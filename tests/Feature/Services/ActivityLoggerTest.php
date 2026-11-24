<?php

use App\Domain\Enums\ActorType;
use App\Models\Device;
use App\Models\Party;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\ActorContext;

it('records the actor, device and time of a change', function () {
    $this->travelTo('2026-11-24 19:30:15');
    $user = User::factory()->create();
    $device = Device::factory()->create();
    $party = Party::factory()->create();

    $log = app(ActivityLogger::class)->record(ActorContext::staff($user, $device), 'party.called', $party, 'waiting', 'called', ['table_ids' => [1]]);

    expect($log->fresh())
        ->actor_type->toBe(ActorType::Staff)
        ->user_id->toBe($user->id)
        ->device_id->toBe($device->id)
        ->occurred_at->toDateTimeString()->toBe('2026-11-24 19:30:15')
        ->action->toBe('party.called')
        ->subject_type->toBe('party')
        ->subject_id->toBe($party->id)
        ->from_status->toBe('waiting')
        ->to_status->toBe('called')
        ->meta->toBe(['table_ids' => [1]]);
});

it('records system actions without user or device', function () {
    $log = app(ActivityLogger::class)->record(ActorContext::system(), 'party.no_show', Party::factory()->create(), 'called', 'no_show');

    expect($log->actor_type)->toBe(ActorType::System)
        ->and($log->user_id)->toBeNull()
        ->and($log->device_id)->toBeNull();
});
