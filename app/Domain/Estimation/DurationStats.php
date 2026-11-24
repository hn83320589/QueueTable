<?php

namespace App\Domain\Estimation;

/**
 * 用餐時間分位數（分鐘）。
 */
final readonly class DurationStats
{
    public function __construct(
        public int $p25,
        public int $p50,
        public int $p75,
    ) {}
}
