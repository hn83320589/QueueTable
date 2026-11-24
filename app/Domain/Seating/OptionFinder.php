<?php

namespace App\Domain\Seating;

use App\Domain\Enums\TableStatus;

/**
 * 找出一組客人「適合」的桌子選項。
 *
 * 適合 = 坐得下，且浪費座位不超過 maxWaste。
 * 排序 = 單桌一律優先於併桌 → 浪費少 → 桌號小（確保結果固定）。
 * 只有沒有任何空著的單桌選項時，才使用併桌；併桌只考慮兩張相鄰桌。
 *
 * 放寬：一組客人等候滿 relaxAfterMinutes 分鐘、且沒有浪費 maxWaste 以內的空選項時，
 * 允許使用浪費到 relaxedMaxWaste 的桌子（浪費少的仍優先）。避免 5 人組只能等兩張 6 人桌。
 */
final class OptionFinder
{
    public function __construct(
        private readonly int $maxWaste,
        private readonly ?int $relaxedMaxWaste = null,
        private readonly ?int $relaxAfterMinutes = null,
    ) {}

    /**
     * 這組客人在時間 $now（Unix 秒）是否已可放寬浪費上限。沒有時間資訊時一律不放寬。
     */
    public function isRelaxed(WaitingParty $party, ?int $now): bool
    {
        return $this->relaxedMaxWaste !== null && $this->relaxAfterMinutes !== null
            && $now !== null && $party->issuedAt !== null
            && $now - $party->issuedAt >= $this->relaxAfterMinutes * 60;
    }

    /**
     * 這組客人目前最佳的全空選項：先找一般上限內的，沒有且已等夠久才找放寬後的。
     *
     * @param  callable(TableSnapshot): bool  $isFree
     */
    public function bestFreeOptionFor(WaitingParty $party, FloorSnapshot $floor, callable $isFree, ?int $now): ?TableOption
    {
        return $this->bestFreeOption($party->size, $floor, $isFree)
            ?? ($this->isRelaxed($party, $now) ? $this->bestFreeOption($party->size, $floor, $isFree, $this->relaxedMaxWaste) : null);
    }

    /**
     * 手動選擇是否合法：一般上限內合法；或已可放寬、沒有一般上限內的空選項、且在放寬上限內合法。
     *
     * @param  list<int>  $tableIds
     * @param  callable(TableSnapshot): bool  $isFree
     */
    public function isValidChoiceFor(WaitingParty $party, array $tableIds, FloorSnapshot $floor, callable $isFree, ?int $now): bool
    {
        if ($this->isValidChoice($party->size, $tableIds, $floor, $isFree)) {
            return true;
        }

        return $this->isRelaxed($party, $now)
            && $this->bestFreeOption($party->size, $floor, $isFree) === null
            && $this->isValidChoice($party->size, $tableIds, $floor, $isFree, $this->relaxedMaxWaste);
    }

    /**
     * 這組客人在時間 $now 可考慮的所有選項（給平板「改選其他桌」用）。
     *
     * @return list<TableOption>
     */
    public function allOptionsFor(WaitingParty $party, FloorSnapshot $floor, ?int $now): array
    {
        return $this->allOptions($party->size, $floor, $this->isRelaxed($party, $now) ? $this->relaxedMaxWaste : null);
    }

    /**
     * 所有非停用桌構成的適合選項（不看是否空著）。
     *
     * @return list<TableOption>
     */
    public function allOptions(int $size, FloorSnapshot $floor, ?int $maxWaste = null): array
    {
        $maxWaste ??= $this->maxWaste;
        $options = [];

        foreach ($floor->tables as $table) {
            if ($table->status !== TableStatus::Disabled && $this->fits($size, $table->capacity, $maxWaste)) {
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
            if (! $this->fits($size, $capacity, $maxWaste)) {
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
    public function bestFreeOption(int $size, FloorSnapshot $floor, callable $isFree, ?int $maxWaste = null): ?TableOption
    {
        foreach ($this->allOptions($size, $floor, $maxWaste) as $option) {
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
    public function isValidChoice(int $size, array $tableIds, FloorSnapshot $floor, callable $isFree, ?int $maxWaste = null): bool
    {
        $chosen = $tableIds;
        sort($chosen);

        foreach ($this->allOptions($size, $floor, $maxWaste) as $option) {
            $ids = $option->tableIds;
            sort($ids);
            if ($ids !== $chosen || ! $this->allFree($option, $floor, $isFree)) {
                continue;
            }
            if (! $option->combined) {
                return true;
            }
            $best = $this->bestFreeOption($size, $floor, $isFree, $maxWaste);

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

    private function fits(int $size, int $capacity, int $maxWaste): bool
    {
        return $capacity >= $size && $capacity - $size <= $maxWaste;
    }
}
