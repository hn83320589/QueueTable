<?php

use App\Domain\Enums\TableStatus;
use App\Models\DiningTable;
use App\Models\Party;
use App\Services\QueueStateRefresher;
use App\Services\SeatingService;
use App\Support\ActorContext;

/*
 * 不變式：系統給的每一個建議，帶位人員依序按「叫號」都必須成功。
 * 建議演算法（SeatingAllocator）與叫號時的檢查（SeatingService::call）若判斷不一致，
 * 平板上的建議卡就會按了失敗。
 */
beforeEach(function () {
    $this->travelTo('2026-11-24 18:00');
    $this->seating = app(SeatingService::class);
    $this->system = ActorContext::system();
    $this->table = fn (string $code, int $capacity, TableStatus $status = TableStatus::Available) => DiningTable::factory()->create(['code' => $code, 'capacity' => $capacity, 'status' => $status]);
});

function callEverySuggestion(object $test): array
{
    $called = [];
    foreach (app(QueueStateRefresher::class)->currentSuggestions('2026-11-24')->suggestions as $s) {
        $test->seating->call(Party::findOrFail($s->partyId), $s->option->tableIds, $test->system);
        $called[] = $s->partyId;
    }

    return $called;
}

it('accepts a combo suggested while a single table is held for a protected party', function () {
    // P（8 人）只能坐 A4+B4，B4 有人 → A4 保護保留給 P。X（4 人）只剩 C2a+C2b 併桌可坐。
    $a4 = ($this->table)('A4', 4);
    $b4 = ($this->table)('B4', 4, TableStatus::Occupied);
    $c2a = ($this->table)('C2a', 2);
    $c2b = ($this->table)('C2b', 2);
    DiningTable::linkAdjacent($a4->id, $b4->id);
    DiningTable::linkAdjacent($c2a->id, $c2b->id);
    $p = Party::factory()->size(8)->create(['position_key' => 1, 'skip_count' => 3]);
    $x = Party::factory()->size(4)->create(['position_key' => 2]);
    app(QueueStateRefresher::class)->refresh('2026-11-24', $this->system);

    expect(callEverySuggestion($this))->toBe([$x->id])
        ->and($a4->fresh()->held_for_party_id)->toBe($p->id);
});

it('never suggests two calls in one round that together skip someone a fourth time', function () {
    // Z（8 人，已被跳過 2 次）可坐 A4+B4 或 C4+D4，B4、D4 有人。A4、C4 都對 Z 有用。
    $a4 = ($this->table)('A4', 4);
    $b4 = ($this->table)('B4', 4, TableStatus::Occupied);
    $c4 = ($this->table)('C4', 4);
    $d4 = ($this->table)('D4', 4, TableStatus::Occupied);
    DiningTable::linkAdjacent($a4->id, $b4->id);
    DiningTable::linkAdjacent($c4->id, $d4->id);
    $z = Party::factory()->size(8)->create(['position_key' => 1, 'skip_count' => 2]);
    Party::factory()->size(4)->create(['position_key' => 2]);
    Party::factory()->size(4)->create(['position_key' => 3]);

    expect(callEverySuggestion($this))->toHaveCount(1)
        ->and($z->fresh()->skip_count)->toBe(3);
});

it('keeps every relevant free table for a protected party that is being suggested', function () {
    // P（2 人，保護中）建議到 A2；B2 也適合 P，在 P 被叫號前不能先給後面的人
    ($this->table)('A2', 2);
    ($this->table)('B2', 2);
    $p = Party::factory()->size(2)->create(['position_key' => 1, 'skip_count' => 3]);
    Party::factory()->size(2)->create(['position_key' => 2]);

    $suggestions = app(QueueStateRefresher::class)->currentSuggestions('2026-11-24')->suggestions;

    expect($suggestions)->toHaveCount(1)->and($suggestions[0]->partyId)->toBe($p->id);
});
