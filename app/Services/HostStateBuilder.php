<?php

namespace App\Services;

use App\Domain\Enums\PartyStatus;
use App\Domain\Enums\TableStatus;
use App\Domain\Seating\OptionFinder;
use App\Domain\Seating\TableSnapshot;
use App\Domain\Seating\WaitingParty;
use App\Models\DiningTable;
use App\Models\Party;
use App\Models\Seating;

/**
 * 帶位平板需要的完整狀態（含姓名，只經已登入的 HTTP 傳送）。
 */
class HostStateBuilder
{
    public function __construct(
        private readonly QueueStateRefresher $refresher,
        private readonly FloorRepository $floors,
        private readonly OptionFinder $finder,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(string $businessDate): array
    {
        $parties = Party::query()
            ->where('business_date', $businessDate)
            ->whereIn('status', [PartyStatus::Waiting, PartyStatus::Called])
            ->orderBy('position_key')->orderBy('id')
            ->get();
        $codes = Party::query()->where('business_date', $businessDate)->pluck('ticket_code', 'id');

        $ahead = [];
        $partyRows = [];
        foreach ($parties as $p) {
            $count = $ahead[$p->queue->value] ?? 0;
            if ($p->status === PartyStatus::Waiting) {
                $ahead[$p->queue->value] = $count + 1;
            }
            $partyRows[] = [
                'id' => $p->id,
                'ticket_code' => $p->ticket_code,
                'queue' => $p->queue->value,
                'size' => $p->size,
                'name' => $p->name,
                'phone_tail' => substr($p->phone, -3),
                'channel' => $p->channel->value,
                'status' => $p->status->value,
                'ahead' => $p->status === PartyStatus::Waiting ? $count : 0,
                'waited_minutes' => intdiv(now()->getTimestamp() - $p->issued_at->getTimestamp(), 60),
                'skip_count' => $p->skip_count,
                'requeue_count' => $p->requeue_count,
                'est_low' => $p->est_low,
                'est_high' => $p->est_high,
                'called_at' => $p->called_at?->toIso8601String(),
            ];
        }

        $noShows = Party::query()
            ->where('business_date', $businessDate)
            ->where('status', PartyStatus::NoShow)
            ->orderByDesc('called_at')
            ->get()
            ->map(fn (Party $p): array => [
                'id' => $p->id,
                'ticket_code' => $p->ticket_code,
                'size' => $p->size,
                'name' => $p->name,
                'can_requeue' => $p->requeue_count < (int) config('queuetable.requeue_limit'),
            ])->all();

        $activeSeatings = Seating::query()
            ->whereNull('left_at')
            ->with(['party', 'tables' => fn ($q) => $q->whereNull('seating_tables.released_at')])
            ->get();
        $seatingOf = [];
        foreach ($activeSeatings as $seating) {
            foreach ($seating->tables as $table) {
                $seatingOf[$table->id] = $seating;
            }
        }

        $tables = DiningTable::query()->orderBy('code')->get()->map(function (DiningTable $t) use ($seatingOf, $codes): array {
            $seating = $seatingOf[$t->id] ?? null;

            return [
                'id' => $t->id,
                'code' => $t->code,
                'capacity' => $t->capacity,
                'area' => $t->area->value,
                'status' => $t->status->value,
                'held_for' => $t->held_for_party_id !== null ? ($codes[$t->held_for_party_id] ?? null) : null,
                'hold_reason' => $t->hold_reason?->value,
                'seated_at' => $seating?->seated_at->toIso8601String(),
                'elapsed_minutes' => $seating !== null ? intdiv(now()->getTimestamp() - $seating->seated_at->getTimestamp(), 60) : null,
                'party_ticket' => $seating?->party->ticket_code,
                'party_size' => $seating?->party->size,
                'status_changed_at' => $t->status_changed_at?->toIso8601String(),
            ];
        })->all();

        $floor = $this->floors->floor();
        $suggestions = array_map(fn ($s): array => [
            'party_id' => $s->partyId,
            'ticket_code' => $codes[$s->partyId] ?? '',
            'size' => $parties->firstWhere('id', $s->partyId)?->size,
            'table_ids' => $s->option->tableIds,
            'table_codes' => array_map(fn (int $id): string => $floor->table($id)->code, $s->option->tableIds),
            'waste' => $s->option->waste,
            'combined' => $s->option->combined,
        ], $this->refresher->currentSuggestions($businessDate)->suggestions);

        return [
            'business_date' => $businessDate,
            'now' => now()->toIso8601String(),
            'parties' => $partyRows,
            'no_shows' => $noShows,
            'tables' => $tables,
            'suggestions' => $suggestions,
        ];
    }

    /**
     * 這組客人目前可以叫號的所有合法選項（給平板「改選其他桌」用）。
     *
     * @return list<array{table_ids: list<int>, codes: list<string>, capacity: int, waste: int, combined: bool}>
     */
    public function optionsFor(Party $party): array
    {
        $floor = $this->floors->floor();
        $isFree = fn (TableSnapshot $t): bool => $t->status === TableStatus::Available
            && ($t->heldForPartyId === null || $t->heldForPartyId === $party->id);

        $waiting = new WaitingParty($party->id, $party->queue, $party->size, $party->position_key, $party->skip_count, $party->issued_at->getTimestamp());
        $now = now()->getTimestamp();

        $options = [];
        foreach ($this->finder->allOptionsFor($waiting, $floor, $now) as $option) {
            if (! $this->finder->isValidChoiceFor($waiting, $option->tableIds, $floor, $isFree, $now)) {
                continue;
            }
            $options[] = [
                'table_ids' => $option->tableIds,
                'codes' => array_map(fn (int $id): string => $floor->table($id)->code, $option->tableIds),
                'capacity' => $option->capacity,
                'waste' => $option->waste,
                'combined' => $option->combined,
            ];
        }

        return $options;
    }
}
