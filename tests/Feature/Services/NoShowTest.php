<?php

use App\Domain\Enums\Channel;
use App\Domain\Enums\PartyStatus;
use App\Exceptions\DuplicateActivePhone;
use App\Exceptions\RequeueNotAllowed;
use App\Models\DiningTable;
use App\Models\Party;
use App\Services\FloorRepository;
use App\Services\SeatingService;
use App\Services\TicketService;
use App\Support\ActorContext;

beforeEach(function () {
    $this->travelTo('2026-11-24 18:00:00');
    $this->seating = app(SeatingService::class);
    $this->table = DiningTable::factory()->create(['capacity' => 2]);
    $this->system = ActorContext::system();
});

/** 建立一組被叫號、3 分鐘後過號的客人。 */
function noShowParty(object $test, float $key = 0.5): Party
{
    $party = Party::factory()->size(2)->create(['position_key' => $key]);
    $test->seating->call($party, [$test->table->id], $test->system);
    $test->travelTo(now()->addMinutes(3));
    $test->seating->expireNoShows($test->system);

    return $party->fresh();
}

/** @return list<int> 佇列 A 的等候順序 */
function queueOrder(): array
{
    return array_map(fn ($w) => $w->id, array_values(array_filter(
        app(FloorRepository::class)->waiting('2026-11-24'),
        fn ($w) => $w->queue->value === 'A',
    )));
}

it('does not expire a call at 2 minutes 59 seconds', function () {
    $party = Party::factory()->size(2)->create();
    $this->seating->call($party, [$this->table->id], $this->system);

    $this->travelTo('2026-11-24 18:02:59');

    expect($this->seating->expireNoShows($this->system))->toBe(0)
        ->and($party->fresh()->status)->toBe(PartyStatus::Called);
});

it('expires a call at exactly 3 minutes and releases the table', function () {
    $party = Party::factory()->size(2)->create();
    $this->seating->call($party, [$this->table->id], $this->system);

    $this->travelTo('2026-11-24 18:03:00');

    expect($this->seating->expireNoShows($this->system))->toBe(1)
        ->and($party->fresh()->status)->toBe(PartyStatus::NoShow)
        ->and($party->fresh()->no_show_count)->toBe(1)
        ->and($this->table->fresh()->held_for_party_id)->toBeNull();
});

it('requeues a no-show party at the third position', function () {
    $r = noShowParty($this);
    $w = collect(range(1, 4))->map(fn ($i) => Party::factory()->size(2)->create(['position_key' => 100 + $i]));

    $this->seating->requeue($r, $this->system);

    expect(queueOrder())->toBe([$w[0]->id, $w[1]->id, $r->id, $w[2]->id, $w[3]->id])
        ->and($r->fresh()->status)->toBe(PartyStatus::Waiting)
        ->and(app(FloorRepository::class)->aheadInQueue($r->fresh()))->toBe(2);
});

it('requeues right after the second party when exactly two are waiting', function () {
    $r = noShowParty($this);
    $w = collect(range(1, 2))->map(fn ($i) => Party::factory()->size(2)->create(['position_key' => 100 + $i]));

    $this->seating->requeue($r, $this->system);

    expect(queueOrder())->toBe([$w[0]->id, $w[1]->id, $r->id]);
});

it('requeues last when fewer than two are waiting', function () {
    $r = noShowParty($this);
    $w = Party::factory()->size(2)->create(['position_key' => 101]);

    $this->seating->requeue($r, $this->system);

    expect(queueOrder())->toBe([$w->id, $r->id]);
});

it('cannot requeue after the second no-show', function () {
    $r = noShowParty($this);
    $this->seating->requeue($r, $this->system);
    $this->seating->call($r->fresh(), [$this->table->id], $this->system);
    $this->travelTo(now()->addMinutes(3));
    $this->seating->expireNoShows($this->system);

    expect($r->fresh()->no_show_count)->toBe(2);
    expect(fn () => $this->seating->requeue($r->fresh(), $this->system))
        ->toThrow(RequeueNotAllowed::class, '每組只能重新排入一次');
});

it('rejects requeue when the phone already has another active ticket', function () {
    $r = noShowParty($this);
    Party::factory()->size(2)->create(['phone' => $r->phone]);

    expect(fn () => $this->seating->requeue($r, $this->system))->toThrow(DuplicateActivePhone::class);
});

it('requeues at the third position even when the others took tickets in the same millisecond', function () {
    $r = noShowParty($this);
    $tickets = app(TicketService::class);
    $w = collect(range(1, 4))->map(fn ($i) => $tickets->issue(2, "客{$i}", sprintf('09300000%02d', $i), Channel::Kiosk, $this->system));

    $this->seating->requeue($r, $this->system);

    expect(queueOrder())->toBe([$w[0]->id, $w[1]->id, $r->id, $w[2]->id, $w[3]->id]);
});
