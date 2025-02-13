<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use LINE\Clients\MessagingApi\Api\MessagingApiApi;
use LINE\Clients\MessagingApi\ApiException;
use LINE\Clients\MessagingApi\Configuration;
use App\Models\PoopRecord;
use Carbon\Carbon;
use LINE\Clients\MessagingApi\Model\ReplyMessageRequest;
use LINE\Clients\MessagingApi\Model\TextMessage;
use LINE\Constants\HTTPHeader;
use LINE\Parser\EventRequestParser;
use LINE\Parser\Exception\InvalidEventRequestException;
use LINE\Parser\Exception\InvalidSignatureException;
use LINE\Webhook\Model\MessageEvent;
use LINE\Webhook\Model\TextMessageContent;

class LineBotService
{
    /**
     * 便便紀錄快取時間(小時)
     */
    const POOP_RECORD_TTL = 1;

    /**
     * 指令對應表
     */
    private const COMMANDS = [
        '本日排行' => 'getDailyRanking',
        '本月排行' => 'getMonthlyRanking',
        '本週排行' => 'getWeeklyRanking',
        '便便統計' => 'getSummarize',
        '屎王是誰'     => 'getPoopKing',
        '💩'        => 'recordPoop',
        '💩 '       => 'recordPoop',
        '💩💩'       => 'recordPoop',
        '💩💩💩'      => 'recordPoop',
        'poop'     => 'recordPoop',
        '便便'     => 'recordPoop',
    ];

    private const DEFAULT_MESSAGE = '請輸入 💩 來記錄便便';

    private const GROUP_ONLY_MESSAGE = '請在群組中使用此功能';

    private MessagingApiApi $bot;

    public function __construct()
    {
        $client = new Client();
        $config = new Configuration();
        $config->setAccessToken(config('line-bot.channel_access_token'));
        $this->bot = new MessagingApiApi(
            client: $client,
            config: $config,
        );
    }

    public function handleMessage(Request $request)
    {
        try {
            $signature = $request->header(HTTPHeader::LINE_SIGNATURE);
            $parsedEvents = EventRequestParser::parseEventRequest(
                $request->getContent(),
                config('line-bot.channel_secret'),
                $signature
            );
        } catch (InvalidSignatureException $e) {
            abort(400, 'Invalid signature');
        } catch (InvalidEventRequestException $e) {
            abort(400, 'Invalid event request');
        }

        foreach ($parsedEvents->getEvents() as $event) {
            if (!($event instanceof MessageEvent)) {
                Log::info('Non message event has come');
                continue;
            }

            $message = $event->getMessage();
            if (!($message instanceof TextMessageContent)) {
                Log::info('Non text message has come');
                continue;
            }

            try {
                $this->parseMessage($event);
            } catch (\Exception $e) {
                Log::error('Failed to reply message: ' . $e->getMessage());
            }
        }
    }

    /**
     * @throws ApiException
     */
    private function parseMessage(MessageEvent $event): void
    {
        $message = $event->getMessage();
        if (!($message instanceof TextMessageContent)) {
            return;
        }

        $command = $message->getText();
        $method = self::COMMANDS[$command] ?? null;

        if (!$method) {
            return;
        }

        // 檢查群組限定指令
        $groupOnlyCommands = ['本日排行', '本月排行', '本週排行', '屎王'];
        if (in_array($command, $groupOnlyCommands) && !$this->isGroupSource($event)) {
            $this->replyMessageWithText($event, self::GROUP_ONLY_MESSAGE);

            return;
        }

        $this->$method($event);
    }

    /**
     * @throws ApiException
     */
    private function recordPoop(MessageEvent $event): void
    {
        $profile = $this->getProfile($event);

        if (!$this->availableToRecordPoop($event)) {
            $this->replyMessageWithText(
                $event,
                message: $profile->getDisplayName() . ' 每人' . self::POOP_RECORD_TTL . '小時內只能紀錄一次便便'
            );

            return;
        }


        // 記錄大便
        $poopRecord = PoopRecord::create([
            'group_id'    => $this->getGroupId($event),
            'user_id'     => $profile->getUserId(),
            'user_name'   => $profile->getDisplayName(),
            'record_date' => Carbon::now(),
        ]);

        // 設定快取
        $cacheKey = $this->getCacheKey($profile->getUserId(), $poopRecord->group_id);
        Cache::put($cacheKey, Carbon::now()->addHours(self::POOP_RECORD_TTL));

        // 計算今日次數
        $todayCount = PoopRecord::where('group_id', $poopRecord->group_id)
            ->where('user_id', $poopRecord->user_id)
            ->whereDate('record_date', Carbon::today())
            ->count();

        // 計算總次數
        $totalCount = PoopRecord::where('group_id', $poopRecord->group_id)
            ->where('user_id', $poopRecord->user_id)
            ->count();

        $this->replyMessageWithText(
            $event,
            "💩 {$poopRecord->user_name} 今日第 {$todayCount} 次便便\n累計: {$totalCount} 次"
        );
    }

