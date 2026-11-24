<?php

namespace App\Models;

use App\Domain\Enums\ActorType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 操作紀錄：所有狀態變更的時間、操作者、平板。
 *
 * @property int $id
 * @property CarbonImmutable $occurred_at
 * @property ActorType $actor_type
 * @property int|null $user_id
 * @property int|null $device_id
 * @property string $action
 * @property string $subject_type
 * @property int $subject_id
 * @property string|null $from_status
 * @property string|null $to_status
 * @property array<string, mixed>|null $meta
 */
#[Fillable(['occurred_at', 'actor_type', 'user_id', 'device_id', 'action', 'subject_type', 'subject_id', 'from_status', 'to_status', 'meta'])]
class ActivityLog extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'actor_type' => ActorType::class,
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
