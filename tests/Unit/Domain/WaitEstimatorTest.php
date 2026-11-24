<?php

use App\Domain\Estimation\ActiveSeating;
use App\Domain\Estimation\DiningStatsTable;
use App\Domain\Estimation\DurationStats;
use App\Domain\Estimation\EstimatorInput;
use App\Domain\Estimation\WaitEstimator;
use App\Domain\QueueLetter;
use App\Domain\Seating\OptionFinder;
use App\Domain\Seating\SeatingAllocator;
use App\Domain\Seating\SkipRule;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $finder = new OptionFinder(maxWaste: 2);
    $this->estimator = new WaitEstimator(new SeatingAllocator($finder, 3), new SkipRule($finder), cleaningMinutes: 5);
    $this->now = CarbonImmutable::parse('2026-11-24 18:00');
    // A 時段 1：P25 40 / P50 50 / P75 60；B 時段 1：60 / 70 / 80
    $this->stats = new DiningStatsTable(
        stats: ['A' => [1 => new DurationStats(40, 50, 60)], 'B' => [1 => new DurationStats(60, 70, 80)]],
        queueFallback: [],
        defaults: [
            'A' => new DurationStats(35, 50, 65), 'B' => new DurationStats(50, 65, 80),
            'C' => new DurationStats(65, 80, 95), 'D' => new DurationStats(80, 95, 110),
        ],
    );
});

it('matches the hand calculation for a fixed history', function () {
    /*
     * 桌況：T1（2 人）17:30 起由 A 組用餐；T2（4 人）17:20 起由 B 組用餐。清桌 5 分鐘，浪費上限 2。
     * 等候：W1（A，2 人）、W2（B，4 人）、W3（A，1 人），依序排隊。現在 18:00。
     *
     * 樂觀（P25）：
     *   T1 空出 17:30+40+5 = 18:15 → W1 入座，等 15 分；T1 再空出 18:15+40+5 = 19:00
     *   T2 空出 17:20+60+5 = 18:25 → W2 入座，等 25 分（W3 不能坐 T2：浪費 3 席）
     *   19:00 T1 空出 → W3 入座，等 60 分
     * 悲觀（P75）：
     *   T1 空出 17:30+60+5 = 18:35 → W1 等 35；T1 再空出 18:35+60+5 = 19:40
     *   T2 空出 17:20+80+5 = 18:45 → W2 等 45
     *   19:40 → W3 等 100
     * 區間：W1 [15,35]、W2 [25,45]、W3 [60,100]
     */
    $floor = makeFloor([['T1', 2, 'occupied'], ['T2', 4, 'occupied']]);
    $input = new EstimatorInput(
        now: $this->now,
        floor: $floor,
        seatings: [
            new ActiveSeating([tid($floor, 'T1')], QueueLetter::A, CarbonImmutable::parse('2026-11-24 17:30')),
            new ActiveSeating([tid($floor, 'T2')], QueueLetter::B, CarbonImmutable::parse('2026-11-24 17:20')),
        ],
        cleaningSince: [],
        called: [],
        waiting: [wp(1, 2, 1.0), wp(2, 4, 2.0), wp(3, 1, 3.0)],
    );

    $estimates = $this->estimator->estimate($input, $this->stats);

    expect([$estimates[1]->low, $estimates[1]->high])->toBe([15, 35])
        ->and([$estimates[2]->low, $estimates[2]->high])->toBe([25, 45])
        ->and([$estimates[3]->low, $estimates[3]->high])->toBe([60, 100]);
});

it('treats a table held for a called party as occupied from now', function () {
    // T4 已叫號保留給 B 組：樂觀 18:00+60+5 = 19:05（65 分）、悲觀 18:00+80+5 = 19:25（85 分）
    $floor = makeFloor([['T4', 4, 'available', 99, 'called']]);
    $input = new EstimatorInput($this->now, $floor, [], [], [['queue' => QueueLetter::B, 'tableIds' => [tid($floor, 'T4')]]], [wp(1, 4, 1.0)]);

    $estimate = $this->estimator->estimate($input, $this->stats)[1];

    expect([$estimate->low, $estimate->high])->toBe([65, 85]);
});

it('counts cleaning time from when cleaning started', function () {
    // 17:58 開始清桌，5 分鐘後 18:03 可用 → 等 3 分 → [0, 5]
    $floor = makeFloor([['T4', 4, 'cleaning']]);
    $input = new EstimatorInput($this->now, $floor, [], [tid($floor, 'T4') => CarbonImmutable::parse('2026-11-24 17:58')], [], [wp(1, 4, 1.0)]);

    $estimate = $this->estimator->estimate($input, $this->stats)[1];

    expect([$estimate->low, $estimate->high])->toBe([0, 5]);
});

it('returns a null estimate when no option can ever seat the party', function () {
    $floor = makeFloor([['T1', 2], ['T2', 4]]);
    $input = new EstimatorInput($this->now, $floor, [], [], [], [wp(1, 12, 1.0)]);

    $estimate = $this->estimator->estimate($input, $this->stats)[1];

    expect($estimate->low)->toBeNull()->and($estimate->high)->toBeNull();
});

it('widens a zero-width interval by five minutes', function () {
    $floor = makeFloor([['T4', 4]]);
    $input = new EstimatorInput($this->now, $floor, [], [], [], [wp(1, 4, 1.0)]);

    $estimate = $this->estimator->estimate($input, $this->stats)[1];

    expect([$estimate->low, $estimate->high])->toBe([0, 5]);
});
