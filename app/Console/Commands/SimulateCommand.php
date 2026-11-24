<?php

namespace App\Console\Commands;

use App\Domain\BusinessDay;
use App\Simulation\SimClock;
use App\Simulation\SimulationRunner;
use Carbon\Carbon;
use Database\Seeders\HistorySeeder;
use Database\Seeders\TableSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class SimulateCommand extends Command
{
    protected $signature = 'queuetable:simulate
        {--seed=42 : 亂數種子，相同種子結果完全相同}
        {--date= : 模擬的營業日（預設今天），歷史資料會產生在它之前的 30 天}
        {--until=03:00 : 最晚模擬到幾點（隔天凌晨以 00:00–11:59 表示）}
        {--json= : 另外把結果寫成 JSON 檔}';

    protected $description = '以加速時鐘模擬一個晚上的候位、叫號與帶位，輸出預估誤差、最長等候、跳號檢查與桌位使用率';

    public function handle(SimulationRunner $runner): int
    {
        $seed = (int) $this->option('seed');
        $date = (string) ($this->option('date') ?: BusinessDay::today());
        $until = SimClock::at($date, (string) $this->option('until'));

        $previous = ['database.default' => config('database.default'), 'broadcasting.default' => config('broadcasting.default')];

        try {
            $this->prepareDatabase($date);
            $this->info("模擬營業日 {$date}（seed {$seed}），虛擬時鐘每步 ".config('simulation.tick_seconds').' 秒…');
            $result = $runner->run($date, $seed, $until);
        } finally {
            Carbon::setTestNow();
            DB::purge('simulation');
            config($previous);
        }

        $this->table(['項目', '結果'], [
            ['取號 / 入座 / 過號 / 取消（組）', "{$result->issued} / {$result->seated} / {$result->noShows} / {$result->cancelled}"],
            ['預估平均絕對誤差（分）', $result->mae ?? '-'],
            ['實際等候落在預估區間內', $result->intervalHitRate === null ? '-' : round($result->intervalHitRate * 100, 1).'%'],
            ['平均等候（分）', $result->avgWait ?? '-'],
            ['最長等候（分）', $result->maxWait ?? '-'],
            ['各佇列最長等候（分）', collect($result->perQueueMaxWait)->map(fn ($m, $q) => "{$q} ".($m ?? '-'))->implode('、')],
            ['最多被跳過次數（稽核 / 系統紀錄）', "{$result->maxSkipAudited} / {$result->maxSkipStored}"],
            ['桌位使用率（17:00–02:00）', round($result->utilization * 100, 1).'%'],
            ['結束時仍在等候（組）', $result->unservedAtEnd],
            ['系統建議被拒絕', $this->counts($result->rejectedSuggestions)],
            ['重新排入被拒絕', $this->counts($result->rejectedRequeues)],
        ]);
        $this->newLine();
        $this->line('依人數分組（含取消的最長等候＝等到入座或放棄為止）：');
        $this->table(['人數', '取號', '入座', '取消', '取消率', '入座者平均等候（分）', '含取消的最長等候（分）'], collect($result->bySize)->map(fn (array $g, string $label) => [
            $label,
            $g['issued'],
            $g['seated'],
            $g['cancelled'],
            $g['cancel_rate'] === null ? '-' : round($g['cancel_rate'] * 100, 1).'%',
            $g['avg_wait_seated'] ?? '-',
            $g['max_wait_including_cancelled'] ?? '-',
        ])->values()->all());
        $this->line('跳號檢查：'.($result->skipCheckPassed ? 'PASS（沒有任何客人被跳過超過 3 次）' : 'FAIL'));
        $this->line('建議一致性：'.($result->suggestionsConsistent() ? 'PASS（每個系統建議都能成功叫號）' : 'FAIL'));

        if ($path = $this->option('json')) {
            File::put((string) $path, json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }

        return $result->skipCheckPassed && $result->suggestionsConsistent() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function counts(array $counts): string
    {
        return $counts === [] ? '0' : collect($counts)->map(fn (int $n, string $k) => "{$k} {$n} 次")->implode('、');
    }

    /**
     * 使用獨立的 simulation 連線：重建資料表、建立桌位與模擬日之前 30 天的歷史。
     */
    private function prepareDatabase(string $date): void
    {
        $path = (string) config('database.connections.simulation.database');
        if ($path !== ':memory:' && ! File::exists($path)) {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, '');
        }

        config(['database.default' => 'simulation', 'broadcasting.default' => 'null']);
        DB::purge('simulation');

        Artisan::call('migrate:fresh', ['--database' => 'simulation', '--force' => true]);

        Carbon::setTestNow(SimClock::at($date, '16:00'));
        (new TableSeeder)->run();
        app(HistorySeeder::class)->run();
    }
}
