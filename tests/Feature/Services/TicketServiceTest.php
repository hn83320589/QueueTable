<?php

use App\Domain\Enums\Channel;
use App\Domain\Enums\PartyStatus;
use App\Exceptions\DuplicateActivePhone;
use App\Exceptions\InvalidTransition;
use App\Exceptions\PartySizeOutOfRange;
use App\Models\ActivityLog;
use App\Models\Device;
use App\Models\DiningTable;
use App\Models\User;
use App\Services\TicketService;
use App\Support\ActorContext;

beforeEach(function () {
    $this->travelTo('2026-11-24 18:00');
    $this->tickets = app(TicketService::class);
    $this->issue = fn (int $size, string $phone, string $name = '陳先生') => $this->tickets->issue($size, $name, $phone, Channel::Remote, ActorContext::customer());
});

it('formats the ticket as the queue letter plus three digits', function () {
    $party = ($this->issue)(3, '0912000001');

    expect($party->ticket_code)->toBe('B001')
        ->and($party->business_date)->toBe('2026-11-24')
        ->and($party->status)->toBe(PartyStatus::Waiting)
        ->and($party->token)->toHaveLength(36);
});

it('numbers tickets sequentially without gaps per queue', function () {
    $codes = collect([[3, '0912000001'], [4, '0912000002'], [2, '0912000003'], [3, '0912000004']])
        ->map(fn ($a) => ($this->issue)(...$a)->ticket_code)->all();

    expect($codes)->toBe(['B001', 'B002', 'A001', 'B003']);
});

it('does not consume a number when issuing fails', function () {
    ($this->issue)(3, '0912000001');

    expect(fn () => ($this->issue)(3, '0912000001'))->toThrow(DuplicateActivePhone::class);

    expect(($this->issue)(3, '0912000002')->ticket_code)->toBe('B002');
});

it('restarts numbering on the next business day', function () {
    $this->travelTo('2026-11-25 01:59');
    $late = ($this->issue)(2, '0912000001');

    $this->travelTo('2026-11-25 17:00');
    $next = ($this->issue)(2, '0912000002');

    expect($late->business_date)->toBe('2026-11-24')
        ->and($next->business_date)->toBe('2026-11-25')
        ->and($next->ticket_code)->toBe('A001');
});

it('rejects a second active ticket for the same phone and says why', function () {
    ($this->issue)(3, '0912000001');

    expect(fn () => ($this->issue)(2, '0912000001'))
        ->toThrow(DuplicateActivePhone::class, '此手機今天已有等候中的號碼 B001');
});

it('treats differently formatted phones as the same phone', function () {
    ($this->issue)(2, '0912-345-678');

    expect(fn () => ($this->issue)(2, '+886912345678'))->toThrow(DuplicateActivePhone::class);
});

it('allows a new ticket after cancelling', function () {
    $party = ($this->issue)(2, '0912000001');
    $this->tickets->cancel($party, ActorContext::customer());

    expect(($this->issue)(2, '0912000001')->ticket_code)->toBe('A002');
});

it('allows the same phone again on a new business day', function () {
    ($this->issue)(2, '0912000001');
    $this->travelTo('2026-11-25 18:00');

    expect(($this->issue)(2, '0912000001')->business_date)->toBe('2026-11-25');
});

it('rejects party sizes out of range', function (int $size) {
    ($this->issue)($size, '0912000001');
})->with([0, 13])->throws(PartySizeOutOfRange::class);

it('stores the estimate made at issue time', function () {
    DiningTable::factory()->create(['capacity' => 4]);

    $party = ($this->issue)(4, '0912000001');

    expect([$party->est_low_at_issue, $party->est_high_at_issue])->toBe([0, 5])
        ->and([$party->est_low, $party->est_high])->toBe([0, 5]);
});

it('cannot cancel a seated party', function () {
    $party = ($this->issue)(2, '0912000001');
    $party->update(['status' => PartyStatus::Seated]);

    $this->tickets->cancel($party, ActorContext::customer());
})->throws(InvalidTransition::class);

it('logs issue and cancel with the actor', function () {
    $user = User::factory()->create();
    $device = Device::factory()->create();
    $party = $this->tickets->issue(2, '林小姐', '0912000001', Channel::Kiosk, ActorContext::staff($user, $device));
    $this->tickets->cancel($party, ActorContext::customer());

    $logs = ActivityLog::where('subject_type', 'party')->where('subject_id', $party->id)->orderBy('id')->get();

    expect($logs->pluck('action')->all())->toBe(['party.issued', 'party.cancelled'])
        ->and($logs[0]->user_id)->toBe($user->id)
        ->and($logs[0]->device_id)->toBe($device->id)
        ->and($logs[1]->actor_type->value)->toBe('customer')
        ->and($logs[1]->from_status)->toBe('waiting')
        ->and($logs[1]->to_status)->toBe('cancelled');
});
