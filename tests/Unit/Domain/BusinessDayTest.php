<?php

use App\Domain\BusinessDay;
use Carbon\CarbonImmutable;

it('maps a time to its business date', function (string $at, string $expected) {
    expect(BusinessDay::for(CarbonImmutable::parse($at)))->toBe($expected);
})->with([
    'evening opening' => ['2026-11-24 17:00:00', '2026-11-24'],
    'after midnight belongs to previous day' => ['2026-11-25 01:59:00', '2026-11-24'],
    'just before cutoff' => ['2026-11-25 05:59:59', '2026-11-24'],
    'cutoff starts new day' => ['2026-11-25 06:00:00', '2026-11-25'],
]);

it('uses now() for today', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-25 01:30:00'));

    expect(BusinessDay::today())->toBe('2026-11-24');
});

it('returns the window of a business date', function () {
    [$start, $end] = BusinessDay::window('2026-11-24');

    expect($start->toDateTimeString())->toBe('2026-11-24 06:00:00')
        ->and($end->toDateTimeString())->toBe('2026-11-25 06:00:00');
});
