<?php

namespace App\Services;

use App\Domain\Enums\PartyStatus;
use App\Domain\Enums\TableStatus;
use App\Domain\Estimation\ActiveSeating;
use App\Domain\Estimation\DiningStatsBuilder;
use App\Domain\Estimation\DiningStatsTable;
use App\Domain\Estimation\EstimatorInput;
use App\Domain\QueueLetter;
use App\Domain\Seating\FloorSnapshot;
use App\Domain\Seating\TableSnapshot;
use App\Domain\Seating\WaitingParty;
use App\Models\DiningTable;
use App\Models\Party;
use App\Models\Seating;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 從資料庫讀出 Domain 需要的快照（唯讀）。
 */
class FloorRepository
{
    public function floor(): FloorSnapshot
    {
        $tables = [];
        foreach (DiningTable::query()->orderBy('code')->get() as $table) {
            $tables[$table->id] = new TableSnapshot(
                $table->id,
                $table->code,
                $table->capacity,
                $table->status,
                $table->held_for_party_id,
                $table->hold_reason,
            );
        }

        $adjacency = DB::table('table_adjacencies')
            ->get(['table_a_id', 'table_b_id'])
            ->map(fn (object $row): array => [(int) $row->table_a_id, (int) $row->table_b_id])
            ->all();

        return new FloorSnapshot($tables, array_values($adjacency));
    }

    /**
     * 等候中的組，依全域順序 (position_key, id)。
     *
     * @return list<WaitingParty>
     */
    public function waiting(string $businessDate): array
    {
        return array_values(Party::query()
            ->where('business_date', $businessDate)
            ->where('status', PartyStatus::Waiting)
            ->orderBy('position_key')->orderBy('id')
            ->get()
            ->map(fn (Party $p): WaitingParty => new WaitingParty($p->id, $p->queue, $p->size, $p->position_key, $p->skip_count))
            ->all());
    }

    /**
     * 同佇列中排在這組前面的等候組數。
     */
    public function aheadInQueue(Party $party): int
    {
        return Party::query()
            ->where('business_date', $party->business_date)
            ->where('queue', $party->queue)
            ->where('status', PartyStatus::Waiting)
            ->where(fn ($q) => $q->where('position_key', '<', $party->position_key)
                ->orWhere(fn ($q) => $q->where('position_key', $party->position_key)->where('id', '<', $party->id)))
            ->count();
    }

    public function estimatorInput(string $businessDate): EstimatorInput
    {
        $seatings = Seating::query()
            ->whereNull('left_at')
            ->with(['party', 'tables' => fn ($q) => $q->whereNull('seating_tables.released_at')])
            ->get()
            ->map(fn (Seating $s): ActiveSeating => new ActiveSeating(
                array_values($s->tables->pluck('id')->map(fn ($id): int => (int) $id)->all()),
                $s->party->queue,
                $s->seated_at,
            ))
            ->all();

        $cleaningSince = [];
        foreach (DiningTable::query()->where('status', TableStatus::Cleaning)->get() as $table) {
            $cleaningSince[$table->id] = $table->status_changed_at ?? now();
        }

        $called = Party::query()
            ->where('business_date', $businessDate)
            ->where('status', PartyStatus::Called)
            ->with('heldTables')
            ->get()
            ->map(fn (Party $p): array => [
                'queue' => $p->queue,
                'tableIds' => array_values($p->heldTables->pluck('id')->map(fn ($id): int => (int) $id)->all()),
            ])
            ->all();

        return new EstimatorInput(
            CarbonImmutable::instance(now()),
            $this->floor(),
            array_values($seatings),
            $cleaningSince,
            array_values($called),
            $this->waiting($businessDate),
        );
    }

    /**
     * 近 N 個營業日（不含當天）的用餐時間統計。
     */
    public function diningStats(string $businessDate): DiningStatsTable
    {
        $to = CarbonImmutable::parse($businessDate);
        $from = $to->subDays((int) config('queuetable.estimate_history_days'));

        $samples = Seating::query()
            ->join('parties', 'parties.id', '=', 'seatings.party_id')
            ->whereNotNull('seatings.left_at')
            ->where('seatings.business_date', '>=', $from->toDateString())
            ->where('seatings.business_date', '<', $to->toDateString())
            ->get(['parties.queue as queue', 'seatings.seated_at', 'seatings.left_at'])
            ->map(fn (Seating $s): array => [
                'queue' => QueueLetter::from((string) $s->getAttribute('queue')),
                'seated_at' => $s->seated_at,
                'minutes' => intdiv(($s->left_at ?? $s->seated_at)->getTimestamp() - $s->seated_at->getTimestamp(), 60),
            ])
            ->all();

        /** @var array<string, int> $defaults */
        $defaults = config('queuetable.default_dining_minutes');

        return DiningStatsBuilder::build(
            array_values($samples),
            (int) config('queuetable.estimate_min_samples'),
            $defaults,
            (int) config('queuetable.default_dining_spread'),
        );
    }
}
