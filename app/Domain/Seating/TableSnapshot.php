<?php

namespace App\Domain\Seating;

use App\Domain\Enums\HoldReason;
use App\Domain\Enums\TableStatus;

/**
 * 某一時刻一張桌子的狀態（純資料，不碰資料庫）。
 */
final readonly class TableSnapshot
{
    public function __construct(
        public int $id,
        public string $code,
        public int $capacity,
        public TableStatus $status,
        public ?int $heldForPartyId = null,
        public ?HoldReason $holdReason = null,
    ) {}

    public function withStatus(TableStatus $status): self
    {
        return new self($this->id, $this->code, $this->capacity, $status, $this->heldForPartyId, $this->holdReason);
    }
}
