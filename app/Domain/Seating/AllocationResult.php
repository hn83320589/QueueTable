<?php

namespace App\Domain\Seating;

final readonly class AllocationResult
{
    /**
     * @param  list<Suggestion>  $suggestions
     * @param  array<int, int>  $protectedHolds  tableId => partyId（保護保留）
     */
    public function __construct(
        public array $suggestions,
        public array $protectedHolds,
    ) {}
}
