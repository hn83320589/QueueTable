<?php

namespace App\Services;

use App\Domain\QueueLetter;
use App\Domain\Seating\SkipRule;
use App\Domain\Seating\TableSnapshot;
use App\Domain\Seating\WaitingParty;
use App\Models\ActivityLog;
use App\Models\Party;

/**
 * 獨立稽核跳號次數：只讀操作紀錄中的叫號（分配了哪些桌、當時排在前面的有誰、當下哪些桌空著），
 * 用同一條 SkipRule 依時間順序重新計算，「是否已進入保護」也以稽核器自己累計的次數判斷，
 * 不依賴 parties.skip_count。
 * 模擬器用它來證明「沒有任何客人被跳過超過 3 次」，而不是相信系統自己記的數字。
 *
 * 限制：相關桌以「目前」的桌位設定判斷，營業中若修改桌位或相鄰關係，稽核結果可能與當時不同。
 */
class SkipAuditor
{
    public function __construct(
        private readonly FloorRepository $floors,
        private readonly SkipRule $rule,
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
            /** @var list<array{party_id: int, size: int, issued_at?: int|null}> $ahead */
            $ahead = $meta['ahead'] ?? [];

            /** @var list<array{id: int, held_for: int|null}> $freeTables */
            $freeTables = $meta['free_tables'] ?? [];
            $heldFor = [];
            foreach ($freeTables as $table) {
                $heldFor[$table['id']] = $table['held_for'];
            }

            foreach ($ahead as $party) {
                $isFree = fn (TableSnapshot $t): bool => array_key_exists($t->id, $heldFor)
                    && ($heldFor[$t->id] === null || $heldFor[$t->id] === $party['party_id'])
                    && ! in_array($t->id, $tableIds, true);
                $waitingParty = new WaitingParty($party['party_id'], QueueLetter::fromPartySize($party['size']), $party['size'], 0.0, 0, $party['issued_at'] ?? null);
                // 用稽核器自己累計的次數判斷是否已進入保護，不讀 parties.skip_count
                if ($this->rule->counts($waitingParty, $counts[$party['party_id']] ?? 0, $tableIds, $isFree, $floor, $call->occurred_at->getTimestamp())) {
                    $counts[$party['party_id']] = ($counts[$party['party_id']] ?? 0) + 1;
                }
            }
        }

        return $counts;
    }
}
