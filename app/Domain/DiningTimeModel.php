<?php

namespace App\Domain;

use Random\Randomizer;

/**
 * 用餐時間的機率模型（lognormal），歷史資料 seeder 與模擬器共用，
 * 讓模擬中的「實際用餐時間」和預估所依據的歷史分布一致。
 */
final class DiningTimeModel
{
    /** 各佇列用餐時間中位數（分鐘） */
    public const MEDIAN = ['A' => 50, 'B' => 65, 'C' => 80, 'D' => 95];

    /** 各時段倍率：1 = 17–18 點、2 = 19–20 點、3 = 21–22 點、4 = 其他 */
    public const SLOT_FACTOR = [1 => 0.95, 2 => 1.05, 3 => 1.0, 4 => 0.9];

    public const SIGMA = 0.25;

    public const MIN_MINUTES = 20;

    public function __construct(private readonly Randomizer $rng) {}

    public function sample(QueueLetter $queue, int $slot): int
    {
        // Box–Muller 轉換產生標準常態分布
        $u1 = 1.0 - $this->rng->nextFloat();
        $u2 = $this->rng->nextFloat();
        $z = sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);

        $minutes = self::MEDIAN[$queue->value] * self::SLOT_FACTOR[$slot] * exp(self::SIGMA * $z);

        return max(self::MIN_MINUTES, (int) round($minutes));
    }
}
