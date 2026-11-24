<?php

use App\Domain\Enums\PartyStatus;
use App\Models\DiningTable;
use App\Models\Party;
use App\Services\SeatingService;
use App\Support\ActorContext;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

function expireEvent(): Event
{
    return collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'expire-no-shows');
}

it('schedules the no-show check every ten seconds', function () {
    expect(expireEvent())->not->toBeNull()
        ->and(expireEvent()->repeatSeconds)->toBe(10);
});

it('expires overdue calls when the scheduled check runs', function () {
    $this->travelTo('2026-11-24 18:00');
    $table = DiningTable::factory()->create(['capacity' => 2]);
    $party = Party::factory()->size(2)->create();
    app(SeatingService::class)->call($party, [$table->id], ActorContext::system());

    $this->travelTo('2026-11-24 18:03:05');
    expireEvent()->run(app());

    expect($party->fresh()->status)->toBe(PartyStatus::NoShow);
});
