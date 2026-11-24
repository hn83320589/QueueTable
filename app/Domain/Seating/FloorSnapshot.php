<?php

namespace App\Domain\Seating;

use InvalidArgumentException;

/**
 * 某一時刻整個樓面的桌況與相鄰關係。
 */
final readonly class FloorSnapshot
{
    /**
     * @param  array<int, TableSnapshot>  $tables  以桌子 id 為 key
     * @param  list<array{0: int, 1: int}>  $adjacency  可併桌的相鄰桌 id 組合
     */
    public function __construct(
        public array $tables,
        public array $adjacency,
    ) {}

    public function table(int $id): TableSnapshot
    {
        return $this->tables[$id] ?? throw new InvalidArgumentException("桌子 {$id} 不存在");
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    public function pairs(): array
    {
        return $this->adjacency;
    }

    public function areAdjacent(int $a, int $b): bool
    {
        foreach ($this->adjacency as [$x, $y]) {
            if (($x === $a && $y === $b) || ($x === $b && $y === $a)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 回傳替換部分桌子後的新快照。
     *
     * @param  array<int, TableSnapshot>  $replacements
     */
    public function with(array $replacements): self
    {
        return new self(array_replace($this->tables, $replacements), $this->adjacency);
    }
}
