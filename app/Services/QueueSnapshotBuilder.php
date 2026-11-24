<?php

namespace App\Services;

use App\Domain\Enums\PartyStatus;
use App\Domain\QueueLetter;
use App\Models\Party;

/**
 * 公開的候位快照（看板、顧客頁、WebSocket 共用）。刻意不放姓名、手機、token。
 */
class QueueSnapshotBuilder
{
    /**
     * @return array{business_date: string, generated_at: string, queues: array<string, array{now_calling: string|null, waiting_count: int}>, parties: list<array{ticket_code: string, queue: string, status: string, ahead: int, est_low: int|null, est_high: int|null}>}
     */
    public function build(string $businessDate): array
    {
        $parties = Party::query()
            ->where('business_date', $businessDate)
            ->whereIn('status', [PartyStatus::Waiting, PartyStatus::Called])
            ->orderBy('position_key')->orderBy('id')
            ->get();

        $nowCalling = Party::query()
            ->where('business_date', $businessDate)
            ->whereNotNull('called_at')
            ->orderByDesc('called_at')->orderByDesc('id')
            ->get(['queue', 'ticket_code'])
            ->unique(fn (Party $p): string => $p->queue->value)
            ->mapWithKeys(fn (Party $p): array => [$p->queue->value => $p->ticket_code]);

        $queues = [];
        foreach (QueueLetter::cases() as $queue) {
            $queues[$queue->value] = [
                'now_calling' => $nowCalling[$queue->value] ?? null,
                'waiting_count' => $parties->filter(fn (Party $p): bool => $p->queue === $queue && $p->status === PartyStatus::Waiting)->count(),
            ];
        }

        $ahead = [];
        $rows = [];
        foreach ($parties as $party) {
            $isWaiting = $party->status === PartyStatus::Waiting;
            $count = $ahead[$party->queue->value] ?? 0;
            $rows[] = [
                'ticket_code' => $party->ticket_code,
                'queue' => $party->queue->value,
                'status' => $party->status->value,
                'ahead' => $isWaiting ? $count : 0,
                'est_low' => $isWaiting ? $party->est_low : null,
                'est_high' => $isWaiting ? $party->est_high : null,
            ];
            if ($isWaiting) {
                $ahead[$party->queue->value] = $count + 1;
            }
        }

        return [
            'business_date' => $businessDate,
            'generated_at' => now()->toIso8601String(),
            'queues' => $queues,
            'parties' => $rows,
        ];
    }
}
