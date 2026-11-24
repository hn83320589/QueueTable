<?php

namespace App\Domain\Estimation;

use Carbon\CarbonInterface;

/**
 * 用餐時段：17–18 點 → 1、19–20 點 → 2、21–22 點 → 3、其餘 → 4。
 */
final class TimeSlot
{
    public static function of(CarbonInterface $at): int
    {
        return match (true) {
            $at->hour >= 17 && $at->hour <= 18 => 1,
            $at->hour >= 19 && $at->hour <= 20 => 2,
            $at->hour >= 21 && $at->hour <= 22 => 3,
            default => 4,
        };
    }
}
