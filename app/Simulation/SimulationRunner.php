<?php

namespace App\Simulation;

use App\Domain\DiningTimeModel;
use App\Domain\Enums\PartyStatus;
use App\Domain\Enums\TableStatus;
use App\Domain\Estimation\TimeSlot;
use App\Exceptions\QueueTableException;
use App\Models\DiningTable;
use App\Models\Party;
use App\Models\Seating;
use App\Services\QueueStateRefresher;
use App\Services\Reports\ReportBuilder;
use App\Services\SeatingService;
use App\Services\SkipAuditor;
use App\Services\TableService;
use App\Services\TicketService;
use App\Support\ActorContext;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * 以加速時鐘模擬一個晚上。每一步把虛擬時間往前推 tick_seconds 秒，
 * 所有動作都呼叫真正的 Application Service（和平板按按鈕走的是同一條路）。
 *
 * 每一步的順序：新客取號 → 叫號的客人報到 → 用餐結束離開 → 清桌完成
 *              → 過號檢查與重新排入 → 等不下去的客人取消 → 帶位人員依建議叫號
 */
final class SimulationRunner
{
    public function __construct(
        private readonly TicketService $tickets,
        private readonly SeatingService $seating,
        private readonly TableService $tables,
        private readonly QueueStateRefresher $refresher,
        private readonly ReportBuilder $reports,
        private readonly SkipAuditor $auditor,
        private readonly ArrivalGenerator $generator,
    ) {}

    public function run(string $date, int $seed, CarbonImmutable $until): SimulationResult
    {
        /** @var array<string, mixed> $config */
        $config = config('simulation');
        $rng = new Randomizer(new Mt19937($seed));
        $dining = new DiningTimeModel($rng);
        $arrivals = $this->generator->generate($rng, $date, $config);
        $actor = ActorContext::system();

        $open = SimClock::at($date, (string) $config['open']);
        $lastTicket = SimClock::at($date, (string) $config['last_ticket']);
        /** @var array{0: int, 1: int} $cleaning */
        $cleaning = $config['cleaning_minutes'];

        /** @var array<int, SimArrival> $behaviour */
        $behaviour = [];
        /** @var array<int, array{0: int, 1: CarbonImmutable}> $leaveAt partyId => [seatingId, 離開時間] */
        $leaveAt = [];
        /** @var array<int, CarbonImmutable> $cleanAt */
        $cleanAt = [];
        /** @var array<int, CarbonImmutable> $requeueAt */
        $requeueAt = [];
        /** @var array<string, int> $rejected 系統建議被叫號服務拒絕的次數（依例外類別） */
        $rejected = [];
        /** @var array<string, int> $requeueRejected */
        $requeueRejected = [];
        $next = 0;
        $t = $open;

        for (; $t->lessThanOrEqualTo($until); $t = $t->addSeconds((int) $config['tick_seconds'])) {
            Carbon::setTestNow($t);

            // 1. 新客取號
            while ($next < count($arrivals) && $arrivals[$next]->at->lessThanOrEqualTo($t)) {
                $a = $arrivals[$next++];
                $party = $this->tickets->issue($a->size, $a->name, $a->phone, $a->channel, $actor);
                $behaviour[$party->id] = $a;
            }

            // 2. 叫號的客人走到門口報到（第一次叫號時，部分客人不會出現）
            foreach ($this->parties($date, PartyStatus::Called) as $party) {
                $a = $behaviour[$party->id];
                if ($party->no_show_count === 0 && $a->willNoShow) {
                    continue;
                }
                if ($party->called_at !== null && $t->greaterThanOrEqualTo($party->called_at->addSeconds($a->checkInDelaySeconds))) {
                    $seating = $this->seating->checkIn($party, $actor);
                    $leaveAt[$party->id] = [$seating->id, $t->addMinutes($dining->sample($party->queue, TimeSlot::of($t)))];
                }
            }

            // 3. 用餐結束離開，桌子轉為清桌中
            foreach ($leaveAt as $partyId => [$seatingId, $at]) {
                if ($at->greaterThan($t)) {
                    continue;
                }
                $seating = Seating::with('tables')->findOrFail($seatingId);
                $first = $seating->tables->sortBy('code')->firstOrFail();
                foreach ($this->tables->leave($first, $actor)->tables as $table) {
                    $cleanAt[$table->id] = $t->addMinutes($rng->getInt($cleaning[0], $cleaning[1]));
                }
                unset($leaveAt[$partyId]);
            }

            // 4. 清桌完成
            foreach ($cleanAt as $tableId => $at) {
                if ($at->lessThanOrEqualTo($t)) {
                    $this->tables->markCleaned(DiningTable::findOrFail($tableId), $actor);
                    unset($cleanAt[$tableId]);
                }
            }

            // 5. 過號檢查；部分過號的客人幾分鐘後回來要求重新排入
            $this->seating->expireNoShows($actor);
            foreach ($this->parties($date, PartyStatus::NoShow) as $party) {
                $a = $behaviour[$party->id];
                if ($a->willRequeue && $party->requeue_count === 0 && ! isset($requeueAt[$party->id])) {
                    $requeueAt[$party->id] = $t->addMinutes($a->requeueDelayMinutes);
                }
            }
            foreach ($requeueAt as $partyId => $at) {
                if ($at->lessThanOrEqualTo($t)) {
                    $this->attempt(fn () => $this->seating->requeue(Party::findOrFail($partyId), $actor), $requeueRejected);
                    unset($requeueAt[$partyId]);
                }
            }

            // 6. 等太久或臨時有事的客人取消
            foreach ($this->parties($date, PartyStatus::Waiting) as $party) {
                $a = $behaviour[$party->id];
                $waited = ($t->getTimestamp() - $party->issued_at->getTimestamp()) / 60;
                if ($waited > $a->patienceMinutes || ($a->randomCancelAt !== null && $a->randomCancelAt->lessThanOrEqualTo($t))) {
                    $this->tickets->cancel($party, ActorContext::customer());
                }
            }

            // 7. 帶位人員依系統建議叫號：和平板一樣，每叫一組就重新取得建議
            //    （叫號會釋放或改變保留，舊的建議卡可能已經不是最佳選項）
            while (($suggestion = $this->refresher->currentSuggestions($date)->suggestions[0] ?? null) !== null) {
                $before = array_sum($rejected);
                $this->attempt(fn () => $this->seating->call(Party::findOrFail($suggestion->partyId), $suggestion->option->tableIds, $actor), $rejected);
                if (array_sum($rejected) > $before) {
                    break; // 建議被拒絕：已計數，下一步再試，避免無限重試
                }
            }

            $active = Party::query()->where('business_date', $date)->whereIn('status', [PartyStatus::Waiting, PartyStatus::Called])->exists();
            if ($t->greaterThan($lastTicket) && $next >= count($arrivals) && ! $active && $leaveAt === []) {
                break;
            }
        }

        ksort($rejected);
        ksort($requeueRejected);
        $result = $this->summarize($seed, $date, $config, $open, CarbonImmutable::instance(now()), $rejected, $requeueRejected);
        Carbon::setTestNow();

        return $result;
    }

