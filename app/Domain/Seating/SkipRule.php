<?php

namespace App\Domain\Seating;

use App\Domain\Enums\TableStatus;

/**
 * 跳號計數規則（設計文件 §5.9，資源版）。
 *
 * 當 $called 被叫號並分配到 $assignedTableIds 時，排在他前面的組 P 只有在以下兩點都成立時才算被跳過一次：
 * 1. 這些桌子中至少有一張對 P 是「相關桌」（單桌適合，或屬於某個適合的併桌組合）；
 * 2. 扣掉這些桌子後，P 當下已經沒有任何全空的適合選項。
 *    （P 自己也還拿得到桌子，例如兩張建議卡被帶位人員反過來按，P 並沒有失去任何機會。）
 * 保護中的組照樣計算，讓第 4 次跳過能被偵測與拒絕。
 */
final class SkipRule
{
    public function __construct(private readonly OptionFinder $finder) {}

    /**
     * @param  list<int>  $assignedTableIds
     * @param  list<WaitingParty>  $waiting
     * @return list<int> 被跳過的 party id（依全域順序）
     */
    public function skippedPartyIds(array $assignedTableIds, WaitingParty $called, array $waiting, FloorSnapshot $floor): array
    {
        $skipped = [];

        foreach (WaitingParty::sortByOrder($waiting) as $party) {
            if ($party->id === $called->id || ! $party->isAheadOf($called)) {
                continue;
            }
            $relevant = $this->finder->relevantTableIds($party->size, $floor);
            if (array_intersect($assignedTableIds, $relevant) === []) {
                continue;
            }
            if ($this->stillHasOption($party, $assignedTableIds, $floor)) {
                continue;
            }
            $skipped[] = $party->id;
        }

        return $skipped;
    }

    /**
     * 扣掉這次分配的桌子後，P 是否仍有全空的適合選項。
     * 「空」= 可用，且沒有保留給別人（保留給 P 自己的算空）。
     *
     * @param  list<int>  $assignedTableIds
     */
    private function stillHasOption(WaitingParty $party, array $assignedTableIds, FloorSnapshot $floor): bool
    {
        $isFree = fn (TableSnapshot $t): bool => $t->status === TableStatus::Available
            && ($t->heldForPartyId === null || $t->heldForPartyId === $party->id)
            && ! in_array($t->id, $assignedTableIds, true);

        return $this->finder->bestFreeOption($party->size, $floor, $isFree) !== null;
    }
}
