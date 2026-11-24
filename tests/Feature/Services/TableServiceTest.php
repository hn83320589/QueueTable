<?php

use App\Domain\Enums\TableStatus;
use App\Exceptions\InvalidTransition;
use App\Models\ActivityLog;
use App\Models\Device;
use App\Models\DiningTable;
use App\Models\Party;
use App\Models\User;
use App\Services\QueueStateRefresher;
use App\Services\SeatingService;
use App\Services\TableService;
use App\Support\ActorContext;

beforeEach(function () {
    $this->travelTo('2026-11-24 18:00');
    $this->tables = app(TableService::class);
    $this->seating = app(SeatingService::class);
    $this->actor = ActorContext::staff(User::factory()->create(), Device::factory()->create());
});

function seatAt(object $test, int $size, array $tables): Party
{
    $party = Party::factory()->size($size)->create();
    $test->seating->call($party, array_map(fn ($t) => $t->id, $tables), $test->actor);
    $test->seating->checkIn($party, $test->actor);

    return $party->fresh();
}

it('moves every table of a combined seating to cleaning when the party leaves', function () {
    $a = DiningTable::factory()->create(['code' => 'T2a', 'capacity' => 2]);
    $b = DiningTable::factory()->create(['code' => 'T2b', 'capacity' => 2]);
    DiningTable::linkAdjacent($a->id, $b->id);
    $party = seatAt($this, 4, [$a, $b]);
    $this->travelTo('2026-11-24 19:10');

    $seating = $this->tables->leave($b, $this->actor);

    expect($seating->left_at->toDateTimeString())->toBe('2026-11-24 19:10:00')
        ->and($a->fresh()->status)->toBe(TableStatus::Cleaning)
        ->and($b->fresh()->status)->toBe(TableStatus::Cleaning)
        ->and($seating->tables()->wherePivotNull('released_at')->count())->toBe(0)
        ->and($seating->party_id)->toBe($party->id);
});

it('makes a cleaned table available and suggests the next party', function () {
    $t = DiningTable::factory()->create(['capacity' => 4]);
    seatAt($this, 4, [$t]);
    $next = Party::factory()->size(4)->create();
    $this->tables->leave($t, $this->actor);

    $this->tables->markCleaned($t, $this->actor);

    $suggestions = app(QueueStateRefresher::class)->currentSuggestions('2026-11-24')->suggestions;
    expect($t->fresh()->status)->toBe(TableStatus::Available)
        ->and($suggestions[0]->partyId)->toBe($next->id);
});

it('cannot leave a table that is not occupied', function () {
    $t = DiningTable::factory()->create();

    $this->tables->leave($t, $this->actor);
})->throws(InvalidTransition::class);

it('cannot disable an occupied table', function () {
    $t = DiningTable::factory()->create(['capacity' => 4]);
    seatAt($this, 4, [$t]);

    expect(fn () => $this->tables->disable($t, $this->actor))->toThrow(InvalidTransition::class, '用餐中');
});

it('cannot disable a table held for a called party', function () {
    $t = DiningTable::factory()->create(['capacity' => 4]);
    $party = Party::factory()->size(4)->create();
    $this->seating->call($party, [$t->id], $this->actor);

    expect(fn () => $this->tables->disable($t, $this->actor))->toThrow(InvalidTransition::class, $party->ticket_code);
});

it('logs every table transition with user, device and time', function () {
    $t = DiningTable::factory()->create(['capacity' => 4]);
    seatAt($this, 4, [$t]);
    ActivityLog::query()->delete();

    $this->tables->leave($t, $this->actor);
    $this->tables->markCleaned($t, $this->actor);
    $this->tables->disable($t, $this->actor);
    $this->tables->enable($t, $this->actor);

    $logs = ActivityLog::where('subject_type', 'dining_table')->orderBy('id')->get();
    expect($logs->map(fn ($l) => [$l->action, $l->from_status, $l->to_status])->all())->toBe([
        ['table.cleaning', 'occupied', 'cleaning'],
        ['table.available', 'cleaning', 'available'],
        ['table.disabled', 'available', 'disabled'],
        ['table.enabled', 'disabled', 'available'],
    ])->and($logs->every(fn ($l) => $l->user_id === $this->actor->userId
        && $l->device_id === $this->actor->deviceId
        && $l->occurred_at->toDateTimeString() === '2026-11-24 18:00:00'))->toBeTrue();
});
