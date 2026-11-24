<?php

namespace App\Domain\Estimation;

use App\Domain\QueueLetter;
use Carbon\CarbonInterface;

final class DiningStatsBuilder
{
    /**
     * @param  list<array{queue: QueueLetter, seated_at: CarbonInterface, minutes: int}>  $samples
     * @param  array<string, int>  $defaultMinutes  [佇列] => P50
     */
    public static function build(array $samples, int $minSamples, array $defaultMinutes, int $spread): DiningStatsTable
    {
        $bySlot = [];
        $byQueue = [];
        foreach ($samples as $sample) {
            $q = $sample['queue']->value;
            $bySlot[$q][TimeSlot::of($sample['seated_at'])][] = $sample['minutes'];
            $byQueue[$q][] = $sample['minutes'];
        }

        $stats = [];
        foreach ($bySlot as $q => $slots) {
            foreach ($slots as $slot => $minutes) {
                if (count($minutes) >= $minSamples) {
                    $stats[$q][$slot] = self::percentiles($minutes);
                }
            }
        }

        $fallback = [];
        foreach ($byQueue as $q => $minutes) {
            if (count($minutes) >= $minSamples) {
                $fallback[$q] = self::percentiles($minutes);
            }
        }

        $defaults = [];
        foreach ($defaultMinutes as $q => $p50) {
            $defaults[$q] = new DurationStats($p50 - $spread, $p50, $p50 + $spread);
        }

        return new DiningStatsTable($stats, $fallback, $defaults);
    }

    /**
     * Nearest-rank 百分位：rank = ceil(p/100 × n)，取排序後第 rank 筆（方便手算）。
     *
     * @param  list<int>  $values
     */
    private static function percentiles(array $values): DurationStats
    {
        sort($values);
        $n = count($values);
        $at = fn (int $p): int => $values[max(1, (int) ceil($p / 100 * $n)) - 1];

        return new DurationStats($at(25), $at(50), $at(75));
    }
}
