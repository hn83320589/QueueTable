<?php

use App\Services\Reports\ReportBuilder;

require_once __DIR__.'/fixture.php';

beforeEach(function () {
    seedReportFixture();
    $this->report = app(ReportBuilder::class)->build('2026-11-20');
});

it('counts issued and seated parties', function () {
    expect($this->report->issued)->toBe(5)
        ->and($this->report->seated)->toBe(3);
});

it('computes no-show and cancel rates over issued tickets', function () {
    // 過號 1/5、取消 1/5
    expect($this->report->noShowRate)->toBe(0.2)
        ->and($this->report->cancelRate)->toBe(0.2);
});

it('computes average and longest wait per queue', function () {
    // A：10、30 分 → 平均 20.0、最長 30；B：20 分；C、D 無人入座
    expect($this->report->perQueue)->toBe([
        'A' => ['avg' => 20.0, 'max' => 30],
        'B' => ['avg' => 20.0, 'max' => 20],
        'C' => ['avg' => null, 'max' => null],
        'D' => ['avg' => null, 'max' => null],
    ]);
});

it('computes the mean absolute error of the estimate made at issue time', function () {
    // |10 − 10| = 0、|30 − 15| = 15、|20 − 25| = 5 → (0 + 15 + 5) / 3 = 6.7
    expect($this->report->mae)->toBe(6.7);
});

it('computes how often the actual wait fell inside the estimate', function () {
    // A001 10 ∈ [5,15]、A002 30 ∉ [10,20]、B001 20 ∈ [20,30] → 2/3
    expect($this->report->intervalHitRate)->toBe(0.667);
});

it('counts table turnover including idle tables', function () {
    expect($this->report->turnover)->toBe(['I01' => 2, 'I05' => 1, 'T01' => 0]);
});

it('shows how many parties were skipped how many times', function () {
    expect($this->report->skipDistribution)->toBe([0 => 2, 1 => 1, 2 => 1, 3 => 1]);
});

it('returns an empty report for a day without parties', function () {
    $empty = app(ReportBuilder::class)->build('2026-11-01');

    expect($empty->issued)->toBe(0)
        ->and($empty->noShowRate)->toBeNull()
        ->and($empty->mae)->toBeNull()
        ->and($empty->skipDistribution)->toBe([0 => 0, 1 => 0, 2 => 0, 3 => 0]);
});
