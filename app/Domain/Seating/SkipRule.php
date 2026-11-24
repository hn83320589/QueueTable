<?php

namespace App\Domain\Seating;

/**
 * 跳號計數規則（設計文件 §5.9，資源版）。
 *
 * 當 $called 被叫號並分配到 $assignedTableIds 時，排在他前面、
 * 且這些桌子中至少有一張對他是「相關桌」（單桌適合，或屬於某個適合的併桌組合）的組，
 * 才算被跳過一次。保護中的組照樣計算，讓第 4 次跳過能被偵測與拒絕。
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
            if (array_intersect($assignedTableIds, $relevant) !== []) {
                $skipped[] = $party->id;
            }
        }

        return $skipped;
    }
}
