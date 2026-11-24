<?php

namespace App\Services\Reports;

/**
 * 匯出 CSV：UTF-8 加 BOM、CRLF 換行，Excel 直接開啟中文不會亂碼。
 */
class ReportCsvExporter
{
    public const BOM = "\xEF\xBB\xBF";

    public function export(DailyReport $report): string
    {
        $rows = [
            ['QueueTable 營業日報表', $report->businessDate],
            [],
            ['指標', '數值'],
            ['取號數', (string) $report->issued],
            ['入座數', (string) $report->seated],
            ['過號率', $this->percent($report->noShowRate)],
            ['取消率', $this->percent($report->cancelRate)],
            ['預估平均絕對誤差（分）', $report->mae === null ? '-' : number_format($report->mae, 1)],
            ['預估區間命中率', $this->percent($report->intervalHitRate)],
            [],
            ['佇列', '平均等候（分）', '最長等候（分）'],
        ];
        foreach ($report->perQueue as $queue => $wait) {
            $rows[] = [$queue, $wait['avg'] === null ? '-' : number_format($wait['avg'], 1), $wait['max'] === null ? '-' : (string) $wait['max']];
        }
        $rows[] = [];
        $rows[] = ['桌號', '周轉次數'];
        foreach ($report->turnover as $code => $count) {
            $rows[] = [$code, (string) $count];
        }
        $rows[] = [];
        $rows[] = ['被跳過次數', '組數'];
        foreach ($report->skipDistribution as $times => $count) {
            $rows[] = [(string) $times, (string) $count];
        }

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('無法建立 CSV 暫存檔');
        }
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '', "\r\n");
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return self::BOM.$csv;
    }

    private function percent(?float $rate): string
    {
        return $rate === null ? '-' : number_format($rate * 100, 1).'%';
    }
}
