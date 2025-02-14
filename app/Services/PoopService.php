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
     * 取得便便紀錄查詢
     */
    private function getPoopQuery(?string $groupId, ?string $userId = null)
    {
        $query = PoopRecord::query();

        if ($groupId) {
            $query->where('group_id', $groupId);
        }

        if ($userId) {
            $query->where('user_id', $userId);
        }

        return $query;
    }

    /**
     * 計算便便次數
     */
    private function calculateCounts($query, ?string $dateRange = null): array
    {
        if ($dateRange === 'today') {
            $query->whereDate('record_date', Carbon::today());
        } elseif ($dateRange === 'week') {
            $query->whereBetween('record_date', [
                Carbon::now()->startOfWeek(),
                Carbon::now()->endOfWeek(),
            ]);
        } elseif ($dateRange === 'month') {
            $query->whereMonth('record_date', Carbon::now()->month);
        }

        $totalCount = $query->count();

        $typeCounts = $query->get()->groupBy('poop_type')->map->count();

        return [
            'total'      => $totalCount,
            'typeCounts' => [
                PoopType::GoodPoop->toString()  => $typeCounts[PoopType::GoodPoop->value] ?? 0,
                PoopType::StuckPoop->toString() => $typeCounts[PoopType::StuckPoop->value] ?? 0,
                PoopType::BadPoop->toString()   => $typeCounts[PoopType::BadPoop->value] ?? 0,
            ],
        ];
    }

    /**
     * 格式化便便統計訊息
     */
    private function formatStatisticsMessage(string $title, array $counts): string
    {
        return "{$title}\n" .
            "總次數: {$counts['total']} 次\n" .
            PoopType::GoodPoop->toString() . ": {$counts['typeCounts'][PoopType::GoodPoop->toString()]} 次\n" .
            PoopType::StuckPoop->toString() . ": {$counts['typeCounts'][PoopType::StuckPoop->toString()]} 次\n" .
            PoopType::BadPoop->toString() . ": {$counts['typeCounts'][PoopType::BadPoop->toString()]} 次\n";
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

            $query = $this->getPoopQuery($groupId, $userId);

            // 計算各種統計數據
            $todayCounts = $this->calculateCounts(clone $query, 'today');
            $weekCounts = $this->calculateCounts(clone $query, 'week');
            $monthCounts = $this->calculateCounts(clone $query, 'month');
            $totalCounts = $this->calculateCounts(clone $query);

            // 計算平均值
            $firstRecord = $query->oldest()->first();
            if ($firstRecord) {
                $daysFromFirst = Carbon::parse($firstRecord->record_date)
                        ->diffInDays(Carbon::now()) + 1;
                $average = round($totalCounts['total'] / $daysFromFirst);
            } else {
                $average = 0;
            }

            // 組裝訊息
            $message = "💩 {$profile->getDisplayName()} 的便便統計 💩\n" .
                $this->formatStatisticsMessage("\n📆今日統計📆\n", $todayCounts) .
                $this->formatStatisticsMessage("\n📆本週統計📆\n", $weekCounts) .
                $this->formatStatisticsMessage("\n📆本月統計📆\n", $monthCounts) .
                "\n總計次數: {$totalCounts['total']} 次\n" .
                "平均每日: {$average} 次";

            $this->replyMessageWithText($event, $message);

        } catch (ApiException $e) {
            Log::error('便便統計失敗: ' . $e->getMessage());
            $this->replyMessageWithText($event, '統計資料取得失敗，請稍後再試');
        }
    }

    /**
     * @throws ApiException
     */
    private function getGroupStatistics(MessageEvent $event): void
    {
        $groupId = $this->getGroupId($event);

        $query = $this->getPoopQuery($groupId);

        // 計算各種統計數據
        $weeklyCounts = $this->calculateCounts(clone $query, 'week');
        $monthlyCounts = $this->calculateCounts(clone $query, 'month');
        $totalCounts = $this->calculateCounts(clone $query);

        // 組裝訊息
        $message = "💩 群組便便統計 💩\n" .
            $this->formatStatisticsMessage("\n📆本週統計📆\n", $weeklyCounts) .
            $this->formatStatisticsMessage("\n📆本月統計📆\n", $monthlyCounts) .
            "\n總計次數: {$totalCounts['total']} 次";

        $this->replyMessageWithText($event, $message);
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
     * 取得排行榜
     *
     * @throws ApiException
     */
    private function getRank(MessageEvent $event): void
    {
        $groupId = $this->getGroupId($event);

        // 取得統計資料
        $statistics = $this->getStatistics($groupId);

        // 組裝訊息
        $message = "💩 群組排行榜 💩\n";

        // 本週總排行榜
        $message .= "\n📅 本週排行榜 📅\n";
        $weeklyRank = $this->generateRankWithType($statistics['weekly']);
        if ($weeklyRank->isNotEmpty()) {
            foreach ($weeklyRank as $index => $record) {
                $rank = $index + 1;
                $message .= "{$rank}. {$record['name']} {$record['totalCount']} 次 ";
                $message .= "(" . PoopType::GoodPoop->toString() . ": {$record['typeCounts'][PoopType::GoodPoop->toString()]}、 ";
                $message .= PoopType::StuckPoop->toString() . ": {$record['typeCounts'][PoopType::StuckPoop->toString()]}、 ";
                $message .= PoopType::BadPoop->toString() . ": {$record['typeCounts'][PoopType::BadPoop->toString()]})\n";
            }
        } else {
            $message .= "暫無紀錄\n";
        }

        // 本月總排行榜
        $message .= "\n📆 本月排行榜 📆\n";
        $monthlyRank = $this->generateRankWithType($statistics['monthly']);
        if ($monthlyRank->isNotEmpty()) {
            foreach ($monthlyRank as $index => $record) {
                $rank = $index + 1;
                $message .= "{$rank}. {$record['name']} {$record['totalCount']} 次 ";
                $message .= "(" . PoopType::GoodPoop->toString() . ": {$record['typeCounts'][PoopType::GoodPoop->toString()]}, ";
                $message .= PoopType::StuckPoop->toString() . ": {$record['typeCounts'][PoopType::StuckPoop->toString()]}, ";
                $message .= PoopType::BadPoop->toString() . ": {$record['typeCounts'][PoopType::BadPoop->toString()]})\n";
            }
        } else {
            $message .= "暫無紀錄\n";
        }

        $this->replyMessageWithText($event, $message);
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

        // 根據 PoopType 分類
        $weeklyByType = $this->groupByPoopType($weeklyRecords);
        $monthlyByType = $this->groupByPoopType($monthlyRecords);

        return [
            'weekly'        => $weeklyRecords,
            'monthly'       => $monthlyRecords,
            'total'         => $totalCount,
            'weeklyByType'  => $weeklyByType,
            'monthlyByType' => $monthlyByType,
        ];
    }

    /**
     * 根據 PoopType 分類統計
     *
     * @param \Illuminate\Support\Collection $records
     * @return array
     */
    private function groupByPoopType($records): array
    {
        $grouped = $records->groupBy('poop_type');

        return [
            PoopType::GoodPoop->toString()  => $grouped[PoopType::GoodPoop->value] ?? collect(),
            PoopType::StuckPoop->toString() => $grouped[PoopType::StuckPoop->value] ?? collect(),
            PoopType::BadPoop->toString()   => $grouped[PoopType::BadPoop->value] ?? collect(),
        ];
    }

    /**
     * 生成排行榜
     *
     * @param \Illuminate\Support\Collection $records 篩選後的紀錄
     * @return \Illuminate\Support\Collection 排行榜資料
     */
    private function generateRankWithType($records): \Illuminate\Support\Collection
    {
        return $records->groupBy('user_id')
            ->map(function ($userRecords) {
                $typeCounts = $userRecords->groupBy('poop_type')->map->count();

                return [
                    'name'       => $userRecords->first()->user_name,
                    'totalCount' => $userRecords->count(),
                    'typeCounts' => [
                        PoopType::GoodPoop->toString()  => $typeCounts[PoopType::GoodPoop->value] ?? 0,
                        PoopType::StuckPoop->toString() => $typeCounts[PoopType::StuckPoop->value] ?? 0,
                        PoopType::BadPoop->toString()   => $typeCounts[PoopType::BadPoop->value] ?? 0,
                    ],
                ];
            })
            ->sortByDesc('totalCount')
            ->values();
    }
}
