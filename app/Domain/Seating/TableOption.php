<?php

namespace App\Domain\Seating;

/**
 * 一個可帶位的選項：一張桌子，或兩張相鄰桌子的併桌。
 */
final readonly class TableOption
{
    /**
     * @param  list<int>  $tableIds  依桌號排序
     */
    public function __construct(
        public array $tableIds,
        public int $capacity,
        public int $waste,
        public bool $combined,
        public string $sortCode,
    ) {}
}
