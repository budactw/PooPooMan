<?php

namespace App\Enum;

enum PoopType: int
{
    case GoodPoop = 1;
    case StuckPoop = 2;
    case BadPoop = 3;

    public function toString(): string
    {
        return match ($this) {
            PoopType::GoodPoop => '順暢',
            PoopType::StuckPoop => '便秘',
            PoopType::BadPoop => '烙賽',
        };
    }
}
