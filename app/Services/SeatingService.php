<?php

namespace App\Services;

use App\Domain\Enums\HoldReason;
use App\Domain\Enums\PartyStatus;
use App\Domain\Enums\TableStatus;
use App\Domain\Seating\FloorSnapshot;
use App\Domain\Seating\OptionFinder;
use App\Domain\Seating\SkipRule;
use App\Domain\Seating\TableSnapshot;
use App\Domain\Seating\WaitingParty;
use App\Exceptions\DuplicateActivePhone;
use App\Exceptions\InvalidTableChoice;
use App\Exceptions\InvalidTransition;
use App\Exceptions\RequeueNotAllowed;
use App\Exceptions\SkipLimitExceeded;
use App\Exceptions\TableUnavailable;
use App\Models\DiningTable;
use App\Models\Party;
use App\Models\Seating;
use App\Support\ActorContext;
use Illuminate\Support\Facades\DB;

/**
 * 叫號、報到入座、過號、重新排入。
 */
class SeatingService
{
    public function __construct(
        private readonly FloorRepository $floors,
        private readonly OptionFinder $finder,
        private readonly SkipRule $skipRule,
        private readonly QueueStateRefresher $refresher,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * 叫號並把桌子保留給這組 3 分鐘。
     *
     * @param  list<int>  $tableIds
     */
    public function call(Party $party, array $tableIds, ActorContext $actor): Party
    {
        return DB::transaction(function () use ($party, $tableIds, $actor): Party {
            $party->refresh();
            if ($party->status !== PartyStatus::Waiting) {
                throw new InvalidTransition("號碼 {$party->ticket_code} 目前為「{$party->status->label()}」，無法叫號");
            }

            // 檢查順序決定錯誤訊息：叫號保留 → 選項是否合法 → 跳號上限 → 保護保留
            $floor = $this->floors->floor();
            $this->assertNotHeldByOthers($party, $tableIds, $floor, HoldReason::Called);
            $this->assertValidChoice($party, $tableIds, $floor);

            $waiting = $this->floors->waiting($party->business_date);
            $skipped = $this->assertSkipLimit($party, $tableIds, $waiting, $floor);
            $this->assertNotHeldByOthers($party, $tableIds, $floor, HoldReason::Protected);

            // 條件式更新：讀取後若有另一台平板搶先動了這些桌子，影響筆數就會不符
            $affected = DiningTable::query()
                ->whereIn('id', $tableIds)
                ->where('status', TableStatus::Available)
                ->where(fn ($q) => $q->whereNull('held_for_party_id')->orWhere('held_for_party_id', $party->id))
                ->update(['held_for_party_id' => $party->id, 'hold_reason' => HoldReason::Called]);
            if ($affected !== count($tableIds)) {
                throw new TableUnavailable('桌子剛被其他平板使用，請重新整理後再試');
            }

            if ($skipped !== []) {
                Party::query()->whereIn('id', $skipped)->increment('skip_count');
            }
            $party->update(['status' => PartyStatus::Called, 'called_at' => now()]);

            $this->logger->record($actor, 'party.called', $party, PartyStatus::Waiting->value, PartyStatus::Called->value, [
                'table_ids' => $tableIds,
                'ahead' => array_values(array_map(
                    fn (WaitingParty $w): array => ['party_id' => $w->id, 'size' => $w->size, 'issued_at' => $w->issuedAt],
                    array_filter($waiting, fn (WaitingParty $w): bool => $w->isAheadOf($this->asWaiting($party))),
                )),
                'skipped_party_ids' => $skipped,
                // 叫號當下可用的桌子與保留對象，讓 SkipAuditor 能獨立重算「前面的組是否仍有桌可坐」
                'free_tables' => array_values(array_map(
                    fn (TableSnapshot $t): array => ['id' => $t->id, 'held_for' => $t->heldForPartyId],
                    array_filter($floor->tables, fn (TableSnapshot $t): bool => $t->status === TableStatus::Available),
                )),
            ]);
            $this->refresher->refresh($party->business_date, $actor);

            return $party;
        });
    }

    /**
     * 叫號後客人報到：建立入座紀錄，桌子轉為用餐中。
     */
    public function checkIn(Party $party, ActorContext $actor): Seating
    {
        return DB::transaction(function () use ($party, $actor): Seating {
            $party->refresh();
            if ($party->status !== PartyStatus::Called) {
                throw new InvalidTransition("號碼 {$party->ticket_code} 目前為「{$party->status->label()}」，無法入座");
            }

            $tableIds = DiningTable::query()
                ->where('held_for_party_id', $party->id)
                ->where('hold_reason', HoldReason::Called)
                ->orderBy('code')
                ->pluck('id')->map(fn ($id): int => (int) $id)->all();
            if ($tableIds === []) {
                throw new TableUnavailable("找不到保留給 {$party->ticket_code} 的桌子");
            }

            $affected = DiningTable::query()
                ->whereIn('id', $tableIds)
                ->where('status', TableStatus::Available)
                ->where('held_for_party_id', $party->id)
                ->update([
                    'status' => TableStatus::Occupied,
                    'held_for_party_id' => null,
                    'hold_reason' => null,
                    'status_changed_at' => now(),
                ]);
            if ($affected !== count($tableIds)) {
                throw new TableUnavailable('桌子狀態已改變，請重新整理後再試');
            }

            $seating = Seating::create([
                'party_id' => $party->id,
                'business_date' => $party->business_date,
                'seated_at' => now(),
                'user_id' => $actor->userId,
                'device_id' => $actor->deviceId,
            ]);
            $seating->tables()->attach($tableIds);
            $party->update(['status' => PartyStatus::Seated, 'seated_at' => now()]);

            $this->logger->record($actor, 'party.seated', $party, PartyStatus::Called->value, PartyStatus::Seated->value, ['table_ids' => array_values($tableIds)]);
            foreach (DiningTable::query()->whereIn('id', $tableIds)->get() as $table) {
                $this->logger->record($actor, 'table.occupied', $table, TableStatus::Available->value, TableStatus::Occupied->value, ['party_id' => $party->id]);
            }
            $this->refresher->refresh($party->business_date, $actor);

            return $seating->load('tables');
        });
    }

    /**
     * 叫號滿 no_show_minutes 分鐘未報到者轉為過號，釋放保留的桌子。
     *
     * @return int 本次過號的組數
     */
    public function expireNoShows(ActorContext $actor): int
    {
        return DB::transaction(function () use ($actor): int {
            $deadline = now()->subMinutes((int) config('queuetable.no_show_minutes'));
            $parties = Party::query()
                ->where('status', PartyStatus::Called)
                ->where('called_at', '<=', $deadline)
                ->get();

            foreach ($parties as $party) {
                DiningTable::query()->where('held_for_party_id', $party->id)->update(['held_for_party_id' => null, 'hold_reason' => null]);
                $party->update(['status' => PartyStatus::NoShow, 'no_show_count' => $party->no_show_count + 1]);
                $this->logger->record($actor, 'party.no_show', $party, PartyStatus::Called->value, PartyStatus::NoShow->value);
            }

            foreach ($parties->pluck('business_date')->unique() as $date) {
                $this->refresher->refresh((string) $date, $actor);
            }

            return $parties->count();
        });
    }

    /**
     * 過號的客人回來：重新排入同佇列第 3 位（前面 2 組），每組只能一次。
     */
    public function requeue(Party $party, ActorContext $actor): Party
    {
        return DB::transaction(function () use ($party, $actor): Party {
            $party->refresh();
            if ($party->status !== PartyStatus::NoShow) {
                throw new RequeueNotAllowed("只有過號的號碼可以重新排入（{$party->ticket_code} 目前為「{$party->status->label()}」）");
            }
            if ($party->requeue_count >= (int) config('queuetable.requeue_limit')) {
                throw new RequeueNotAllowed('每組只能重新排入一次');
            }

            $other = Party::query()
                ->where('business_date', $party->business_date)
                ->where('phone', $party->phone)
                ->whereIn('status', [PartyStatus::Waiting, PartyStatus::Called])
                ->first();
            if ($other !== null) {
                throw new DuplicateActivePhone("此手機今天已有等候中的號碼 {$other->ticket_code}");
            }

            $party->update([
                'status' => PartyStatus::Waiting,
                'position_key' => $this->thirdPositionKey($party),
                'requeue_count' => $party->requeue_count + 1,
                'called_at' => null,
            ]);

            $this->logger->record($actor, 'party.requeued', $party, PartyStatus::NoShow->value, PartyStatus::Waiting->value, ['position_key' => $party->position_key]);
            $this->refresher->refresh($party->business_date, $actor);

            return $party;
        });
    }

    /**
     * 同佇列 waiting 依序為 k1, k2, k3…：
     * ≥ 3 組 → (k2 + k3) / 2；剛好 2 組 → k2 + 1；不足 2 組 → 排到最後。
     */
    private function thirdPositionKey(Party $party): float
    {
        $keys = Party::query()
            ->where('business_date', $party->business_date)
            ->where('queue', $party->queue)
            ->where('status', PartyStatus::Waiting)
            ->orderBy('position_key')->orderBy('id')
            ->pluck('position_key')
            ->map(fn ($k): float => (float) $k)
            ->all();

        $now = now()->getPreciseTimestamp(3) / 1000;

        if (count($keys) >= 3) {
            return ($keys[1] + $keys[2]) / 2;
        }
        if (count($keys) === 2) {
            return $keys[1] + 1;
        }
        $last = end($keys);

        return $last === false ? $now : max($now, $last + 1);
    }

    /**
     * 桌子必須構成一個適合的選項（坐得下、浪費不超標、併桌須相鄰且沒有空著的單桌）。
     * 被選中的保護保留桌視為空著，交給後面的跳號關卡判斷，錯誤訊息才會正確。
     *
     * @param  list<int>  $tableIds
     */
    private function assertValidChoice(Party $party, array $tableIds, FloorSnapshot $floor): void
    {
        foreach ($tableIds as $id) {
            if (! isset($floor->tables[$id])) {
                throw new InvalidTableChoice('選擇的桌子不存在');
            }
        }

        // 被選中的保護保留桌視為空著（交給跳號關卡給出正確訊息）；
        // 其他保護保留桌和建議演算法一樣視為不空，否則「有空著的單桌」會誤判而拒絕合法的併桌。
        $isFree = fn (TableSnapshot $t): bool => $t->status === TableStatus::Available
            && ($t->heldForPartyId === null || $t->heldForPartyId === $party->id
                || ($t->holdReason === HoldReason::Protected && in_array($t->id, $tableIds, true)));

        if (! $this->finder->isValidChoiceFor($this->asWaiting($party), $tableIds, $floor, $isFree, now()->getTimestamp())) {
            $codes = implode('+', array_map(fn (int $id): string => $floor->table($id)->code, $tableIds));
            $relaxAfter = (int) config('queuetable.relax_after_minutes');
            throw new InvalidTableChoice("{$codes} 目前不適合 {$party->size} 人（可能不空、坐不下、浪費太多座位，或有空著的單桌可用；等候滿 {$relaxAfter} 分鐘後才可坐浪費較多的桌子）");
        }
    }

    /**
     * 這次叫號會讓誰被跳過；若有人因此超過上限則拒絕。
     *
     * @param  list<int>  $tableIds
     * @param  list<WaitingParty>  $waiting
     * @return list<int>
     */
    private function assertSkipLimit(Party $party, array $tableIds, array $waiting, FloorSnapshot $floor): array
    {
        $called = $this->asWaiting($party);
        $skipped = $this->skipRule->skippedPartyIds($tableIds, $called, $waiting, $floor, now()->getTimestamp());
        $max = (int) config('queuetable.max_skips');

        foreach ($waiting as $w) {
            if (in_array($w->id, $skipped, true) && $w->skipCount + 1 > $max) {
                $code = Party::query()->whereKey($w->id)->value('ticket_code');
                throw new SkipLimitExceeded("{$code} 已被跳過 {$max} 次，這張桌子必須保留給他");
            }
        }

        return $skipped;
    }

    /**
     * @param  list<int>  $tableIds
     */
    private function assertNotHeldByOthers(Party $party, array $tableIds, FloorSnapshot $floor, HoldReason $reason): void
    {
        foreach ($tableIds as $id) {
            $table = $floor->tables[$id] ?? null;
            if ($table !== null && $table->holdReason === $reason && $table->heldForPartyId !== $party->id) {
                throw new TableUnavailable("{$table->code} 已保留給其他客人");
            }
        }
    }

    private function asWaiting(Party $party): WaitingParty
    {
        return new WaitingParty($party->id, $party->queue, $party->size, $party->position_key, $party->skip_count, $party->issued_at->getTimestamp());
    }
}
