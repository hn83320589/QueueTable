<?php

use App\Domain\Enums\Channel;
use App\Domain\Enums\PartyStatus;
use App\Models\Party;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->travelTo('2026-11-24 18:00'));

it('shows the take-a-ticket page to guests', function () {
    $this->get('/take')->assertOk()->assertInertia(fn (Assert $page) => $page->component('customer/Take')->where('maxPartySize', 12));
});

it('takes a ticket remotely and lands on its status page', function () {
    $response = $this->post('/take', ['size' => 3, 'name' => '陳先生', 'phone' => '0912-345-678']);

    $party = Party::sole();
    $response->assertRedirect("/q/{$party->token}");
    expect($party->channel)->toBe(Channel::Remote)->and($party->phone)->toBe('0912345678');

    $this->get("/q/{$party->token}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('customer/Status')
        ->where('party.ticket_code', 'B001')
        ->where('party.status', 'waiting')
        ->has('snapshot.queues.B'));
});

it('explains why a second ticket for the same phone is refused', function () {
    $this->post('/take', ['size' => 3, 'name' => '陳先生', 'phone' => '0912345678']);

    $this->from('/take')->post('/take', ['size' => 2, 'name' => '陳先生', 'phone' => '0912345678'])
        ->assertRedirect('/take')
        ->assertSessionHasErrors(['phone' => '此手機今天已有等候中的號碼 B001']);
    expect(Party::count())->toBe(1);
});

it('validates the take-a-ticket form', function () {
    $this->post('/take', ['size' => 0, 'name' => '', 'phone' => ''])->assertSessionHasErrors(['size', 'name', 'phone']);
});

it('lets the customer cancel from the status page', function () {
    $this->post('/take', ['size' => 2, 'name' => '林小姐', 'phone' => '0912345678']);
    $party = Party::sole();

    $this->post("/q/{$party->token}/cancel")->assertRedirect();

    expect($party->fresh()->status)->toBe(PartyStatus::Cancelled);
});

it('returns 404 for an unknown token', function () {
    $this->get('/q/00000000-0000-0000-0000-000000000000')->assertNotFound();
});
