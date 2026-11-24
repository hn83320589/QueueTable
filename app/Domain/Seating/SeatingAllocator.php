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

                continue;
            }
            foreach ($this->finder->relevantTableIds($party->size, $floor) as $id) {
                if (isset($free[$id])) {
                    $holds[$id] = $party->id;
                    unset($free[$id]);
                }
            }
        }

        foreach ($waiting as $party) {
            if ($this->isProtected($party)) {
                continue;
            }
            $option = $this->finder->bestFreeOption($party->size, $floor, $isFree);
            if ($option === null) {
                continue;
            }
            $suggestions[] = new Suggestion($party->id, $option);
            foreach ($option->tableIds as $id) {
                unset($free[$id]);
            }
        }

        return new AllocationResult($suggestions, $holds);
    }

    private function isProtected(WaitingParty $party): bool
    {
        return $party->skipCount >= $this->maxSkips;
    }
}
