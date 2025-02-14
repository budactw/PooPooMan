<?php

namespace App\Enum;

enum PoopCommand: string
{
    case Help = '/指令';
    case Rank = '/排行';
    case Summarize = '/個人統計';
    case GroupSummarize = '/群統計';
    case GoodPoop = '💩';
    case StuckPoop = '💩💩';
    case BadPoop = '💩💩💩';

    public static function showHelp(): string
    {
        return implode("\n", collect(self::cases())->map(function (PoopCommand $command) {
            return $command->value . ' - ' . $command->desc();
        })->toArray()) . "\n 請輸入指令，例如紀錄便便，請輸入「💩」符號，或是輸入「/排行」查看排行榜";
    }

    public function desc()
    {
        return match ($this) {
            self::Help => '指令說明',
            self::Rank => '每月、每週排行',
            self::Summarize => '個人統計',
            self::GroupSummarize => '群統計',
            self::GoodPoop => '順暢棒賽',
            self::StuckPoop => '便秘',
            self::BadPoop => '烙賽',
            default => '未知指令',
        };
    }

    /**
     * 是否允許一對一視窗使用
     *
     * @return bool
     */
    public function allowOneToOne(): bool
    {
        return match ($this) {
            self::Help, self::Summarize, self::GoodPoop, self::BadPoop, self::StuckPoop => true,
            self::Rank, self::GroupSummarize => false,
        };
    }

    /**
     * 是否允許群組視窗使用
     *
     * @return bool
     */
    public function allowGroup(): bool
    {
        return match ($this) {
            self::Help, self::Rank, self::Summarize, self::GroupSummarize, self::GoodPoop, self::BadPoop, self::StuckPoop => true,
        };
    }
}
