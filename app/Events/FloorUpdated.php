<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * 私有頻道：只通知帶位平板「有變動」，平板再用已登入的 HTTP 重新取得含姓名的資料。
 */
class FloorUpdated implements ShouldBroadcastNow
{
    public function __construct(public readonly string $businessDate) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('staff');
    }

    public function broadcastAs(): string
    {
        return 'floor.updated';
    }

    /**
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return ['business_date' => $this->businessDate, 'at' => now()->toIso8601String()];
    }
}