    /**
     * 執行一個可能被業務規則拒絕的動作，依例外類別計數（不吞掉：結果會輸出並影響 PASS/FAIL）。
     * 其他例外照常拋出。
     *
     * @param  array<string, int>  $rejected
     */
    private function attempt(callable $action, array &$rejected): void
    {
        try {
            $action();
        } catch (QueueTableException $e) {
            $key = class_basename($e);
            $rejected[$key] = ($rejected[$key] ?? 0) + 1;
        }
    }

    /**
     * @return Collection<int, Party>
     */
    private function parties(string $date, PartyStatus $status)
    {
        return Party::query()->where('business_date', $date)->where('status', $status)->orderBy('id')->get();
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, int>  $rejected
     * @param  array<string, int>  $requeueRejected
     */
    private function summarize(int $seed, string $date, array $config, CarbonImmutable $open, CarbonImmutable $end, array $rejected, array $requeueRejected): SimulationResult
    {
        $report = $this->reports->build($date);
        $parties = Party::query()->where('business_date', $date)->get();
        $seated = $parties->filter(fn (Party $p): bool => $p->seated_at !== null);
        $waits = $seated->map(fn (Party $p): float => (($p->seated_at?->getTimestamp() ?? 0) - $p->issued_at->getTimestamp()) / 60);

        $audit = $this->auditor->audit($date);
        $maxAudited = $audit === [] ? 0 : max($audit);
        $maxStored = (int) ($parties->max('skip_count') ?? 0);

        $perQueueMax = [];
        foreach ($report->perQueue as $queue => $wait) {
            $perQueueMax[$queue] = $wait['max'];
        }
        $maxes = array_filter($perQueueMax, fn (?int $m): bool => $m !== null);

        return new SimulationResult(
            seed: $seed,
            businessDate: $date,
            issued: $report->issued,
            seated: $report->seated,
            noShows: $parties->filter(fn (Party $p): bool => $p->no_show_count > 0)->count(),
            cancelled: $parties->filter(fn (Party $p): bool => $p->status === PartyStatus::Cancelled)->count(),
            mae: $report->mae,
            intervalHitRate: $report->intervalHitRate,
            avgWait: $waits->isEmpty() ? null : round((float) $waits->avg(), 1),
            maxWait: $maxes === [] ? null : max($maxes),
            perQueueMaxWait: $perQueueMax,
            maxSkipAudited: $maxAudited,
            maxSkipStored: $maxStored,
            skipCheckPassed: $maxAudited <= (int) config('queuetable.max_skips') && $maxStored <= (int) config('queuetable.max_skips') && $maxAudited === $maxStored,
            utilization: $this->utilization($date, $open, SimClock::at($date, (string) $config['close']), $end),
            unservedAtEnd: $parties->filter(fn (Party $p): bool => $p->status->isActive())->count(),
            rejectedSuggestions: $rejected,
            rejectedRequeues: $requeueRejected,
            bySize: $this->bySize($parties, $end),
        );
    }

    /**
     * 依人數分組的取號、入座、取消與等候。
     * 「含取消的最長等候」把等不下去而取消的組也算進來（等到取消為止），
     * 避免只看入座者時，大組客人等到放棄的情況被藏起來。
     *
     * @param  \Illuminate\Support\Collection<int, Party>  $parties
     * @return array<string, array{issued: int, seated: int, cancelled: int, cancel_rate: float|null, avg_wait_seated: float|null, max_wait_including_cancelled: int|null}>
     */
    private function bySize($parties, CarbonImmutable $end): array
    {
        $buckets = ['1–2' => [1, 2], '3–4' => [3, 4], '5–6' => [5, 6], '7–8' => [7, 8], '9–12' => [9, 12]];
        $result = [];

        foreach ($buckets as $label => [$min, $max]) {
            $group = $parties->filter(fn (Party $p): bool => $p->size >= $min && $p->size <= $max);
            $seated = $group->filter(fn (Party $p): bool => $p->seated_at !== null);
            $cancelled = $group->filter(fn (Party $p): bool => $p->status === PartyStatus::Cancelled)->count();
            $waits = $group->map(function (Party $p) use ($end): float {
                $until = $p->seated_at ?? $p->cancelled_at ?? $p->called_at ?? $end;

                return ($until->getTimestamp() - $p->issued_at->getTimestamp()) / 60;
            });
            $seatedWaits = $seated->map(fn (Party $p): float => (($p->seated_at?->getTimestamp() ?? 0) - $p->issued_at->getTimestamp()) / 60);

            $result[$label] = [
                'issued' => $group->count(),
                'seated' => $seated->count(),
                'cancelled' => $cancelled,
                'cancel_rate' => $group->isEmpty() ? null : round($cancelled / $group->count(), 3),
                'avg_wait_seated' => $seatedWaits->isEmpty() ? null : round((float) $seatedWaits->avg(), 1),
                'max_wait_including_cancelled' => $waits->isEmpty() ? null : (int) round((float) $waits->max()),
            ];
        }

        return $result;
    }

    /**
     * 桌位使用率 = 用餐中的「桌 × 分鐘」÷ 營業時間內可用的「桌 × 分鐘」（停用桌不算）。
     */
    private function utilization(string $date, CarbonImmutable $open, CarbonImmutable $close, CarbonImmutable $end): float
    {
        $busy = 0.0;
        foreach (Seating::query()->where('business_date', $date)->with('tables')->get() as $seating) {
            $from = max($seating->seated_at->getTimestamp(), $open->getTimestamp());
            $to = min(($seating->left_at ?? $end)->getTimestamp(), $close->getTimestamp());
            if ($to > $from) {
                $busy += ($to - $from) / 60 * $seating->tables->count();
            }
        }

        $tables = DiningTable::query()->where('status', '!=', TableStatus::Disabled)->count();
        $available = $tables * ($close->getTimestamp() - $open->getTimestamp()) / 60;

        return $available > 0 ? round($busy / $available, 3) : 0.0;
    }
}
