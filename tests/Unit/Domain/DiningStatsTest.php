<?php

use App\Domain\Estimation\DiningStatsBuilder;
use App\Domain\Estimation\TimeSlot;
use App\Domain\QueueLetter;
use Carbon\CarbonImmutable;

function sample(QueueLetter $q, string $seatedAt, int $minutes): array
{
    return ['queue' => $q, 'seated_at' => CarbonImmutable::parse($seatedAt), 'minutes' => $minutes];
}

it('maps the seating hour to a time slot', function (string $at, int $slot) {
    expect(TimeSlot::of(CarbonImmutable::parse($at)))->toBe($slot);
})->with([
    ['2026-11-24 17:30', 1], ['2026-11-24 18:59', 1],
    ['2026-11-24 19:00', 2], ['2026-11-24 20:59', 2],
    ['2026-11-24 22:00', 3],
    ['2026-11-24 23:10', 4], ['2026-11-25 02:30', 4],
]);

it('uses nearest-rank percentiles', function () {
    $samples = array_map(fn ($m) => sample(QueueLetter::A, '2026-11-20 18:00', $m), [70, 30, 50, 40, 60]);

    $stats = DiningStatsBuilder::build($samples, minSamples: 5, defaultMinutes: ['A' => 50, 'B' => 65, 'C' => 80, 'D' => 95], spread: 15)
        ->for(QueueLetter::A, 1);

    expect([$stats->p25, $stats->p50, $stats->p75])->toBe([40, 50, 60]);
});

it('falls back to the whole queue when a slot has too few samples', function () {
    $samples = array_map(fn ($m) => sample(QueueLetter::A, '2026-11-20 18:00', $m), [30, 40, 50, 60, 70]);
    $samples[] = sample(QueueLetter::A, '2026-11-20 20:00', 80);
    $samples[] = sample(QueueLetter::A, '2026-11-20 20:00', 90);

    $stats = DiningStatsBuilder::build($samples, 5, ['A' => 50, 'B' => 65, 'C' => 80, 'D' => 95], 15)
        ->for(QueueLetter::A, 2);

    // 全部 7 筆排序 [30,40,50,60,70,80,90]：P25 第 2 筆、P50 第 4 筆、P75 第 6 筆
    expect([$stats->p25, $stats->p50, $stats->p75])->toBe([40, 60, 80]);
});

it('uses configured defaults when a queue has too few samples', function () {
    $stats = DiningStatsBuilder::build([], 5, ['A' => 50, 'B' => 65, 'C' => 80, 'D' => 95], 15)
        ->for(QueueLetter::A, 3);

    expect([$stats->p25, $stats->p50, $stats->p75])->toBe([35, 50, 65]);
});
