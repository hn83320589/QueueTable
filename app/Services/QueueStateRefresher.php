<?php

namespace App\Services;

use App\Domain\Enums\HoldReason;
use App\Domain\Estimation\WaitEstimator;
use App\Domain\Seating\AllocationResult;
use App\Domain\Seating\SeatingAllocator;
use App\Models\DiningTable;
use App\Models\NotificationLog;
use App\Models\Party;
use App\Support\ActorContext;

/**
 * 每次狀態變更後（在同一個交易內）把衍生狀態同步到最新：
 * 1. 保護保留（被跳過 3 次的組）
 * 2. 所有等候組的預估區間
 * 3. 「即將輪到」通知（前面剩 coming_soon_ahead 組以內，每組只產生一次）
 * 4. 交易提交後推播
 */
class QueueStateRefresher
{
    public function __construct(
        private readonly FloorRepository $floors,
        private readonly SeatingAllocator $allocator,
        private readonly WaitEstimator $estimator,
        private readonly ActivityLogger $logger,
        private readonly QueueBroadcaster $broadcaster,
    ) {}

    public function refresh(string $businessDate, ActorContext $actor): void
    {
        $this->syncProtectedHolds($this->currentSuggestions($businessDate), $actor);
        $this->updateEstimates($businessDate);
        $this->createComingSoonNotifications($businessDate);
        $this->broadcaster->afterCommit($businessDate);
    }

    public function currentSuggestions(string $businessDate): AllocationResult
    {
        return $this->allocator->allocate($this->floors->floor(), $this->floors->waiting($businessDate), now()->getTimestamp());
    }

    private function syncProtectedHolds(AllocationResult $result, ActorContext $actor): void
    {
        $desired = $result->protectedHolds;

        $current = DiningTable::query()->where('hold_reason', HoldReason::Protected)->get();
        foreach ($current as $table) {
            if (($desired[$table->id] ?? null) === $table->held_for_party_id) {
                unset($desired[$table->id]);

                continue;
            }
            $this->changeHold($table, $desired[$table->id] ?? null, $actor);
            unset($desired[$table->id]);
        }

        foreach ($desired as $tableId => $partyId) {
            $this->changeHold(DiningTable::findOrFail($tableId), $partyId, $actor);
        }
    }

    private function changeHold(DiningTable $table, ?int $partyId, ActorContext $actor): void
    {
        $from = $table->held_for_party_id;
        $table->update([
            'held_for_party_id' => $partyId,
            'hold_reason' => $partyId === null ? null : HoldReason::Protected,
        ]);
        $this->logger->record($actor, 'table.hold_changed', $table, $table->status->value, $table->status->value, [
            'reason' => HoldReason::Protected->value,
            'from_party_id' => $from,
            'to_party_id' => $partyId,
        ]);
    }

    private function updateEstimates(string $businessDate): void
    {
        $estimates = $this->estimator->estimate(
            $this->floors->estimatorInput($businessDate),
            $this->floors->diningStats($businessDate),
        );

        foreach ($estimates as $partyId => $estimate) {
            Party::query()->whereKey($partyId)->update([
                'est_low' => $estimate->low,
                'est_high' => $estimate->high,
            ]);
        }
    }

    private function createComingSoonNotifications(string $businessDate): void
    {
        $limit = (int) config('queuetable.coming_soon_ahead');
        $ahead = [];
        $rows = [];

        foreach ($this->floors->waiting($businessDate) as $party) {
            $count = $ahead[$party->queue->value] ?? 0;
            $ahead[$party->queue->value] = $count + 1;
            if ($count > $limit) {
                continue;
            }
            $rows[] = $party->id;
        }

        if ($rows === []) {
            return;
        }

        $codes = Party::query()->whereIn('id', $rows)->pluck('ticket_code', 'id');
        NotificationLog::query()->insertOrIgnore(array_map(fn (int $id): array => [
            'party_id' => $id,
            'type' => NotificationLog::COMING_SOON,
            'message' => "您的號碼 {$codes[$id]} 即將輪到，請回到店門口",
            'created_at' => now(),
        ], $rows));
    }
}
