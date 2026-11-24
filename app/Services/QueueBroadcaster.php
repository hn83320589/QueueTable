<?php

namespace App\Services;

use App\Events\FloorUpdated;
use App\Events\QueueUpdated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 交易提交後推播最新候位狀態。
 *
 * - 交易 rollback 時不推播（afterCommit）。
 * - 同一個交易內呼叫多次只推一次。
 * - 推播失敗（例如 Reverb 沒啟動）只記 warning，不讓已完成的業務操作失敗。
 */
class QueueBroadcaster
{
    /** @var array<string, true> */
    private array $pending = [];

    public function __construct(private readonly QueueSnapshotBuilder $snapshots) {}

    public function afterCommit(string $businessDate): void
    {
        if (isset($this->pending[$businessDate])) {
            return;
        }
        $this->pending[$businessDate] = true;

        DB::afterCommit(function () use ($businessDate) {
            unset($this->pending[$businessDate]);
            $this->dispatch($businessDate);
        });
    }

    private function dispatch(string $businessDate): void
    {
        try {
            event(new QueueUpdated($this->snapshots->build($businessDate)));
            event(new FloorUpdated($businessDate));
        } catch (Throwable $e) {
            Log::warning('推播失敗，畫面需重新整理才會更新', [
                'business_date' => $businessDate,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
