<?php

namespace App\Domain\Seating;

use App\Domain\QueueLetter;

/**
 * 一組等候中的客人（純資料）。全域順序 = (positionKey, id) 升冪。
 */
final readonly class WaitingParty
{
    public function __construct(
        public int $id,
        public QueueLetter $queue,
        public int $size,
        public float $positionKey,
        public int $skipCount,
    ) {}

    public function isAheadOf(self $other): bool
    {
        return [$this->positionKey, $this->id] < [$other->positionKey, $other->id];
    }

    public function withSkipCount(int $skipCount): self
    {
        return new self($this->id, $this->queue, $this->size, $this->positionKey, $skipCount);
    }

    /**
     * @param  list<self>  $parties
     * @return list<self>
     */
    public static function sortByOrder(array $parties): array
    {
        usort($parties, fn (self $a, self $b): int => [$a->positionKey, $a->id] <=> [$b->positionKey, $b->id]);

        return $parties;
    }
}
