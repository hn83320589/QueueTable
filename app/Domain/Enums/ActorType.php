<?php

namespace App\Domain\Enums;

enum ActorType: string
{
    case Staff = 'staff';
    case Customer = 'customer';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Staff => '員工',
            self::Customer => '顧客',
            self::System => '系統',
        };
    }
}
