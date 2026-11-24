<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 通知紀錄（不實際發送簡訊）。
 *
 * @property int $id
 * @property int $party_id
 * @property string $type
 * @property string $message
 * @property CarbonImmutable $created_at
 */
#[Fillable(['party_id', 'type', 'message', 'created_at'])]
class NotificationLog extends Model
{
    public const COMING_SOON = 'coming_soon';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}
