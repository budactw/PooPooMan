<?php

namespace App\Services;

use App\Enum\PoopCommand;
use App\Enum\PoopType;
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

class PoopService
{
    /**
     * 便便紀錄快取時間(小時)
     */
    const POOP_RECORD_TTL = 1;

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

        $command = PoopCommand::tryFrom($message->getText());

        if (!$command) {
            $this->replyMessageWithText($event, '找不到指令，請輸入 /指令 查看可用指令');

            return;
        }

        if (!$command->allowOneToOne() && !$this->isGroupSource($event)) {
            $this->replyMessageWithText($event, '請在群組中使用此功能');

            return;
        }

        switch ($command) {
            case PoopCommand::Help:
                $this->replyMessageWithText($event, PoopCommand::showHelp());
                break;
            case PoopCommand::Rank:
                $this->getRank($event);
                break;
            case PoopCommand::Summarize:
                $this->getSummarize($event);
                break;
            case PoopCommand::GoodPoop:
                $this->recordPoop($event, PoopType::GoodPoop);
                break;
            case PoopCommand::StuckPoop:
                $this->recordPoop($event, PoopType::StuckPoop);
                break;
            case PoopCommand::BadPoop:
                $this->recordPoop($event, PoopType::BadPoop);
                break;
            case PoopCommand::GroupSummarize:
                $this->getGroupStatistics($event);
                break;
            default:
                $this->replyMessageWithText($event, '找不到指令，請輸入 /指令 查看可用指令');
        }
    }

    /**
     * @throws ApiException
     */
    private function recordPoop(MessageEvent $event, PoopType $poopType): void
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

        $this->replyMessageWithText(
            $event,
            "💩 {$poopRecord->user_name} 今日第 {$todayCount} 次便便\n"
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
    private function getGroupStatistics(MessageEvent $event): void
    {
        $groupId = $this->getGroupId($event);

        // 取得統計資料
        $statistics = $this->getStatistics($groupId);

        // 計算次數
        $weeklyCount = $statistics['weekly']->count();
        $monthlyCount = $statistics['monthly']->count();
        $totalCount = $statistics['total'];

        // 組裝訊息
        $message = "💩 群組便便統計 💩\n";
        $message .= "\n📅 本週次數: {$weeklyCount} 次";
        $message .= "\n📆 本月次數: {$monthlyCount} 次";
        $message .= "\n📈 總次數: {$totalCount} 次";

        $this->replyMessageWithText($event, $message);
    }

    /**
     * 取得排行榜
     *
     * @throws ApiException
     */
    private function getRank(MessageEvent $event): void
    {
        $groupId = $this->getGroupId($event);

        // 取得統計資料
        $statistics = $this->getStatistics($groupId);

        // 生成排行榜
        $weeklyRank = $this->generateRank($statistics['weekly']);
        $monthlyRank = $this->generateRank($statistics['monthly']);

        // 組裝訊息
        $message = "💩 群組排行榜 💩\n";

        // 本週排行榜
        if ($weeklyRank->isNotEmpty()) {
            $message .= "\n📅 本週排行榜 📅\n";
            foreach ($weeklyRank as $index => $record) {
                $rank = $index + 1;
                $message .= "{$rank}. {$record['name']}: {$record['count']} 次\n";
            }
        } else {
            $message .= "\n📅 本週還沒有人便便喔，請努力！！\n";
        }

        // 本月排行榜
        if ($monthlyRank->isNotEmpty()) {
            $message .= "\n📆 本月排行榜 📆\n";
            foreach ($monthlyRank as $index => $record) {
                $rank = $index + 1;
                $message .= "{$rank}. {$record['name']}: {$record['count']} 次\n";
            }
        } else {
            $message .= "\n📆 本月還沒有人便便喔，請努力！！\n";
        }

        $this->replyMessageWithText($event, $message);
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
     * 取得群組便便統計資料
     *
     * @param mixed $groupId 群組 ID
     * @return array 統計結果
     */
    private function getStatistics($groupId): array
    {
        // 查詢所有便便紀錄
        $records = PoopRecord::query()
            ->where('group_id', $groupId)
            ->get();

        // 本週次數
        $weeklyRecords = $records->filter(function ($record) {
            return Carbon::parse($record->record_date)->between(
                Carbon::now()->startOfWeek(),
                Carbon::now()->endOfWeek()
            );
        });

        // 本月次數
        $monthlyRecords = $records->filter(function ($record) {
            return Carbon::parse($record->record_date)->month === Carbon::now()->month;
        });

        // 總次數
        $totalCount = $records->count();

        return [
            'weekly'  => $weeklyRecords,
            'monthly' => $monthlyRecords,
            'total'   => $totalCount,
        ];
    }

    /**
     * 生成排行榜
     *
     * @param \Illuminate\Support\Collection $records 篩選後的紀錄
     * @return \Illuminate\Support\Collection 排行榜資料
     */
    private function generateRank($records): \Illuminate\Support\Collection
    {
        return $records->groupBy('user_id')
            ->map(function ($userRecords) {
                return [
                    'name'  => $userRecords->first()->user_name,
                    'count' => $userRecords->count(),
                ];
            })
            ->sortByDesc('count')
            ->values();
    }
}
