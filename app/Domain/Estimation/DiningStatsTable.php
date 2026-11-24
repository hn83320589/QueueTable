<?php

namespace App\Domain\Estimation;

use App\Domain\QueueLetter;

/**
 * 佇列 × 時段的用餐時間統計，樣本不足時依序退回「同佇列全部時段」→「設定檔預設值」。
 */
final readonly class DiningStatsTable
{
    /**
     * @param  array<string, array<int, DurationStats>>  $stats  [佇列][時段]
     * @param  array<string, DurationStats>  $queueFallback  [佇列]
     * @param  array<string, DurationStats>  $defaults  [佇列]
     */
    public function __construct(
        public array $stats,
        public array $queueFallback,
        public array $defaults,
    ) {}

    public function for(QueueLetter $queue, int $slot): DurationStats
    {
        return $this->stats[$queue->value][$slot]
            ?? $this->queueFallback[$queue->value]
            ?? $this->defaults[$queue->value];
    }
}
