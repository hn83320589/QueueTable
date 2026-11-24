<?php

namespace App\Domain\Estimation;

use App\Domain\QueueLetter;
use Carbon\CarbonImmutable;

/**
 * 一筆進行中的入座（預估時用來推算桌子何時空出）。
 */
final readonly class ActiveSeating
{
    /**
     * @param  list<int>  $tableIds
     */
    public function __construct(
        public array $tableIds,
        public QueueLetter $queue,
        public CarbonImmutable $seatedAt,
    ) {}
}
