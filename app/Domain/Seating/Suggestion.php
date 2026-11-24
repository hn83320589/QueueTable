<?php

namespace App\Domain\Seating;

/**
 * 帶位建議：叫哪一組、帶到哪個選項。
 */
final readonly class Suggestion
{
    public function __construct(
        public int $partyId,
        public TableOption $option,
    ) {}
}
