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

it('does not count a party that can still be seated right now at another table', function () {
    // P（2 位）排在前面，A2、B2 都空著：X 拿走 A2，P 還有 B2 可坐，P 沒有失去任何機會
    $floor = makeFloor([['A2', 2], ['B2', 2]]);
    $p = wp(1, 2, 1.0);
    $x = wp(2, 2, 2.0);

    expect($this->rule->skippedPartyIds([tid($floor, 'A2')], $x, [$p, $x], $floor))->toBe([]);
});

it('counts a party whose only remaining option was the table taken', function () {
    $floor = makeFloor([['A2', 2], ['B2', 2, 'occupied']]);
    $p = wp(1, 2, 1.0);
    $x = wp(2, 2, 2.0);

    expect($this->rule->skippedPartyIds([tid($floor, 'A2')], $x, [$p, $x], $floor))->toBe([1]);
});

it('does not treat tables held for someone else as an option for the party ahead', function () {
    // B2 已叫號保留給別人，P 實際上只剩 A2 可坐
    $floor = makeFloor([['A2', 2], ['B2', 2, 'available', 99, 'called']]);
    $p = wp(1, 2, 1.0);
    $x = wp(2, 2, 2.0);

    expect($this->rule->skippedPartyIds([tid($floor, 'A2')], $x, [$p, $x], $floor))->toBe([1]);
});
