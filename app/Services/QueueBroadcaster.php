<?php

namespace App\Services;

/**
 * 交易提交後推播最新候位狀態（Task 12 實作）。
 */
class QueueBroadcaster
{
    public function afterCommit(string $businessDate): void
    {
        //
    }
}
