<?php

namespace App\Domain\Seating;

use App\Domain\Enums\HoldReason;
use App\Domain\Enums\TableStatus;

/**
 * 帶位建議演算法（設計文件 §5.8）。純函式：輸入桌況與等候清單，輸出建議與保護保留。
 *
 * 1. 保護中的組（被跳過達 maxSkips 次）先處理：有全空的適合選項就建議他；
 *    否則把所有對他「相關」的空桌保留給他，別人拿不到。
 * 2. 其餘組依全域順序，找最佳空桌選項（單桌優先、浪費最少）。
 *    排在前面但沒有適合桌子的組，會被後面的組先帶位（之後由 SkipRule 計算是否算被跳過）。
 */
final class SeatingAllocator
{
    public function __construct(
        private readonly OptionFinder $finder,
        private readonly int $maxSkips,
    ) {}

    /**
     * @param  list<WaitingParty>  $waiting
     */
    public function allocate(FloorSnapshot $floor, array $waiting): AllocationResult
    {
        $waiting = WaitingParty::sortByOrder($waiting);

        /** @var array<int, true> $free */
        $free = [];
        foreach ($floor->tables as $table) {
            if ($table->status === TableStatus::Available && $table->holdReason !== HoldReason::Called) {
                $free[$table->id] = true;
            }
        }
        // 以參照捕捉：已分配或已保留的桌子從 $free 移除後，後面的組就看不到
        $isFree = function (TableSnapshot $t) use (&$free): bool {
            return isset($free[$t->id]);
        };

        $suggestions = [];
        $holds = [];

        foreach ($waiting as $party) {
            if (! $this->isProtected($party)) {
                continue;
            }
            $option = $this->finder->bestFreeOption($party->size, $floor, $isFree);
            if ($option !== null) {
                $suggestions[] = new Suggestion($party->id, $option);
                foreach ($option->tableIds as $id) {
                    unset($free[$id]);
                }
            }
            // 不論是否已建議，他其他相關的空桌都保留到他被叫號為止：
            // 否則帶位人員若先叫後面的組，那組會拿走他的相關桌，叫號會被跳號關卡拒絕
            foreach ($this->finder->relevantTableIds($party->size, $floor) as $id) {
                if (isset($free[$id])) {
                    $holds[$id] = $party->id;
                    unset($free[$id]);
                }
            }
        }

        // 同一輪的多個建議依序叫號時，跳號會累加；以虛擬次數避免一輪內把某人推過上限
        $skips = [];
        foreach ($waiting as $party) {
            $skips[$party->id] = $party->skipCount;
        }
        $suggested = array_fill_keys(array_map(fn (Suggestion $s): int => $s->partyId, $suggestions), true);

        foreach ($waiting as $party) {
            if ($this->isProtected($party)) {
                continue;
            }
            $option = $this->finder->bestFreeOption($party->size, $floor, $isFree);
            if ($option === null) {
                continue;
            }
            $skipped = $this->skippedAhead($party, $option, $waiting, $suggested, $floor);
            if (array_filter($skipped, fn (int $id): bool => $this->maxSkips < $skips[$id] + 1) !== []) {
                continue;
            }
            foreach ($skipped as $id) {
                $skips[$id]++;
            }
            $suggestions[] = new Suggestion($party->id, $option);
            $suggested[$party->id] = true;
            foreach ($option->tableIds as $id) {
                unset($free[$id]);
            }
        }

        return new AllocationResult($suggestions, $holds);
    }

    /**
     * 依序叫號時，這個建議會跳過哪些排在前面、本輪沒有拿到建議的組（規則同 SkipRule）。
     *
     * @param  list<WaitingParty>  $waiting
     * @param  array<int, true>  $suggested
     * @return list<int>
     */
    private function skippedAhead(WaitingParty $party, TableOption $option, array $waiting, array $suggested, FloorSnapshot $floor): array
    {
        $ids = [];
        foreach ($waiting as $ahead) {
            if (! $ahead->isAheadOf($party) || isset($suggested[$ahead->id])) {
                continue;
            }
            if (array_intersect($option->tableIds, $this->finder->relevantTableIds($ahead->size, $floor)) !== []) {
                $ids[] = $ahead->id;
            }
        }

        return $ids;
    }

    private function isProtected(WaitingParty $party): bool
    {
        return $party->skipCount >= $this->maxSkips;
    }
}
