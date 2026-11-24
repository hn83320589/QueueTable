<?php

use App\Domain\Enums\HoldReason;
use App\Domain\Enums\PartyStatus;
use App\Domain\Enums\TableStatus;
use App\Models\ActivityLog;
use App\Models\DiningTable;
use App\Models\NotificationLog;
use App\Models\Party;
use App\Services\QueueStateRefresher;
use App\Support\ActorContext;

beforeEach(function () {
    $this->travelTo('2026-11-24 18:00');
    $this->refresher = app(QueueStateRefresher::class);
    $this->date = '2026-11-24';
});

function waitingParty(int $size, float $key, array $attrs = []): Party
{
    return Party::factory()->size($size)->create(['position_key' => $key] + $attrs);
}

it('creates exactly one coming-soon notification per party', function () {
    $parties = collect(range(1, 4))->map(fn ($i) => waitingParty(2, $i));

    $this->refresher->refresh($this->date, ActorContext::system());
    $this->refresher->refresh($this->date, ActorContext::system());
    $this->refresher->refresh($this->date, ActorContext::system());

    // 前面 0、1、2 組的前三組各一則；第四組前面還有 3 組，沒有
    expect(NotificationLog::count())->toBe(3)
        ->and(NotificationLog::where('party_id', $parties[3]->id)->exists())->toBeFalse();

    $parties[0]->update(['status' => PartyStatus::Seated]);
    $this->refresher->refresh($this->date, ActorContext::system());

    expect(NotificationLog::count())->toBe(4)
        ->and(NotificationLog::where('party_id', $parties[3]->id)->count())->toBe(1)
        ->and(NotificationLog::where('party_id', $parties[3]->id)->value('message'))->toContain($parties[3]->ticket_code);
});

it('counts parties ahead within the same queue only', function () {
    collect(range(1, 3))->each(fn ($i) => waitingParty(4, $i));
    $a = waitingParty(2, 10);

    $this->refresher->refresh($this->date, ActorContext::system());

    expect(NotificationLog::where('party_id', $a->id)->exists())->toBeTrue();
});

it('holds every free relevant table for a protected party and releases it later', function () {
    $t4x = DiningTable::factory()->create(['code' => 'T4x', 'capacity' => 4]);
    $t6 = DiningTable::factory()->create(['code' => 'T6', 'capacity' => 6, 'status' => TableStatus::Occupied]);
    DiningTable::linkAdjacent($t4x->id, $t6->id);
    $p = waitingParty(10, 1, ['skip_count' => 3]);

    $this->refresher->refresh($this->date, ActorContext::system());

    expect($t4x->fresh()->held_for_party_id)->toBe($p->id)
        ->and($t4x->fresh()->hold_reason)->toBe(HoldReason::Protected)
        ->and(ActivityLog::where('action', 'table.hold_changed')->count())->toBe(1);

    $p->update(['status' => PartyStatus::Cancelled]);
    $this->refresher->refresh($this->date, ActorContext::system());

    expect($t4x->fresh()->held_for_party_id)->toBeNull()
        ->and($t4x->fresh()->hold_reason)->toBeNull()
        ->and(ActivityLog::where('action', 'table.hold_changed')->count())->toBe(2);
});

it('never touches tables held for a called party', function () {
    $called = waitingParty(4, 1, ['status' => PartyStatus::Called, 'called_at' => now()]);
    $t4 = DiningTable::factory()->create(['capacity' => 4, 'held_for_party_id' => $called->id, 'hold_reason' => HoldReason::Called]);

    $this->refresher->refresh($this->date, ActorContext::system());

    expect($t4->fresh()->held_for_party_id)->toBe($called->id)
        ->and($t4->fresh()->hold_reason)->toBe(HoldReason::Called);
});

it('updates estimates for all waiting parties', function () {
    DiningTable::factory()->create(['capacity' => 4]);
    $a = waitingParty(4, 1);
    $b = waitingParty(12, 2);

    $this->refresher->refresh($this->date, ActorContext::system());

    expect([$a->fresh()->est_low, $a->fresh()->est_high])->toBe([0, 5])
        ->and([$b->fresh()->est_low, $b->fresh()->est_high])->toBe([null, null]);
});

it('exposes the current suggestions', function () {
    $t4 = DiningTable::factory()->create(['capacity' => 4]);
    $a = waitingParty(3, 1);

    $result = $this->refresher->currentSuggestions($this->date);

    expect($result->suggestions)->toHaveCount(1)
        ->and($result->suggestions[0]->partyId)->toBe($a->id)
        ->and($result->suggestions[0]->option->tableIds)->toBe([$t4->id]);
});
