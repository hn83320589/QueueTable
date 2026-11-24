<?php

use App\Models\ActivityLog;
use App\Models\DiningTable;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->manager = User::factory()->manager()->create();
    $this->device = actingAsHost($this, $this->manager);
});

it('lists tables with their adjacency', function () {
    $a = DiningTable::factory()->create(['code' => 'I01']);
    $b = DiningTable::factory()->create(['code' => 'I02']);
    DiningTable::linkAdjacent($a->id, $b->id);

    $this->get('/admin/tables')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('admin/Tables')
        ->where('tables.0.code', 'I01')
        ->where('tables.0.adjacent_ids', [$b->id]));
});

it('creates a table and logs who did it', function () {
    $this->post('/admin/tables', ['code' => 'T09', 'capacity' => 6, 'area' => 'outdoor'])->assertRedirect();

    $table = DiningTable::where('code', 'T09')->sole();
    expect($table->capacity)->toBe(6)
        ->and(ActivityLog::where('action', 'table.created')->where('user_id', $this->manager->id)->where('device_id', $this->device->id)->exists())->toBeTrue();
});

it('rejects a duplicate table code', function () {
    DiningTable::factory()->create(['code' => 'I01']);

    $this->post('/admin/tables', ['code' => 'I01', 'capacity' => 4, 'area' => 'indoor'])->assertSessionHasErrors('code');
});

it('updates capacity and area', function () {
    $t = DiningTable::factory()->create(['code' => 'I01', 'capacity' => 4]);

    $this->put("/admin/tables/{$t->id}", ['code' => 'I01', 'capacity' => 6, 'area' => 'bar'])->assertRedirect();

    expect($t->fresh()->capacity)->toBe(6)->and($t->fresh()->area->value)->toBe('bar');
});

it('sets adjacency in both directions and replaces the old set', function () {
    [$a, $b, $c] = DiningTable::factory()->count(3)->create();
    DiningTable::linkAdjacent($a->id, $b->id);

    $this->put("/admin/tables/{$c->id}/adjacency", ['adjacent_ids' => [$a->id]])->assertRedirect();
    $this->put("/admin/tables/{$a->id}/adjacency", ['adjacent_ids' => [$c->id]])->assertRedirect();

    expect($a->adjacentIds())->toBe([$c->id])
        ->and($c->adjacentIds())->toBe([$a->id])
        ->and($b->adjacentIds())->toBe([]);
});

it('refuses to make a table adjacent to itself', function () {
    $a = DiningTable::factory()->create();

    $this->put("/admin/tables/{$a->id}/adjacency", ['adjacent_ids' => [$a->id]])->assertSessionHasErrors('adjacent_ids.0');
});

it('creates a host account that can log in', function () {
    $this->post('/admin/users', ['name' => '帶位小王', 'email' => 'host3@queuetable.test', 'password' => 'secret-123', 'role' => 'host'])->assertRedirect();
    auth()->logout();

    $this->post('/login', ['email' => 'host3@queuetable.test', 'password' => 'secret-123'])->assertRedirect('/host');
    expect(ActivityLog::where('action', 'user.created')->exists())->toBeTrue();
});

it('forbids hosts from the manager pages', function (string $method, string $url) {
    $host = User::factory()->create();

    $this->actingAs($host)->call($method, $url)->assertForbidden();
})->with([
    ['GET', '/admin/tables'],
    ['POST', '/admin/tables'],
    ['GET', '/admin/users'],
    ['POST', '/admin/users'],
]);
