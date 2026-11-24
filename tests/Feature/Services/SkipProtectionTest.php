<?php

use App\Domain\Enums\HoldReason;
use App\Domain\Enums\TableStatus;
use App\Exceptions\SkipLimitExceeded;
use App\Models\DiningTable;
use App\Models\Party;
use App\Services\QueueStateRefresher;
use App\Services\SeatingService;
use App\Support\ActorContext;

/*
 * 情境：10 人組 P 最先排隊，只能坐 T4x+T6 併桌，但 T6 一直有人。
 * 後面的 4 人組 X1..X4 每次在 T4x 空出時被帶走，P 每次都被跳過。
 */
beforeEach(function () {
    $this->travelTo('2026-11-24 18:00');
    $this->seating = app(SeatingService::class);
    $this->system = ActorContext::system();
    $this->t4x = DiningTable::factory()->create(['code' => 'T4x', 'capacity' => 4]);
    $this->t6 = DiningTable::factory()->create(['code' => 'T6', 'capacity' => 6, 'status' => TableStatus::Occupied]);
    DiningTable::linkAdjacent($this->t4x->id, $this->t6->id);
    $this->p = Party::factory()->size(10)->create(['position_key' => 1]);
    $this->x = collect(range(1, 4))->map(fn ($i) => Party::factory()->size(4)->create(['position_key' => 1 + $i]));

    $this->skipViaT4x = function (int $i) {
        $this->seating->call($this->x[$i], [$this->t4x->id], $this->system);
        $this->seating->checkIn($this->x[$i], $this->system);
        freeTables([$this->t4x]);
    };
});

it('counts a skip each time a later party takes a table the party could use', function () {
    ($this->skipViaT4x)(0);
    expect($this->p->fresh()->skip_count)->toBe(1);

    ($this->skipViaT4x)(1);
    ($this->skipViaT4x)(2);
    expect($this->p->fresh()->skip_count)->toBe(3);
});

it('holds the next relevant table for a party skipped three times', function () {
    foreach ([0, 1, 2] as $i) {
        ($this->skipViaT4x)($i);
    }

    $suggestions = app(QueueStateRefresher::class)->currentSuggestions('2026-11-24')->suggestions;

    expect($this->t4x->fresh()->hold_reason)->toBe(HoldReason::Protected)
        ->and($this->t4x->fresh()->held_for_party_id)->toBe($this->p->id)
        ->and($suggestions)->toBe([]);
});

it('rejects a manual call that would skip a protected party a fourth time', function () {
    foreach ([0, 1, 2] as $i) {
        ($this->skipViaT4x)($i);
    }

    expect(fn () => $this->seating->call($this->x[3], [$this->t4x->id], $this->system))
        ->toThrow(SkipLimitExceeded::class);
    expect($this->p->fresh()->skip_count)->toBe(3);
});

it('seats the protected party as soon as its full option frees up', function () {
    foreach ([0, 1, 2] as $i) {
        ($this->skipViaT4x)($i);
    }

    freeTables([$this->t6]);
    $suggestions = app(QueueStateRefresher::class)->currentSuggestions('2026-11-24')->suggestions;

    expect($suggestions[0]->partyId)->toBe($this->p->id)
        ->and($suggestions[0]->option->tableIds)->toBe([$this->t4x->id, $this->t6->id]);

    $this->seating->call($this->p, [$this->t4x->id, $this->t6->id], $this->system);
    expect($this->t6->fresh()->held_for_party_id)->toBe($this->p->id);
});

it('protects a party of ten after three small parties went ahead of it', function () {
    // 題意：10 人組沒有桌可坐，被 3 組小桌客人越過後必須進入保護，下一張對他有用的桌子保留給他
    $t2 = DiningTable::factory()->create(['code' => 'T2', 'capacity' => 2]);
    $this->t4x->update(['status' => TableStatus::Occupied]);
    $small = collect(range(1, 4))->map(fn ($i) => Party::factory()->size(2)->create(['position_key' => 10 + $i]));

    foreach ([0, 1, 2] as $i) {
        $this->seating->call($small[$i], [$t2->id], $this->system);
        $this->seating->checkIn($small[$i], $this->system);
        freeTables([$t2]);
    }
    expect($this->p->fresh()->skip_count)->toBe(3);

    // 第 4 組小桌客人仍可坐 2 人桌（對 10 人組沒用），不會讓他變成第 4 次
    $this->seating->call($small[3], [$t2->id], $this->system);
    expect($this->p->fresh()->skip_count)->toBe(3);

    // T4x 空出時保留給 10 人組
    freeTables([$this->t4x]);
    expect($this->t4x->fresh()->held_for_party_id)->toBe($this->p->id)
        ->and($this->t4x->fresh()->hold_reason)->toBe(HoldReason::Protected);
});