    /**
     * @throws ApiException
     */
    private function replyMessageWithText(MessageEvent $event, string $message): void
    {
        $this->bot->replyMessage(new ReplyMessageRequest([
            'quote'      => true,
            'replyToken' => $event->getReplyToken(),
            'messages'   => [
                (new TextMessage(['text' => $message]))->setType('text'),
            ],
        ]));
    }

    /**
     * @throws ApiException
     */
    private function getDailyRanking(MessageEvent $event): void
    {
        $groupId = $this->getGroupId($event);
        $records = PoopRecord::query()
            ->where('group_id', $groupId)
            ->whereDate('record_date', Carbon::today())
            ->get()
            ->groupBy('user_id')
            ->map(function ($userRecords) {
                return [
                    'name'  => $userRecords->first()->user_name,
                    'count' => $userRecords->count(),
                ];
            })
            ->sortByDesc('count')
            ->values();

        if ($records->isEmpty()) {
            $this->replyMessageWithText($event, '今日還沒有人便便喔，請努力！！');

            return;
        }

        $message = "💩 今日便便排行榜 💩\n";
        foreach ($records as $index => $record) {
            $rank = $index + 1;
            $message .= "{$rank}. {$record['name']}: {$record['count']} 次\n";
        }

        $this->replyMessageWithText($event, $message);
    }

    /**
     * @throws ApiException
     */
    private function getMonthlyRanking(MessageEvent $event): void
    {
        $groupId = $this->getGroupId($event);
        $records = PoopRecord::query()
            ->where('group_id', $groupId)
            ->whereMonth('record_date', Carbon::now()->month)
            ->get()
            ->groupBy('user_id')
            ->map(function ($userRecords) {
                return [
                    'name'  => $userRecords->first()->user_name,
                    'count' => $userRecords->count(),
                ];
            })
            ->sortByDesc('count')
            ->values();

        if ($records->isEmpty()) {
            $this->replyMessageWithText($event, '本月還沒有人便便喔，請努力！！');

            return;
        }

        $message = "💩 本月便便排行榜 💩\n";
        foreach ($records as $index => $record) {
            $rank = $index + 1;
            $message .= "{$rank}. {$record['name']}: {$record['count']} 次\n";
        }

        $this->replyMessageWithText($event, $message);
    }

    /**
     * @throws ApiException
     */
    private function getWeeklyRanking(MessageEvent $event): void
    {
        $groupId = $this->getGroupId($event);
        $records = PoopRecord::query()
            ->where('group_id', $groupId)
            ->whereBetween('record_date', [
                Carbon::now()->startOfWeek(),
                Carbon::now()->endOfWeek(),
            ])
            ->get()
            ->groupBy('user_id')
            ->map(function ($userRecords) {
                return [
                    'group_id' => $userRecords->first()->group_id,
                    'user_id' => $userRecords->first()->user_id,
                    'name'    => $userRecords->first()->user_name,
                    'count'   => $userRecords->count(),
                ];
            })
            ->sortByDesc('count')
            ->values();

        if ($records->isEmpty()) {
            $this->replyMessageWithText($event, '本週還沒有人便便喔，請努力！！');

            return;
        }

        $message = "💩 本週便便排行榜 💩\n";
        foreach ($records as $index => $record) {
            $rank = $index + 1;
            $message .= "{$rank}. {$record['name']}: {$record['count']} 次\n";
        }


        $this->replyMessageWithText($event, $message);

        $king = $records->sortByDesc('count')->first();
    }

