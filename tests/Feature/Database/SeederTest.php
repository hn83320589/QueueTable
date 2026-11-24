<?php

use App\Domain\BusinessDay;
use App\Domain\Seating\OptionFinder;
use App\Models\Device;
use App\Models\DiningTable;
use App\Models\Party;
use App\Models\User;
use App\Services\FloorRepository;
use Database\Seeders\HistorySeeder;
use Database\Seeders\StaffSeeder;
use Database\Seeders\TableSeeder;
use Illuminate\Support\Facades\DB;

it('creates 20 tables with 80 seats', function () {
    $this->seed(TableSeeder::class);

    expect(DiningTable::count())->toBe(20)
        ->and((int) DiningTable::sum('capacity'))->toBe(80);
});

it('offers at least one option for every party size from 1 to 12', function (int $size) {
    $this->seed(TableSeeder::class);

    $floor = app(FloorRepository::class)->floor();

    expect((new OptionFinder(maxWaste: 2))->allOptions($size, $floor))->not->toBeEmpty();
})->with(range(1, 12));

it('creates manager, hosts and two devices', function () {
    $this->seed(StaffSeeder::class);

    expect(User::where('role', 'manager')->pluck('email')->all())->toBe(['manager@queuetable.test'])
        ->and(User::where('role', 'host')->count())->toBe(2)
        ->and(Device::orderBy('name')->pluck('name')->all())->toBe(['平板 A', '平板 B']);
});

describe('history', function () {
    beforeEach(function () {
        $this->travelTo('2026-11-24 15:00');
        $this->seed([TableSeeder::class, HistorySeeder::class]);
    });

    it('creates 30 business days of history before today', function () {
        $dates = Party::distinct()->orderBy('business_date')->pluck('business_date');

        expect($dates)->toHaveCount(30)
            ->and($dates->last())->toBe('2026-11-23')
            ->and($dates->first())->toBe('2026-10-25');
    });

    it('creates between 100 and 150 parties per day', function () {
        $counts = Party::groupBy('business_date')->selectRaw('count(*) as c')->pluck('c');

        expect($counts->min())->toBeGreaterThanOrEqual(100)
            ->and($counts->max())->toBeLessThanOrEqual(150);
    });

    it('never overlaps seatings on the same table', function () {
        $overlaps = DB::selectOne('
            SELECT count(*) AS c
            FROM seating_tables x JOIN seatings a ON a.id = x.seating_id
            JOIN seating_tables y ON y.table_id = x.table_id AND y.seating_id > x.seating_id
            JOIN seatings b ON b.id = y.seating_id
            WHERE a.seated_at < b.left_at AND b.seated_at < a.left_at
        ');

        expect($overlaps->c)->toBe(0);
    });

    it('never seats more people than the tables hold', function () {
        $over = DB::selectOne('
            SELECT count(*) AS c FROM (
                SELECT p.size, sum(t.capacity) AS cap
                FROM seatings s JOIN parties p ON p.id = s.party_id
                JOIN seating_tables st ON st.seating_id = s.id JOIN dining_tables t ON t.id = st.table_id
                GROUP BY s.id
            ) WHERE size > cap
        ');

        expect($over->c)->toBe(0);
    });

    it('closes every historical seating', function () {
        expect(DB::table('seatings')->whereNull('left_at')->count())->toBe(0)
            ->and(DB::table('seating_tables')->whereNull('released_at')->count())->toBe(0)
            ->and(Party::whereIn('status', ['waiting', 'called'])->count())->toBe(0);
    });

    it('stores an estimate at issue time for seated parties', function () {
        expect(Party::where('status', 'seated')->whereNull('est_low_at_issue')->count())->toBe(0);
    });
});

it('keeps today free of history', function () {
    $this->travelTo('2026-11-24 15:00');
    $this->seed([TableSeeder::class, HistorySeeder::class]);

    expect(Party::where('business_date', BusinessDay::today())->count())->toBe(0);
});
