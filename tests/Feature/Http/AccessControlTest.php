<?php

use App\Models\Device;
use App\Models\User;
use App\Support\ActorContext;

it('sends guests to the login page', function () {
    $this->get('/host')->assertRedirect('/login');
});

it('asks a signed-in host to pick a tablet first', function () {
    $this->actingAs(User::factory()->create())->get('/host')->assertRedirect('/device');
});

it('remembers the selected tablet in a cookie', function () {
    $device = Device::factory()->create();

    $this->actingAs(User::factory()->create())
        ->post('/device', ['device_id' => $device->id])
        ->assertRedirect('/host')
        ->assertCookie(ActorContext::DEVICE_COOKIE, (string) $device->id);
});

it('lets guests open the public pages', function (string $url) {
    $this->get($url)->assertOk();
})->with(['/', '/board', '/take']);

it('sends a host to the host board after login', function () {
    $user = User::factory()->create(['email' => 'host9@queuetable.test']);

    $this->post('/login', ['email' => 'host9@queuetable.test', 'password' => 'password'])->assertRedirect('/host');
});
