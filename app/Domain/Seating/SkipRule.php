<?php

namespace App\Domain\Seating;

use App\Domain\Enums\TableStatus;

/**
 * 跳號計數規則（設計文件 §5.9）。
 *
 * 題意：「輪到的客人沒有適合的桌子時，可以先讓後面的人入座，但最多被跳過 3 次，之後保留下一張適合的桌子給他。」
 *
 * 當 $called 被叫號並分配到 $assignedTableIds 時，排在他前面、仍在等候的 P：
 * 1. 扣掉這些桌子後 P 當下仍有全空的適合選項 → 不算（P 自己也拿得到桌子，沒有被插隊）。
 * 2. P 尚未進入保護（被跳過不到 maxSkips 次）→ 算一次（P 沒桌可坐、後面的人先進去）。
 * 3. P 已進入保護 → 對 P 有用的桌子都保留給他，只有這次拿走的是他的相關桌才算
 *    （寫入時會被拒絕；後面的人用他用不到的桌子不影響他，不再計數）。
 */
final class SkipRule
{
    public function __construct(
        private readonly OptionFinder $finder,
        private readonly int $maxSkips,
    ) {}

    /**
     * @param  list<int>  $assignedTableIds
     * @param  list<WaitingParty>  $waiting
     * @param  int|null  $now  叫號時間（Unix 秒），判斷前面的組是否已可放寬浪費上限
     * @return list<int> 被跳過的 party id（依全域順序）
     */
    public function skippedPartyIds(array $assignedTableIds, WaitingParty $called, array $waiting, FloorSnapshot $floor, ?int $now = null): array
    {
        $skipped = [];

        foreach (WaitingParty::sortByOrder($waiting) as $party) {
            if ($party->id === $called->id || ! $party->isAheadOf($called)) {
                continue;
            }
            if ($this->counts($party, $party->skipCount, $assignedTableIds, $this->freeExcept($party->id, $assignedTableIds), $floor, $now)) {
                $skipped[] = $party->id;
            }
        }

        return $skipped;
    }

    /**
     * 單一組是否被這次分配跳過（SkipAuditor 以紀錄中的資料重算時也用這個方法）。
     *
     * @param  list<int>  $assignedTableIds
     * @param  callable(TableSnapshot): bool  $isFree  扣掉這次分配後，對這組而言空著的桌子
     */
    public function counts(WaitingParty $party, int $skipCount, array $assignedTableIds, callable $isFree, FloorSnapshot $floor, ?int $now = null): bool
    {
        if ($this->finder->bestFreeOptionFor($party, $floor, $isFree, $now) !== null) {
            return false;
        }
        if ($skipCount < $this->maxSkips) {
            return true;
        }

        return array_intersect($assignedTableIds, $this->finder->relevantTableIds($party->size, $floor)) !== [];
    }

    /**
     * 「空」= 可用、沒有保留給別人（保留給自己的算空），且不在這次分配的桌子中。
     *
     * @param  list<int>  $assignedTableIds
     * @return callable(TableSnapshot): bool
     */
    private function freeExcept(int $partyId, array $assignedTableIds): callable
    {
        return fn (TableSnapshot $t): bool => $t->status === TableStatus::Available
            && ($t->heldForPartyId === null || $t->heldForPartyId === $partyId)
            && ! in_array($t->id, $assignedTableIds, true);
    }
}
