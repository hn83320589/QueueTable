<?php

namespace App\Domain\Enums;

enum HoldReason: string
{
    /** 叫號後 3 分鐘內保留給該組 */
    case Called = 'called';

    /** 被跳過 3 次的組，相關空桌保留給他 */
    case Protected = 'protected';

    public function label(): string
    {
        return match ($this) {
            self::Called => '叫號保留',
            self::Protected => '保護保留',
        };
    }
}
