<?php

namespace App\Domain;

use InvalidArgumentException;

/**
 * 依人數分的四個佇列：A 1–2 人、B 3–4 人、C 5–6 人、D 7 人以上。
 */
enum QueueLetter: string
{
    case A = 'A';
    case B = 'B';
    case C = 'C';
    case D = 'D';

    public static function fromPartySize(int $size): self
    {
        $max = (int) config('queuetable.max_party_size');

        if ($size < 1 || $size > $max) {
            throw new InvalidArgumentException("人數必須介於 1 到 {$max} 人");
        }

        return match (true) {
            $size <= 2 => self::A,
            $size <= 4 => self::B,
            $size <= 6 => self::C,
            default => self::D,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::A => 'A（1–2 人）',
            self::B => 'B（3–4 人）',
            self::C => 'C（5–6 人）',
            self::D => 'D（7 人以上）',
        };
    }
}
