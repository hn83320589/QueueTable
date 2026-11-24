<?php

use App\Domain\Enums\Channel;
use App\Domain\Enums\PartyStatus;
use App\Domain\Enums\TableStatus;
use App\Models\ActivityLog;
use App\Models\DiningTable;
use App\Models\Party;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo('2026-11-24 18:00');
    $this->device = actingAsHost($this);
    $this->table = DiningTable::factory()->create(['code' => 'T1', 'capacity' => 4]);
});

it('runs a party through call, check-in, leave and clean', function () {
    $party = Party::factory()->size(4)->create();

    $this->from('/host')->post("/host/parties/{$party->id}/call", ['table_ids' => [$this->table->id]])->assertRedirect('/host')->assertSessionHasNoErrors();
    $this->from('/host')->post("/host/parties/{$party->id}/check-in")->assertRedirect('/host')->assertSessionHasNoErrors();
    $this->from('/host')->post("/host/tables/{$this->table->id}/leave")->assertRedirect('/host');
    $this->from('/host')->post("/host/tables/{$this->table->id}/clean")->assertRedirect('/host');

    expect($party->fresh()->status)->toBe(PartyStatus::Seated)
        ->and($this->table->fresh()->status)->toBe(TableStatus::Available)
        ->and(ActivityLog::whereNotNull('user_id')->where('device_id', $this->device->id)->count())->toBeGreaterThanOrEqual(5);
});

it('answers a duplicate check-in with a message instead of an error page', function () {
    $party = Party::factory()->size(4)->create();
    $this->post("/host/parties/{$party->id}/call", ['table_ids' => [$this->table->id]]);
    $this->post("/host/parties/{$party->id}/check-in");

    $this->from('/host')->post("/host/parties/{$party->id}/check-in")
        ->assertRedirect('/host')
        ->assertSessionHasErrors(['action' => "號碼 {$party->ticket_code} 目前為「已入座」，無法入座"]);
});

it('answers JSON clients with 422 for rule violations', function () {
    $party = Party::factory()->size(4)->create(['status' => PartyStatus::Seated]);

    $this->postJson("/host/parties/{$party->id}/cancel")->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, '無法取消'));
});

it('issues a kiosk ticket for a walk-in party', function () {
    $this->post('/host/parties', ['size' => 2, 'name' => '王先生', 'phone' => '0911222333'])->assertRedirect();

    expect(Party::sole()->channel)->toBe(Channel::Kiosk);
});

it('lists the valid table options for a party', function () {
    $party = Party::factory()->size(3)->create();

    $this->getJson("/host/parties/{$party->id}/options")
        ->assertOk()
        ->assertJsonPath('options.0.codes', ['T1'])
        ->assertJsonPath('options.0.waste', 1);
});

it('shows the host board with suggestions', function () {
    $party = Party::factory()->size(4)->create();

    $this->get('/host')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('host/Index')
        ->where('state.business_date', '2026-11-24')
        ->where('state.suggestions.0.ticket_code', $party->ticket_code)
        ->where('state.suggestions.0.table_codes', ['T1'])
        ->where('state.tables.0.code', 'T1'));
});

it('still shows the previous business day after midnight', function () {
    $party = Party::factory()->size(4)->create();
    $this->travelTo('2026-11-25 02:30');

    $this->get('/host')->assertInertia(fn (Assert $page) => $page
        ->where('state.business_date', '2026-11-24')
        ->where('state.parties.0.ticket_code', $party->ticket_code));
});

it('expires overdue calls before showing the host board', function () {
    $party = Party::factory()->size(4)->create();
    $this->post("/host/parties/{$party->id}/call", ['table_ids' => [$this->table->id]]);
    $this->travelTo('2026-11-24 18:03:30');

    $this->get('/host')->assertOk();

    expect($party->fresh()->status)->toBe(PartyStatus::NoShow);
});
