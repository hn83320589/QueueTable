<?php

namespace App\Domain\Enums;

enum TableStatus: string
{
    case Available = 'available';
    case Occupied = 'occupied';
    case Cleaning = 'cleaning';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Available => '可用',
            self::Occupied => '用餐中',
            self::Cleaning => '清桌中',
            self::Disabled => '停用',
        };
    }
}
