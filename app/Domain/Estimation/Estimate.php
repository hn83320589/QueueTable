<?php

namespace App\Domain\Estimation;

/**
 * 預估等候區間（從現在起的分鐘）。low/high 皆為 null 代表目前桌位配置永遠無法入座。
 */
final readonly class Estimate
{
    public function __construct(
        public ?int $low,
        public ?int $high,
    ) {}

    public function isUnseatable(): bool
    {
        return $this->low === null;
    }
}
