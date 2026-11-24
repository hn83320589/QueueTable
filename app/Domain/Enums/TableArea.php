<?php

namespace App\Domain\Enums;

enum TableArea: string
{
    case Indoor = 'indoor';
    case Outdoor = 'outdoor';
    case Bar = 'bar';

    public function label(): string
    {
        return match ($this) {
            self::Indoor => '室內',
            self::Outdoor => '戶外',
            self::Bar => '吧台',
        };
    }
}
