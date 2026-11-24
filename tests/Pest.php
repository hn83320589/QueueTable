<?php

use App\Models\Device;
use App\Models\User;
use App\Support\ActorContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

require_once __DIR__.'/Helpers/floor.php';

/**
 * 以帶位人員身分（含平板 cookie）登入。
 */
function actingAsHost(TestCase $test, ?User $user = null): Device
{
    $user ??= User::factory()->create();
    $device = Device::factory()->create();
    $test->actingAs($user)->withCredentials()->withCookie(ActorContext::DEVICE_COOKIE, (string) $device->id);

    return $device;
}
