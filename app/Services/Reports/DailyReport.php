<?php

namespace App\Services\Reports;

/**
 * 營業日報表。比率為 0–1（3 位小數），分鐘為 1 位小數；沒有資料時為 null。
 */
final readonly class DailyReport
{
    /**
     * @param  array<string, array{avg: float|null, max: int|null}>  $perQueue
     * @param  array<string, int>  $turnover  桌號 => 周轉次數
     * @param  array<int, int>  $skipDistribution  被跳過次數 => 組數
     */
    public function __construct(
        public string $businessDate,
        public int $issued,
        public int $seated,
        public ?float $noShowRate,
        public ?float $cancelRate,
        public array $perQueue,
        public ?float $mae,
        public ?float $intervalHitRate,
        public array $turnover,
        public array $skipDistribution,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
