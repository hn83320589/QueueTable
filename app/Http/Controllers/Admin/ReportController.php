<?php

namespace App\Http\Controllers\Admin;

use App\Domain\BusinessDay;
use App\Http\Controllers\Controller;
use App\Services\Reports\ReportBuilder;
use App\Services\Reports\ReportCsvExporter;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function __construct(private readonly ReportBuilder $reports) {}

    public function show(Request $request): Response
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']], [], ['date' => '日期']);
        $date = (string) ($data['date'] ?? BusinessDay::today());

        return Inertia::render('admin/Report', [
            'date' => $date,
            'today' => BusinessDay::today(),
            'report' => $this->reports->build($date)->toArray(),
        ]);
    }

    public function csv(string $date, ReportCsvExporter $exporter): HttpResponse
    {
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1, 404);

        return response($exporter->export($this->reports->build($date)), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"queuetable-report-{$date}.csv\"",
        ]);
    }
}
