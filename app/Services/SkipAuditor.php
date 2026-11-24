<?php

namespace App\Services;

use App\Domain\Seating\OptionFinder;
use App\Models\ActivityLog;
use App\Models\Party;

/**
 * 獨立稽核跳號次數：只讀操作紀錄中的叫號（分配了哪些桌、當時排在前面的有誰），
 * 用同一個「相關桌」定義重新計算，不依賴 parties.skip_count。
 * 模擬器用它來證明「沒有任何客人被跳過超過 3 次」，而不是相信系統自己記的數字。
 *
 * 限制：相關桌以「目前」的桌位設定判斷，營業中若修改桌位或相鄰關係，稽核結果可能與當時不同。
 */
class SkipAuditor
{
    public function __construct(
        private readonly FloorRepository $floors,
        private readonly OptionFinder $finder,
    ) {}

    /**
     * @return array<int, int> partyId => 被跳過次數（只列出至少一次的）
     */
    public function audit(string $businessDate): array
    {
        $partyIds = Party::query()->where('business_date', $businessDate)->pluck('id')->all();
        if ($partyIds === []) {
            return [];
        }

        $floor = $this->floors->floor();
        $relevant = [];
        $counts = [];

        $calls = ActivityLog::query()
            ->where('action', 'party.called')
            ->where('subject_type', 'party')
            ->whereIn('subject_id', $partyIds)
            ->orderBy('id')
            ->get();

        foreach ($calls as $call) {
            $meta = $call->meta ?? [];
            /** @var list<int> $tableIds */
            $tableIds = $meta['table_ids'] ?? [];
            /** @var list<array{party_id: int, size: int}> $ahead */
            $ahead = $meta['ahead'] ?? [];

            foreach ($ahead as $party) {
                $relevant[$party['size']] ??= $this->finder->relevantTableIds($party['size'], $floor);
                if (array_intersect($tableIds, $relevant[$party['size']]) !== []) {
                    $counts[$party['party_id']] = ($counts[$party['party_id']] ?? 0) + 1;
                }
            }
        }

        return $counts;
    }
}
