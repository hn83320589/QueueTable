<?php

namespace App\Services;

use App\Domain\Seating\OptionFinder;
use App\Domain\Seating\TableSnapshot;
use App\Models\ActivityLog;
use App\Models\Party;

/**
 * 獨立稽核跳號次數：只讀操作紀錄中的叫號（分配了哪些桌、當時排在前面的有誰、當下哪些桌空著），
 * 用同一套規則重新計算，不依賴 parties.skip_count：
 * 分配的桌子對前面的組是相關桌，且扣掉後他當下已沒有全空的適合選項，才算被跳過。
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

            /** @var list<array{id: int, held_for: int|null}> $freeTables */
            $freeTables = $meta['free_tables'] ?? [];
            $heldFor = [];
            foreach ($freeTables as $table) {
                $heldFor[$table['id']] = $table['held_for'];
            }

            foreach ($ahead as $party) {
                $relevant[$party['size']] ??= $this->finder->relevantTableIds($party['size'], $floor);
                if (array_intersect($tableIds, $relevant[$party['size']]) === []) {
                    continue;
                }
                $isFree = fn (TableSnapshot $t): bool => array_key_exists($t->id, $heldFor)
                    && ($heldFor[$t->id] === null || $heldFor[$t->id] === $party['party_id'])
                    && ! in_array($t->id, $tableIds, true);
                if ($this->finder->bestFreeOption($party['size'], $floor, $isFree) !== null) {
                    continue;
                }
                $counts[$party['party_id']] = ($counts[$party['party_id']] ?? 0) + 1;
            }
        }

        return $counts;
    }
}
