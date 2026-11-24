<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Support\ActorContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * 所有狀態變更都經過這裡留下紀錄：時間、操作者、平板、從什麼狀態變成什麼狀態。
 */
class ActivityLogger
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function record(ActorContext $actor, string $action, Model $subject, ?string $from, ?string $to, array $meta = []): ActivityLog
    {
        return ActivityLog::create([
            'occurred_at' => now(),
            'actor_type' => $actor->type,
            'user_id' => $actor->userId,
            'device_id' => $actor->deviceId,
            'action' => $action,
            'subject_type' => Str::snake(class_basename($subject)),
            'subject_id' => (int) $subject->getKey(),
            'from_status' => $from,
            'to_status' => $to,
            'meta' => $meta === [] ? null : $meta,
        ]);
    }
}
