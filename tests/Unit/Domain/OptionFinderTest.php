<?php

use App\Domain\Enums\TableStatus;
use App\Domain\Seating\FloorSnapshot;
use App\Domain\Seating\OptionFinder;
use App\Domain\Seating\TableSnapshot;

/**
 * T2a(2)–T2b(2) 相鄰、T2c(2) 獨立、T4(4)、T6(6)、T8(8)、T4x(4)–T6 相鄰。
 *
 * @param  list<string>  $free  空著的桌號，其餘為 occupied
 */
function sampleFloor(array $free, array $disabled = []): FloorSnapshot
{
    $all = [['T2a', 2], ['T2b', 2], ['T2c', 2], ['T4', 4], ['T6', 6], ['T8', 8], ['T4x', 4]];

    return makeFloor(
        array_map(fn ($t) => [$t[0], $t[1], in_array($t[0], $disabled, true) ? 'disabled' : (in_array($t[0], $free, true) ? 'available' : 'occupied')], $all),
        [['T2a', 'T2b'], ['T4x', 'T6']],
    );
}

function isAvailable(): Closure
{
    return fn (TableSnapshot $t): bool => $t->status === TableStatus::Available;
}

beforeEach(fn () => $this->finder = new OptionFinder(maxWaste: 2));

it('picks the table with the least waste', function () {
    $floor = sampleFloor(['T4', 'T6']);

    $option = $this->finder->bestFreeOption(3, $floor, isAvailable());

    expect(codes($floor, $option->tableIds))->toBe(['T4'])
        ->and($option->waste)->toBe(1)
        ->and($option->combined)->toBeFalse();
});

it('prefers a single table over a combo even when the combo wastes less', function () {
    $floor = sampleFloor(['T2a', 'T2b', 'T6']);

    $option = $this->finder->bestFreeOption(4, $floor, isAvailable());

    expect(codes($floor, $option->tableIds))->toBe(['T6']);
});

it('suggests a combo only when no single table fits', function () {
    $floor = sampleFloor(['T2a', 'T2b']);

    $option = $this->finder->bestFreeOption(4, $floor, isAvailable());

    expect(codes($floor, $option->tableIds))->toBe(['T2a', 'T2b'])
        ->and($option->combined)->toBeTrue()
        ->and($option->capacity)->toBe(4)
        ->and($option->waste)->toBe(0);
});

it('never combines tables that are not adjacent', function () {
    $floor = sampleFloor(['T2a', 'T2c']);

    expect($this->finder->bestFreeOption(4, $floor, isAvailable()))->toBeNull();
});

it('rejects an option that wastes more than the limit', function () {
    $floor = sampleFloor(['T4']);

    expect($this->finder->bestFreeOption(1, $floor, isAvailable()))->toBeNull();
});

it('excludes disabled tables from every option', function () {
    $floor = sampleFloor(['T4', 'T4x'], disabled: ['T4']);

    $codes = array_map(fn ($o) => codes($floor, $o->tableIds), $this->finder->allOptions(4, $floor));

    expect($codes)->not->toContain(['T4']);
});

it('lists relevant tables as every table appearing in a suitable option', function () {
    $floor = sampleFloor([]);

    expect(codes($floor, $this->finder->relevantTableIds(10, $floor)))->toBe(['T6', 'T4x']);
});

it('orders all options single first, then by waste, then by code', function () {
    $floor = sampleFloor([]);

    $codes = array_map(fn ($o) => implode('+', codes($floor, $o->tableIds)), $this->finder->allOptions(4, $floor));

    expect($codes)->toBe(['T4', 'T4x', 'T6', 'T2a+T2b']);
});

it('rejects a combo choice when a free single option exists', function () {
    $floor = sampleFloor(['T2a', 'T2b', 'T4']);

    expect($this->finder->isValidChoice(4, [tid($floor, 'T2a'), tid($floor, 'T2b')], $floor, isAvailable()))->toBeFalse()
        ->and($this->finder->isValidChoice(4, [tid($floor, 'T4')], $floor, isAvailable()))->toBeTrue();
});

it('rejects a choice of non-adjacent or unsuitable tables', function () {
    $floor = sampleFloor(['T2a', 'T2c', 'T8']);

    expect($this->finder->isValidChoice(4, [tid($floor, 'T2a'), tid($floor, 'T2c')], $floor, isAvailable()))->toBeFalse()
        ->and($this->finder->isValidChoice(2, [tid($floor, 'T8')], $floor, isAvailable()))->toBeFalse();
});

it('rejects a choice that includes a table that is not free', function () {
    $floor = sampleFloor(['T2a']);

    expect($this->finder->isValidChoice(4, [tid($floor, 'T2a'), tid($floor, 'T2b')], $floor, isAvailable()))->toBeFalse();
});
