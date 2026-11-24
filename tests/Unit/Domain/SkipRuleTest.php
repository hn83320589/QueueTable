<?php

use App\Domain\Seating\OptionFinder;
use App\Domain\Seating\SkipRule;

beforeEach(function () {
    $this->rule = new SkipRule(new OptionFinder(maxWaste: 2));
    // T4x–T6 相鄰可併 10 人；T2a 對 10 人組毫無用處
    $this->floor = makeFloor([['T4x', 4], ['T6', 6, 'occupied'], ['T2a', 2]], [['T4x', 'T6']]);
});

it('counts a party ahead whose relevant table was taken', function () {
    $p = wp(1, 10, 1.0);
    $x = wp(2, 4, 2.0);

    expect($this->rule->skippedPartyIds([tid($this->floor, 'T4x')], $x, [$p, $x], $this->floor))->toBe([1]);
});

it('does not count a party when the taken table is useless to it', function () {
    $p = wp(1, 10, 1.0);
    $x = wp(2, 2, 2.0);

    expect($this->rule->skippedPartyIds([tid($this->floor, 'T2a')], $x, [$p, $x], $this->floor))->toBe([]);
});

it('does not count parties behind the called party', function () {
    $x = wp(1, 4, 1.0);
    $p = wp(2, 10, 2.0);

    expect($this->rule->skippedPartyIds([tid($this->floor, 'T4x')], $x, [$x, $p], $this->floor))->toBe([]);
});

it('keeps counting a protected party so a fourth skip can be detected', function () {
    $p = wp(1, 10, 1.0, skip: 3);
    $x = wp(2, 4, 2.0);

    expect($this->rule->skippedPartyIds([tid($this->floor, 'T4x')], $x, [$p, $x], $this->floor))->toBe([1]);
});
