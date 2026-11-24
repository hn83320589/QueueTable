<?php

namespace App\Domain\Seating;

use App\Domain\Enums\TableStatus;

/**
 * 找出一組客人「適合」的桌子選項。
 *
 * 適合 = 坐得下，且浪費座位不超過 maxWaste。
 * 排序 = 單桌一律優先於併桌 → 浪費少 → 桌號小（確保結果固定）。
 * 只有沒有任何空著的單桌選項時，才使用併桌；併桌只考慮兩張相鄰桌。
 */
final class OptionFinder
{
    public function __construct(private readonly int $maxWaste) {}

    /**
     * 所有非停用桌構成的適合選項（不看是否空著）。
     *
     * @return list<TableOption>
     */
    public function allOptions(int $size, FloorSnapshot $floor): array
    {
        $options = [];

        foreach ($floor->tables as $table) {
            if ($table->status !== TableStatus::Disabled && $this->fits($size, $table->capacity)) {
                $options[] = new TableOption([$table->id], $table->capacity, $table->capacity - $size, false, $table->code);
            }
        }

        foreach ($floor->pairs() as [$a, $b]) {
            $ta = $floor->table($a);
            $tb = $floor->table($b);
            if ($ta->status === TableStatus::Disabled || $tb->status === TableStatus::Disabled) {
                continue;
            }
            $capacity = $ta->capacity + $tb->capacity;
            if (! $this->fits($size, $capacity)) {
                continue;
            }
            [$first, $second] = strcmp($ta->code, $tb->code) <= 0 ? [$ta, $tb] : [$tb, $ta];
            $options[] = new TableOption([$first->id, $second->id], $capacity, $capacity - $size, true, $first->code.'+'.$second->code);
        }

        usort($options, fn (TableOption $x, TableOption $y): int => [$x->combined, $x->waste, $x->sortCode] <=> [$y->combined, $y->waste, $y->sortCode]);

        return $options;
    }

    /**
     * 最佳的「全部桌子都空著」的選項；先找單桌，沒有才找併桌。
     *
     * @param  callable(TableSnapshot): bool  $isFree
     */
    public function bestFreeOption(int $size, FloorSnapshot $floor, callable $isFree): ?TableOption
    {
        foreach ($this->allOptions($size, $floor) as $option) {
            if ($this->allFree($option, $floor, $isFree)) {
                return $option;
            }
        }

        return null;
    }

    /**
     * 對這個人數「相關」的桌子：出現在任何適合選項中的桌子（依 id 排序）。
     *
     * @return list<int>
     */
    public function relevantTableIds(int $size, FloorSnapshot $floor): array
    {
        $ids = [];
        foreach ($this->allOptions($size, $floor) as $option) {
            foreach ($option->tableIds as $id) {
                $ids[$id] = true;
            }
        }
        $ids = array_keys($ids);
        sort($ids);

        return $ids;
    }

    /**
     * 手動選擇的桌子是否合法：構成一個適合選項、全部空著；
     * 若是併桌，則不能有任何空著的單桌選項。
     *
     * @param  list<int>  $tableIds
     * @param  callable(TableSnapshot): bool  $isFree
     */
    public function isValidChoice(int $size, array $tableIds, FloorSnapshot $floor, callable $isFree): bool
    {
        $chosen = $tableIds;
        sort($chosen);

        foreach ($this->allOptions($size, $floor) as $option) {
            $ids = $option->tableIds;
            sort($ids);
            if ($ids !== $chosen || ! $this->allFree($option, $floor, $isFree)) {
                continue;
            }
            if (! $option->combined) {
                return true;
            }
            $best = $this->bestFreeOption($size, $floor, $isFree);

            return $best !== null && $best->combined;
        }

        return false;
    }

    /**
     * @param  callable(TableSnapshot): bool  $isFree
     */
    private function allFree(TableOption $option, FloorSnapshot $floor, callable $isFree): bool
    {
        foreach ($option->tableIds as $id) {
            if (! $isFree($floor->table($id))) {
                return false;
            }
        }

        return true;
    }

    private function fits(int $size, int $capacity): bool
    {
        return $capacity >= $size && $capacity - $size <= $this->maxWaste;
    }
}
