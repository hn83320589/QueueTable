<?php

use App\Models\User;
use App\Services\Reports\ReportBuilder;
use App\Services\Reports\ReportCsvExporter;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/fixture.php';

beforeEach(fn () => seedReportFixture());

it('starts with a UTF-8 BOM so Excel shows Chinese correctly', function () {
    $csv = app(ReportCsvExporter::class)->export(app(ReportBuilder::class)->build('2026-11-20'));

    expect(substr($csv, 0, 3))->toBe("\xEF\xBB\xBF")
        ->and($csv)->toContain("\r\n");
});

it('writes every section with hand-checked numbers', function () {
    $csv = app(ReportCsvExporter::class)->export(app(ReportBuilder::class)->build('2026-11-20'));
    $rows = array_map(str_getcsv(...), explode("\r\n", trim(substr($csv, 3))));

    expect($rows)->toContain(['取號數', '5'])
        ->toContain(['入座數', '3'])
        ->toContain(['過號率', '20.0%'])
        ->toContain(['取消率', '20.0%'])
        ->toContain(['預估平均絕對誤差（分）', '6.7'])
        ->toContain(['佇列', '平均等候（分）', '最長等候（分）'])
        ->toContain(['A', '20.0', '30'])
        ->toContain(['C', '-', '-'])
        ->toContain(['桌號', '周轉次數'])
        ->toContain(['I01', '2'])
        ->toContain(['T01', '0'])
        ->toContain(['被跳過次數', '組數'])
        ->toContain(['3', '1']);
});

it('downloads the CSV for managers', function () {
    actingAsHost($this, User::factory()->manager()->create());

    $response = $this->get('/admin/reports/2026-11-20/csv')->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('text/csv; charset=UTF-8')
        ->and($response->headers->get('Content-Disposition'))->toContain('queuetable-report-2026-11-20.csv')
        ->and($response->getContent())->toStartWith("\xEF\xBB\xBF");
});

it('shows the report page for a chosen date', function () {
    actingAsHost($this, User::factory()->manager()->create());

    $this->get('/admin/reports?date=2026-11-20')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('admin/Report')
        ->where('date', '2026-11-20')
        ->where('report.issued', 5)
        ->where('report.mae', 6.7));
});

it('keeps hosts away from reports', function () {
    $this->actingAs(User::factory()->create())->get('/admin/reports/2026-11-20/csv')->assertForbidden();
});
