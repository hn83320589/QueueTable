<?php

namespace App\Domain\Estimation;

use App\Domain\QueueLetter;
use App\Domain\Seating\FloorSnapshot;
use App\Domain\Seating\WaitingParty;
use Carbon\CarbonImmutable;

final readonly class EstimatorInput
{
    /**
     * @param  list<ActiveSeating>  $seatings
     * @param  array<int, CarbonImmutable>  $cleaningSince  tableId => 開始清桌時間
     * @param  list<array{queue: QueueLetter, tableIds: list<int>}>  $called  已叫號、尚未入座的組
     * @param  list<WaitingParty>  $waiting
     */
    public function __construct(
        public CarbonImmutable $now,
        public FloorSnapshot $floor,
        public array $seatings,
        public array $cleaningSince,
        public array $called,
        public array $waiting,
    ) {}
}
