<?php

namespace App\Services;

use App\Domain\BusinessDay;
use App\Domain\Enums\TableStatus;
use App\Exceptions\InvalidTransition;
use App\Models\DiningTable;
use App\Models\Party;
use App\Models\Seating;
use App\Support\ActorContext;
use Illuminate\Support\Facades\DB;

/**
 * 桌況操作：離開（轉清桌中）、清桌完成、停用、啟用。
 */
class TableService
{
    public function __construct(
        private readonly QueueStateRefresher $refresher,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * 客人離開：結束這張桌子所屬的入座紀錄，併桌的另一張也一起轉為清桌中。
     */
    public function leave(DiningTable $table, ActorContext $actor): Seating
    {
        return DB::transaction(function () use ($table, $actor): Seating {
            $table->refresh();
            if ($table->status !== TableStatus::Occupied) {
                throw new InvalidTransition("{$table->code} 目前為「{$table->status->label()}」，沒有客人可以離開");
            }

            $seating = Seating::query()
                ->whereNull('left_at')
                ->whereHas('tables', fn ($q) => $q->where('dining_tables.id', $table->id)->whereNull('seating_tables.released_at'))
                ->with('tables')
                ->first() ?? throw new InvalidTransition("找不到 {$table->code} 的入座紀錄");

            $seating->update(['left_at' => now()]);
            DB::table('seating_tables')->where('seating_id', $seating->id)->whereNull('released_at')->update(['released_at' => now()]);

            foreach ($seating->tables as $t) {
                $this->transition($t, TableStatus::Occupied, TableStatus::Cleaning, 'table.cleaning', $actor, ['seating_id' => $seating->id]);
            }
            $this->refresher->refresh($seating->business_date, $actor);

            return $seating;
        });
    }

    public function markCleaned(DiningTable $table, ActorContext $actor): DiningTable
    {
        return $this->simple($table, TableStatus::Cleaning, TableStatus::Available, 'table.available', $actor);
    }

    public function enable(DiningTable $table, ActorContext $actor): DiningTable
    {
        return $this->simple($table, TableStatus::Disabled, TableStatus::Available, 'table.enabled', $actor);
    }

    public function disable(DiningTable $table, ActorContext $actor): DiningTable
    {
        return DB::transaction(function () use ($table, $actor): DiningTable {
            $table->refresh();
            if (! in_array($table->status, [TableStatus::Available, TableStatus::Cleaning], true)) {
                throw new InvalidTransition("{$table->code} 目前為「{$table->status->label()}」，不能停用");
            }
            if ($table->held_for_party_id !== null) {
                $code = Party::query()->whereKey($table->held_for_party_id)->value('ticket_code');
                throw new InvalidTransition("{$table->code} 已保留給 {$code}，不能停用");
            }

            $this->transition($table, $table->status, TableStatus::Disabled, 'table.disabled', $actor);
            $this->refresher->refresh(BusinessDay::today(), $actor);

            return $table;
        });
    }

    private function simple(DiningTable $table, TableStatus $from, TableStatus $to, string $action, ActorContext $actor): DiningTable
    {
        return DB::transaction(function () use ($table, $from, $to, $action, $actor): DiningTable {
            $table->refresh();
            if ($table->status !== $from) {
                throw new InvalidTransition("{$table->code} 目前為「{$table->status->label()}」，無法改為「{$to->label()}」");
            }

            $this->transition($table, $from, $to, $action, $actor);
            $this->refresher->refresh(BusinessDay::today(), $actor);

            return $table;
        });
    }

    /**
     * 條件式更新：只有目前狀態仍為 $from 才會改，避免兩台平板同時操作同一張桌子。
     *
     * @param  array<string, mixed>  $meta
     */
    private function transition(DiningTable $table, TableStatus $from, TableStatus $to, string $action, ActorContext $actor, array $meta = []): void
    {
        $affected = DiningTable::query()
            ->whereKey($table->id)
            ->where('status', $from)
            ->update(['status' => $to, 'status_changed_at' => now()]);
        if ($affected !== 1) {
            throw new InvalidTransition("{$table->code} 的狀態剛被其他平板改變，請重新整理");
        }

        $table->refresh();
        $this->logger->record($actor, $action, $table, $from->value, $to->value, $meta);
    }
}
