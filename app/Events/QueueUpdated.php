<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * 公開頻道：看板與所有顧客頁面訂閱。payload 只有號碼、狀態、前面組數與預估，不含個資。
 */
class QueueUpdated implements ShouldBroadcastNow
{
    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function __construct(public readonly array $snapshot) {}

    public function broadcastOn(): Channel
    {
        return new Channel('queue');
    }

    public function broadcastAs(): string
    {
        return 'queue.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->snapshot;
    }
}
