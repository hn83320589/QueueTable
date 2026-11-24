<?php

namespace App\Simulation;

use Carbon\CarbonImmutable;

/**
 * 營業日內的時刻：中午以前的時刻代表隔天凌晨（例如營業日 11/24 的 01:30 是 11/25 01:30）。
 */
final class SimClock
{
    public static function at(string $businessDate, string $time): CarbonImmutable
    {
        $at = CarbonImmutable::parse("{$businessDate} {$time}");

        return $at->hour < 12 ? $at->addDay() : $at;
    }
}
