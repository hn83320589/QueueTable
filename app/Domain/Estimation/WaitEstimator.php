<?php

namespace App\Domain\Estimation;

use App\Domain\Enums\HoldReason;
use App\Domain\Enums\TableStatus;
use App\Domain\QueueLetter;
use App\Domain\Seating\FloorSnapshot;
use App\Domain\Seating\SeatingAllocator;
use App\Domain\Seating\SkipRule;
use App\Domain\Seating\TableSnapshot;
use App\Domain\Seating\WaitingParty;
use Carbon\CarbonImmutable;

/**
 * 等候時間預估（設計文件 §5.11）：用帶位演算法本身把目前狀態「快轉」。
 *
 * 樂觀情境用歷史用餐時間 P25、悲觀情境用 P75，各前推一次得到每組的預計入座時間，
 * 區間 = [floor5(兩者較小), ceil5(兩者較大)]，寬度為 0 時上限 +5。
 */
final class WaitEstimator
{
    public function __construct(
        private readonly SeatingAllocator $allocator,
        private readonly SkipRule $skipRule,
        private readonly int $cleaningMinutes,
    ) {}

    /**
     * @return array<int, Estimate> partyId => 預估
     */
    public function estimate(EstimatorInput $input, DiningStatsTable $stats): array
    {
        $optimistic = $this->simulate($input, fn (DurationStats $d): int => $d->p25, $stats);
        $pessimistic = $this->simulate($input, fn (DurationStats $d): int => $d->p75, $stats);

        $estimates = [];
        foreach ($input->waiting as $party) {
            $a = $optimistic[$party->id] ?? null;
            $b = $pessimistic[$party->id] ?? null;
            if ($a === null || $b === null) {
                $estimates[$party->id] = new Estimate(null, null);

                continue;
            }
            $low = (int) (floor(min($a, $b) / 5) * 5);
            $high = (int) (ceil(max($a, $b) / 5) * 5);
            $estimates[$party->id] = new Estimate($low, $high > $low ? $high : $low + 5);
        }

        return $estimates;
    }

    /**
     * 前推一次，回傳每組的「剩餘等候分鐘」；無法入座的組不在結果中。
     *
     * @param  callable(DurationStats): int  $pick
     * @return array<int, float>
     */
    private function simulate(EstimatorInput $input, callable $pick, DiningStatsTable $stats): array
    {
        $now = $input->now;
        $dining = fn (QueueLetter $q, CarbonImmutable $at): int => $pick($stats->for($q, TimeSlot::of($at)));

        $freeAt = $this->initialFreeTimes($input, $dining);
        $remaining = WaitingParty::sortByOrder($input->waiting);
        $seatedAfter = [];
        $t = $now;

        while ($remaining !== []) {
            $floor = $this->floorAt($input->floor, $freeAt, $t);
            $result = $this->allocator->allocate($floor, $remaining, $t->getTimestamp());

            foreach ($result->suggestions as $suggestion) {
                $party = $this->find($remaining, $suggestion->partyId);
                $seatedAfter[$party->id] = ($t->getTimestamp() - $now->getTimestamp()) / 60;

                $skipped = $this->skipRule->skippedPartyIds($suggestion->option->tableIds, $party, $remaining, $floor, $t->getTimestamp());
                // 同一輪後面的建議計算跳過時，這組剛分到的桌子已經不是空的
                $floor = $floor->with(array_combine(
                    $suggestion->option->tableIds,
                    array_map(fn (int $id): TableSnapshot => $floor->table($id)->withStatus(TableStatus::Occupied), $suggestion->option->tableIds),
                ));
                $remaining = array_values(array_filter(
                    array_map(fn (WaitingParty $p) => in_array($p->id, $skipped, true) ? $p->withSkipCount($p->skipCount + 1) : $p, $remaining),
                    fn (WaitingParty $p) => $p->id !== $party->id,
                ));

                $until = $t->addMinutes($dining($party->queue, $t) + $this->cleaningMinutes);
                foreach ($suggestion->option->tableIds as $id) {
                    $freeAt[$id] = $until;
                }
            }

            $next = null;
            foreach ($freeAt as $at) {
                if ($at !== null && $at->greaterThan($t) && ($next === null || $at->lessThan($next))) {
                    $next = $at;
                }
            }
            if ($next === null) {
                break;
            }
            $t = $next;
        }

        return $seatedAfter;
    }

    /**
     * 每張桌子預計可用的時間；停用桌為 null（永遠不可用）。
     *
     * @param  callable(QueueLetter, CarbonImmutable): int  $dining
     * @return array<int, CarbonImmutable|null>
     */
    private function initialFreeTimes(EstimatorInput $input, callable $dining): array
    {
        $now = $input->now;
        $freeAt = [];

        $seatingOf = [];
        foreach ($input->seatings as $seating) {
            foreach ($seating->tableIds as $id) {
                $seatingOf[$id] = $seating;
            }
        }
        $calledOf = [];
        foreach ($input->called as $called) {
            foreach ($called['tableIds'] as $id) {
                $calledOf[$id] = $called['queue'];
            }
        }

        foreach ($input->floor->tables as $table) {
            $freeAt[$table->id] = match ($table->status) {
                TableStatus::Disabled => null,
                TableStatus::Occupied => isset($seatingOf[$table->id])
                    ? $this->later($now, $seatingOf[$table->id]->seatedAt->addMinutes($dining($seatingOf[$table->id]->queue, $seatingOf[$table->id]->seatedAt)))->addMinutes($this->cleaningMinutes)
                    : $now->addMinutes($this->cleaningMinutes),
                TableStatus::Cleaning => $this->later($now, ($input->cleaningSince[$table->id] ?? $now)->addMinutes($this->cleaningMinutes)),
                TableStatus::Available => $table->holdReason === HoldReason::Called
                    ? $now->addMinutes($dining($calledOf[$table->id] ?? QueueLetter::A, $now) + $this->cleaningMinutes)
                    : $now,
            };
        }

        return $freeAt;
    }

    /**
     * 虛擬時間 t 的桌況：已到可用時間者為 available（保留在虛擬世界重新計算），其餘為 occupied。
     *
     * @param  array<int, CarbonImmutable|null>  $freeAt
     */
    private function floorAt(FloorSnapshot $floor, array $freeAt, CarbonImmutable $t): FloorSnapshot
    {
        $tables = [];
        foreach ($floor->tables as $table) {
            $at = $freeAt[$table->id] ?? null;
            $status = match (true) {
                $table->status === TableStatus::Disabled => TableStatus::Disabled,
                $at !== null && $at->lessThanOrEqualTo($t) => TableStatus::Available,
                default => TableStatus::Occupied,
            };
            $tables[$table->id] = new TableSnapshot($table->id, $table->code, $table->capacity, $status);
        }

        return new FloorSnapshot($tables, $floor->adjacency);
    }

    /**
     * @param  list<WaitingParty>  $parties
     */
    private function find(array $parties, int $id): WaitingParty
    {
        foreach ($parties as $party) {
            if ($party->id === $id) {
                return $party;
            }
        }
        throw new \LogicException("等候組 {$id} 不在清單中");
    }

    private function later(CarbonImmutable $a, CarbonImmutable $b): CarbonImmutable
    {
        return $a->greaterThan($b) ? $a : $b;
    }
}
