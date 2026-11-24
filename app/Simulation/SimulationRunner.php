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
                    $this->attempt(fn () => $this->seating->requeue(Party::findOrFail($partyId), $actor));
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

            // 7. 帶位人員依系統建議叫號
            foreach ($this->refresher->currentSuggestions($date)->suggestions as $suggestion) {
                $this->attempt(fn () => $this->seating->call(Party::findOrFail($suggestion->partyId), $suggestion->option->tableIds, $actor));
            }

            $active = Party::query()->where('business_date', $date)->whereIn('status', [PartyStatus::Waiting, PartyStatus::Called])->exists();
            if ($t->greaterThan($lastTicket) && $next >= count($arrivals) && ! $active && $leaveAt === []) {
                break;
            }
        }

        $result = $this->summarize($seed, $date, $config, $open, CarbonImmutable::instance(now()));
        Carbon::setTestNow();

        return $result;
    }

    /**
     * 業務規則拒絕（例如保護保留的桌子）是正常情況，略過即可；其他錯誤照常拋出。
     */
    private function attempt(callable $action): void
    {
        try {
            $action();
        } catch (QueueTableException) {
            // 建議被其他規則否決：下一步會重新計算
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
     */
    private function summarize(int $seed, string $date, array $config, CarbonImmutable $open, CarbonImmutable $end): SimulationResult
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
        );
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
