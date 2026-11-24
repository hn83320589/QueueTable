<?php

use App\Domain\Enums\HoldReason;
use App\Domain\Enums\PartyStatus;
use App\Domain\Enums\TableStatus;
use App\Exceptions\InvalidTableChoice;
use App\Exceptions\InvalidTransition;
use App\Exceptions\TableUnavailable;
use App\Models\ActivityLog;
use App\Models\Device;
use App\Models\DiningTable;
use App\Models\Party;
use App\Models\Seating;
use App\Models\User;
use App\Services\QueueStateRefresher;
use App\Services\SeatingService;
use App\Services\TicketService;
use App\Support\ActorContext;

beforeEach(function () {
    $this->travelTo('2026-11-24 18:00');
    $this->seating = app(SeatingService::class);
    $this->actor = ActorContext::staff(User::factory()->create(), Device::factory()->create());
    $this->t1 = DiningTable::factory()->create(['code' => 'T1', 'capacity' => 4]);
});

it('calls a party and holds its tables', function () {
    $party = Party::factory()->size(4)->create();

    $this->seating->call($party, [$this->t1->id], $this->actor);

    expect($party->fresh()->status)->toBe(PartyStatus::Called)
        ->and($party->fresh()->called_at->toDateTimeString())->toBe('2026-11-24 18:00:00')
        ->and($this->t1->fresh()->held_for_party_id)->toBe($party->id)
        ->and($this->t1->fresh()->hold_reason)->toBe(HoldReason::Called);
});

it('seats a called party and occupies its tables', function () {
    $party = Party::factory()->size(4)->create();
    $this->seating->call($party, [$this->t1->id], $this->actor);

    $seating = $this->seating->checkIn($party, $this->actor);

    expect($party->fresh()->status)->toBe(PartyStatus::Seated)
        ->and($this->t1->fresh()->status)->toBe(TableStatus::Occupied)
        ->and($this->t1->fresh()->held_for_party_id)->toBeNull()
        ->and($seating->tables->pluck('id')->all())->toBe([$this->t1->id])
        ->and($seating->user_id)->toBe($this->actor->userId)
        ->and($seating->device_id)->toBe($this->actor->deviceId);
});

it('refuses to bring two parties to one table', function () {
    $a = Party::factory()->size(4)->create();
    $b = Party::factory()->size(4)->create();
    $this->seating->call($a, [$this->t1->id], $this->actor);

    expect(fn () => $this->seating->call($b, [$this->t1->id], $this->actor))->toThrow(TableUnavailable::class);
    expect($b->fresh()->status)->toBe(PartyStatus::Waiting);
});

it('refuses to seat the same party twice', function () {
    $party = Party::factory()->size(4)->create();
    $this->seating->call($party, [$this->t1->id], $this->actor);
    $this->seating->checkIn($party, $this->actor);

    expect(fn () => $this->seating->checkIn($party, $this->actor))->toThrow(InvalidTransition::class);
    expect(Seating::where('party_id', $party->id)->count())->toBe(1);
});

it('refuses to call a party that is already called', function () {
    $party = Party::factory()->size(4)->create();
    $t2 = DiningTable::factory()->create(['capacity' => 4]);
    $this->seating->call($party, [$this->t1->id], $this->actor);

    expect(fn () => $this->seating->call($party, [$t2->id], $this->actor))->toThrow(InvalidTransition::class);
});

it('refuses a table another tablet just occupied and leaves no trace', function () {
    $party = Party::factory()->size(4)->create();
    // 另一台平板剛好把這張桌子入座了
    $this->t1->update(['status' => TableStatus::Occupied]);

    expect(fn () => $this->seating->call($party, [$this->t1->id], $this->actor))->toThrow(InvalidTableChoice::class);
    expect($party->fresh()->status)->toBe(PartyStatus::Waiting)
        ->and(ActivityLog::where('action', 'party.called')->count())->toBe(0);
});

it('rejects a combo when a single table is free', function () {
    $a = DiningTable::factory()->create(['code' => 'T2a', 'capacity' => 2]);
    $b = DiningTable::factory()->create(['code' => 'T2b', 'capacity' => 2]);
    DiningTable::linkAdjacent($a->id, $b->id);
    $party = Party::factory()->size(4)->create();

    expect(fn () => $this->seating->call($party, [$a->id, $b->id], $this->actor))->toThrow(InvalidTableChoice::class);

    $this->t1->update(['status' => TableStatus::Occupied]);
    $this->seating->call($party->fresh(), [$a->id, $b->id], $this->actor);
    expect($party->fresh()->status)->toBe(PartyStatus::Called);
});

it('releases tables and recomputes suggestions when a called party cancels', function () {
    $party = Party::factory()->size(4)->create();
    $next = Party::factory()->size(4)->create();
    $this->seating->call($party, [$this->t1->id], $this->actor);

    app(TicketService::class)->cancel($party, ActorContext::customer());

    $suggestions = app(QueueStateRefresher::class)->currentSuggestions('2026-11-24')->suggestions;
    expect($this->t1->fresh()->held_for_party_id)->toBeNull()
        ->and($suggestions[0]->partyId)->toBe($next->id)
        ->and($suggestions[0]->option->tableIds)->toBe([$this->t1->id]);
});

it('logs call and seat with user and device', function () {
    $party = Party::factory()->size(4)->create();
    $this->seating->call($party, [$this->t1->id], $this->actor);
    $this->seating->checkIn($party, $this->actor);

    $logs = ActivityLog::whereIn('action', ['party.called', 'party.seated', 'table.occupied'])->get();

    expect($logs->pluck('action')->sort()->values()->all())->toBe(['party.called', 'party.seated', 'table.occupied'])
        ->and($logs->every(fn ($l) => $l->user_id === $this->actor->userId && $l->device_id === $this->actor->deviceId))->toBeTrue()
        ->and($logs->firstWhere('action', 'party.called')->meta['table_ids'])->toBe([$this->t1->id]);
});

it('lets a party of five take an eight-seat table after waiting fifteen minutes', function () {
    $this->t1->update(['status' => TableStatus::Occupied]);
    $t8 = DiningTable::factory()->create(['code' => 'T8', 'capacity' => 8]);
    $party = Party::factory()->size(5)->create(['issued_at' => '2026-11-24 18:00:00']);

    expect(fn () => $this->seating->call($party, [$t8->id], $this->actor))->toThrow(InvalidTableChoice::class);
    expect(app(QueueStateRefresher::class)->currentSuggestions('2026-11-24')->suggestions)->toBe([]);

    $this->travelTo('2026-11-24 18:15');
    $suggestions = app(QueueStateRefresher::class)->currentSuggestions('2026-11-24')->suggestions;
    expect($suggestions[0]->option->tableIds)->toBe([$t8->id]);

    $this->seating->call($party->fresh(), [$t8->id], $this->actor);
    expect($party->fresh()->status)->toBe(PartyStatus::Called);
});
