<?php

namespace App\Simulation;

use App\Domain\Enums\Channel;
use Carbon\CarbonImmutable;

/**
 * 模擬中的一組客人：何時到、幾位，以及他之後會怎麼做（全部事先由亂數決定，結果可重現）。
 */
final readonly class SimArrival
{
    public function __construct(
        public CarbonImmutable $at,
        public int $size,
        public string $name,
        public string $phone,
        public Channel $channel,
        public int $patienceMinutes,
        public int $checkInDelaySeconds,
        public bool $willNoShow,
        public bool $willRequeue,
        public int $requeueDelayMinutes,
        public ?CarbonImmutable $randomCancelAt,
    ) {}
}
