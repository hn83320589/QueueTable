<?php

namespace App\Domain;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * 營業日：營業時間 17:00 到隔天 02:00 會跨過午夜，
 * 所以以台灣時間 cutoff（預設 06:00）切日，cutoff 以前算前一個營業日。
 */
final class BusinessDay
{
    public static function for(CarbonInterface $at): string
    {
        return CarbonImmutable::instance($at)
            ->setTimezone(config('app.timezone'))
            ->subHours(self::cutoffHour())
            ->toDateString();
    }

    public static function today(): string
    {
        return self::for(now());
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} [開始, 結束)
     */
    public static function window(string $date): array
    {
        $start = CarbonImmutable::parse($date, config('app.timezone'))
            ->startOfDay()
            ->addHours(self::cutoffHour());

        return [$start, $start->addDay()];
    }

    private static function cutoffHour(): int
    {
        return (int) config('queuetable.business_day_cutoff_hour');
    }
}
