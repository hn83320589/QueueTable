<?php

namespace App\Services\Reports;

use App\Domain\Enums\PartyStatus;
use App\Domain\QueueLetter;
use App\Models\DiningTable;
use App\Models\Party;
use Illuminate\Support\Facades\DB;

class ReportBuilder
{
    public function build(string $businessDate): DailyReport
    {
        $parties = Party::query()->where('business_date', $businessDate)->get();
        $issued = $parties->count();
        $seated = $parties->filter(fn (Party $p): bool => $p->seated_at !== null);

        // 實際等候 = 入座時間 − 取號時間（分鐘）
        $waits = $seated->mapWithKeys(fn (Party $p): array => [
            $p->id => ($p->seated_at?->getTimestamp() - $p->issued_at->getTimestamp()) / 60,
        ]);

        $perQueue = [];
        foreach (QueueLetter::cases() as $queue) {
            $values = $seated->filter(fn (Party $p): bool => $p->queue === $queue)->map(fn (Party $p): float => $waits[$p->id]);
            $perQueue[$queue->value] = [
                'avg' => $values->isEmpty() ? null : round((float) $values->avg(), 1),
                'max' => $values->isEmpty() ? null : (int) round((float) $values->max()),
            ];
        }

        $estimated = $seated->filter(fn (Party $p): bool => $p->est_low_at_issue !== null && $p->est_high_at_issue !== null);
        $errors = $estimated->map(fn (Party $p): float => abs($waits[$p->id] - ($p->est_low_at_issue + $p->est_high_at_issue) / 2));
        $hits = $estimated->filter(fn (Party $p): bool => $waits[$p->id] >= $p->est_low_at_issue && $waits[$p->id] <= $p->est_high_at_issue);

        $skips = [0 => 0, 1 => 0, 2 => 0, 3 => 0];
        foreach ($parties as $party) {
            $skips[$party->skip_count] = ($skips[$party->skip_count] ?? 0) + 1;
        }
        ksort($skips);

        return new DailyReport(
            businessDate: $businessDate,
            issued: $issued,
            seated: $seated->count(),
            noShowRate: $this->rate($parties->filter(fn (Party $p): bool => $p->no_show_count > 0)->count(), $issued),
            cancelRate: $this->rate($parties->filter(fn (Party $p): bool => $p->status === PartyStatus::Cancelled)->count(), $issued),
            perQueue: $perQueue,
            mae: $errors->isEmpty() ? null : round((float) $errors->avg(), 1),
            intervalHitRate: $this->rate($hits->count(), $estimated->count()),
            turnover: $this->turnover($businessDate),
            skipDistribution: $skips,
        );
    }

    /**
     * 周轉次數：該營業日開始的入座筆數，併桌時兩張桌各算一次；沒有入座的桌子為 0。
     *
     * @return array<string, int>
     */
    private function turnover(string $businessDate): array
    {
        $counts = DB::table('seating_tables')
            ->join('seatings', 'seatings.id', '=', 'seating_tables.seating_id')
            ->where('seatings.business_date', $businessDate)
            ->groupBy('seating_tables.table_id')
            ->pluck(DB::raw('count(*)'), 'seating_tables.table_id');

        $turnover = [];
        foreach (DiningTable::query()->orderBy('code')->get(['id', 'code']) as $table) {
            $turnover[$table->code] = (int) ($counts[$table->id] ?? 0);
        }

        return $turnover;
    }

    private function rate(int $count, int $total): ?float
    {
        return $total === 0 ? null : round($count / $total, 3);
    }
}
