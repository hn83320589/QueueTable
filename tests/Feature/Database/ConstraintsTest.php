<?php

use App\Domain\Enums\PartyStatus;
use App\Models\DiningTable;
use App\Models\NotificationLog;
use App\Models\Party;
use App\Models\Seating;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('rejects duplicate ticket code in the same business date', function () {
    Party::factory()->create(['business_date' => '2026-11-24', 'ticket_code' => 'B001']);

    Party::factory()->create(['business_date' => '2026-11-24', 'ticket_code' => 'B001']);
})->throws(QueryException::class);

it('allows the same ticket code on another business date', function () {
    Party::factory()->create(['business_date' => '2026-11-24', 'ticket_code' => 'B001']);
    Party::factory()->create(['business_date' => '2026-11-25', 'ticket_code' => 'B001']);

    expect(Party::count())->toBe(2);
});

it('rejects a second active party with the same phone', function () {
    Party::factory()->create(['business_date' => '2026-11-24', 'phone' => '0912345678']);

    Party::factory()->create(['business_date' => '2026-11-24', 'phone' => '0912345678', 'status' => PartyStatus::Called]);
})->throws(QueryException::class);

it('allows the same phone again once the first party is no longer active', function () {
    $first = Party::factory()->create(['business_date' => '2026-11-24', 'phone' => '0912345678']);
    $first->update(['status' => PartyStatus::Cancelled]);

    Party::factory()->create(['business_date' => '2026-11-24', 'phone' => '0912345678']);

    expect(Party::where('phone', '0912345678')->count())->toBe(2);
});

it('rejects a table in two active seatings', function () {
    $table = DiningTable::factory()->create();
    $a = Seating::factory()->create();
    $b = Seating::factory()->create();

    DB::table('seating_tables')->insert(['seating_id' => $a->id, 'table_id' => $table->id]);
    DB::table('seating_tables')->insert(['seating_id' => $b->id, 'table_id' => $table->id]);
})->throws(QueryException::class);

it('allows a table in a new seating once the previous one is released', function () {
    $table = DiningTable::factory()->create();
    $a = Seating::factory()->create();
    $b = Seating::factory()->create();

    DB::table('seating_tables')->insert(['seating_id' => $a->id, 'table_id' => $table->id, 'released_at' => now()]);
    DB::table('seating_tables')->insert(['seating_id' => $b->id, 'table_id' => $table->id]);

    expect(DB::table('seating_tables')->count())->toBe(2);
});

it('rejects two seatings for one party', function () {
    $party = Party::factory()->create();

    Seating::factory()->create(['party_id' => $party->id]);
    Seating::factory()->create(['party_id' => $party->id]);
})->throws(QueryException::class);

it('rejects a duplicate notification type for one party', function () {
    $party = Party::factory()->create();

    NotificationLog::create(['party_id' => $party->id, 'type' => 'coming_soon', 'message' => 'x', 'created_at' => now()]);
    NotificationLog::create(['party_id' => $party->id, 'type' => 'coming_soon', 'message' => 'x', 'created_at' => now()]);
})->throws(QueryException::class);
