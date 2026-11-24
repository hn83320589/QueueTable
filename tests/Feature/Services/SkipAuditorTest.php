<?php

use App\Domain\Enums\TableStatus;
use App\Models\DiningTable;
use App\Models\Party;
use App\Services\SeatingService;
use App\Services\SkipAuditor;
use App\Support\ActorContext;

beforeEach(function () {
    $this->travelTo('2026-11-24 18:00');
    $this->seating = app(SeatingService::class);
    $this->system = ActorContext::system();
    $this->t4x = DiningTable::factory()->create(['code' => 'T4x', 'capacity' => 4]);
    $this->t6 = DiningTable::factory()->create(['code' => 'T6', 'capacity' => 6, 'status' => TableStatus::Occupied]);
    $this->t2 = DiningTable::factory()->create(['code' => 'T2', 'capacity' => 2]);
    DiningTable::linkAdjacent($this->t4x->id, $this->t6->id);
    $this->p = Party::factory()->size(10)->create(['position_key' => 1]);
});

it('recounts skips from the call log and agrees with the stored count', function () {
    foreach (range(1, 3) as $i) {
        $x = Party::factory()->size(4)->create(['position_key' => 1 + $i]);
        $this->seating->call($x, [$this->t4x->id], $this->system);
        $this->seating->checkIn($x, $this->system);
        freeTables([$this->t4x]);
    }

    $audit = app(SkipAuditor::class)->audit('2026-11-24');

    expect($audit[$this->p->id])->toBe(3)
        ->and($this->p->fresh()->skip_count)->toBe(3);
});

it('does not count calls that used tables the party could never use', function () {
    $x = Party::factory()->size(2)->create(['position_key' => 2]);
    $this->seating->call($x, [$this->t2->id], $this->system);

    expect(app(SkipAuditor::class)->audit('2026-11-24'))->not->toHaveKey($this->p->id);
});

it('ignores calls from other business days', function () {
    $x = Party::factory()->size(4)->create(['position_key' => 2]);
    $this->seating->call($x, [$this->t4x->id], $this->system);

    expect(app(SkipAuditor::class)->audit('2026-11-23'))->toBe([]);
});
