<?php

use App\Domain\Seating\OptionFinder;
use App\Domain\Seating\SeatingAllocator;

beforeEach(fn () => $this->allocator = new SeatingAllocator(new OptionFinder(maxWaste: 2), maxSkips: 3));

it('suggests the earliest party that fits', function () {
    $floor = makeFloor([['T4', 4]]);

    $result = $this->allocator->allocate($floor, [wp(2, 4, 2.0), wp(1, 2, 1.0)]);

    expect(suggestionMap($result, $floor))->toBe([1 => 'T4']);
});

it('lets a later smaller party go first when the head has no fitting table', function () {
    $floor = makeFloor([['T2a', 2], ['T8', 8, 'occupied']]);

    $result = $this->allocator->allocate($floor, [wp(1, 8, 1.0), wp(2, 2, 2.0)]);

    expect(suggestionMap($result, $floor))->toBe([2 => 'T2a']);
});

it('orders parties across queues by position key then id', function () {
    $floor = makeFloor([['T4', 4]]);

    $result = $this->allocator->allocate($floor, [wp(7, 4, 5.0), wp(3, 3, 5.0)]);

    expect(suggestionMap($result, $floor))->toBe([3 => 'T4']);
});

it('can suggest several parties at once when several tables are free', function () {
    $floor = makeFloor([['T2a', 2], ['T4', 4]]);

    $result = $this->allocator->allocate($floor, [wp(1, 2, 1.0), wp(2, 4, 2.0)]);

    expect(suggestionMap($result, $floor))->toBe([1 => 'T2a', 2 => 'T4']);
});

it('excludes tables held for a called party', function () {
    $floor = makeFloor([['T4', 4, 'available', 99, 'called']]);

    $result = $this->allocator->allocate($floor, [wp(1, 4, 1.0)]);

    expect($result->suggestions)->toBe([]);
});

it('treats existing protected holds as recomputable', function () {
    $floor = makeFloor([['T4', 4, 'available', 99, 'protected']]);

    $result = $this->allocator->allocate($floor, [wp(1, 4, 1.0)]);

    expect(suggestionMap($result, $floor))->toBe([1 => 'T4']);
});

it('gives a protected party a fully free option before anyone else', function () {
    $floor = makeFloor([['T8', 8], ['T2a', 2]]);

    $result = $this->allocator->allocate($floor, [wp(1, 2, 1.0), wp(9, 8, 2.0, skip: 3)]);

    expect(suggestionMap($result, $floor))->toBe([9 => 'T8', 1 => 'T2a']);
});

it('holds every free relevant table for a protected party', function () {
    $floor = makeFloor([['T4x', 4], ['T6', 6, 'occupied'], ['T4', 4, 'occupied']], [['T4x', 'T6']]);

    $result = $this->allocator->allocate($floor, [wp(9, 10, 1.0, skip: 3), wp(2, 4, 2.0)]);

    expect($result->protectedHolds)->toBe([tid($floor, 'T4x') => 9])
        ->and($result->suggestions)->toBe([]);
});

it('lets others use tables a protected party can never use', function () {
    $floor = makeFloor([['T4x', 4, 'occupied'], ['T6', 6, 'occupied'], ['T2a', 2]], [['T4x', 'T6']]);

    $result = $this->allocator->allocate($floor, [wp(9, 10, 1.0, skip: 3), wp(2, 2, 2.0)]);

    expect(suggestionMap($result, $floor))->toBe([2 => 'T2a'])
        ->and($result->protectedHolds)->toBe([]);
});

it('gives contested tables to the earlier protected party', function () {
    $floor = makeFloor([['T4x', 4], ['T6', 6, 'occupied']], [['T4x', 'T6']]);

    $result = $this->allocator->allocate($floor, [wp(5, 9, 2.0, skip: 3), wp(4, 10, 1.0, skip: 3)]);

    expect($result->protectedHolds)->toBe([tid($floor, 'T4x') => 4]);
});
