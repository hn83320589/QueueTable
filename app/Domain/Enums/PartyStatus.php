<?php

namespace App\Domain\Enums;

enum PartyStatus: string
{
    case Waiting = 'waiting';
    case Called = 'called';
    case Seated = 'seated';
    case NoShow = 'no_show';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Waiting => '等候中',
            self::Called => '已叫號',
            self::Seated => '已入座',
            self::NoShow => '過號',
            self::Cancelled => '已取消',
        };
    }

    /** 等候中或已叫號，都算「同一支手機只能有一筆」的有效號碼。 */
    public function isActive(): bool
    {
        return $this === self::Waiting || $this === self::Called;
    }
}