    /**
     * @throws ApiException
     */
    private function getSummarize(MessageEvent $event): void
    {
        try {
            $userId = $event->getSource()->getUserId();
            $profile = $this->getProfile($event);
            $groupId = $this->getGroupId($event);

            $query = PoopRecord::query()
                ->where('user_id', $userId);

            if ($this->isGroupSource($event)) {
                $query->where('group_id', $groupId);
            }

            // 計算各種統計數據
            $todayCount = (clone $query)
                ->whereDate('record_date', Carbon::today())
                ->count();

            $weekCount = (clone $query)
                ->whereBetween('record_date', [
                    Carbon::now()->startOfWeek(),
                    Carbon::now()->endOfWeek(),
                ])->count();

            $monthCount = (clone $query)
                ->whereMonth('record_date', Carbon::now()->month)
                ->count();

            $totalCount = $query->count();

            // 計算平均值
            $firstRecord = $query->oldest()->first();
            if ($firstRecord) {
                $daysFromFirst = Carbon::parse($firstRecord->record_date)
                        ->diffInDays(Carbon::now()) + 1;
                $average = round($totalCount / $daysFromFirst);
            } else {
                $average = 0;
            }

            $message = "💩 {$profile->getDisplayName()} 的便便統計 💩\n" .
                "今日次數: {$todayCount} 次\n" .
                "本週次數: {$weekCount} 次\n" .
                "本月次數: {$monthCount} 次\n" .
                "總計次數: {$totalCount} 次\n" .
                "平均每日: {$average} 次";

            $this->replyMessageWithText($event, $message);

        } catch (ApiException $e) {
            Log::error('便便統計失敗: ' . $e->getMessage());
            $this->replyMessageWithText($event, '統計資料取得失敗，請稍後再試');
        }
    }

    private function availableToRecordPoop(MessageEvent $event): bool
    {
        $userId = $event->getSource()->getUserId();
        $groupId = $this->getGroupId($event);
        $cacheKey = $this->getCacheKey($userId, $groupId);

        return !Cache::has($cacheKey);
    }

    private function getCacheKey(string $userId, ?string $groupId): string
    {
        return "poop_record_{$userId}_" . ($groupId ?? 'none');
    }

    private function getGroupId(MessageEvent $messageEvent): ?string
    {
        if ($this->isGroupSource($messageEvent)) {
            return $messageEvent->getSource()->getGroupId();
        }

        return null;
    }

    private function isGroupSource(MessageEvent $messageEvent): bool
    {
        return $messageEvent->getSource()->getType() === 'group';
    }

    private function isUserSource(MessageEvent $messageEvent): bool
    {
        return $messageEvent->getSource()->getType() === 'user';
    }

    private function getProfile(MessageEvent $event)
    {
        if ($this->isUserSource($event)) {
            return $this->bot->getProfile($event->getSource()->getUserId());
        }

        if ($this->isGroupSource($event)) {
            return $this->bot->getGroupMemberProfile($this->getGroupId($event), $event->getSource()->getUserId());
        }
    }

    /**
     * @throws ApiException
     */
    private function getPoopKing(MessageEvent $event): void
    {
        $king = PoopRecord::query()
            ->where('group_id', $this->getGroupId($event))
            ->whereBetween('record_date', [
                Carbon::now()->startOfWeek(),
                Carbon::now()->endOfWeek(),
            ])
            ->get()
            ->groupBy('user_id')
            ->map(function ($userRecords) {
                return [
                    'group_id' => $userRecords->first()->group_id,
                    'user_id' => $userRecords->first()->user_id,
                    'name'    => $userRecords->first()->user_name,
                    'count'   => $userRecords->count(),
                ];
            })
            ->sortByDesc('count')
            ->first();

        $profile = $this->bot->getGroupMemberProfile($king['group_id'], $king['user_id']);

        $this->replyMessageWithImage($event, $profile->getPictureUrl());
    }

    /**
     * @throws ApiException
     */
    private function replyMessageWithImage(MessageEvent $event, ?string $imageUrl): void
    {
        if (!$imageUrl) {
            $this->replyMessageWithText($event, '找不到屎王的照片');

            return;
        }

        $this->bot->replyMessage(new ReplyMessageRequest([
            'replyToken' => $event->getReplyToken(),
            'messages'   => [
                [
                    'type'               => 'image',
                    'originalContentUrl' => $imageUrl,
                    'previewImageUrl'    => $imageUrl,
                ],
            ],
        ]));
    }
}
