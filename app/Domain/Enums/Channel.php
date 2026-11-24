<?php

namespace App\Domain\Enums;

enum Channel: string
{
    case Kiosk = 'kiosk';
    case Remote = 'remote';

    public function label(): string
    {
        return match ($this) {
            self::Kiosk => '門口平板',
            self::Remote => '手機遠端',
        };
    }
}
