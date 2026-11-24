<?php

namespace App\Models;

use App\Domain\Enums\Channel;
use App\Domain\Enums\PartyStatus;
use App\Domain\QueueLetter;
use Carbon\CarbonImmutable;
use Database\Factories\PartyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * 一組候位客人。
 *
 * @property int $id
 * @property string $business_date
 * @property QueueLetter $queue
 * @property int $number
 * @property string $ticket_code
 * @property int $size
 * @property string $name
 * @property string $phone
 * @property Channel $channel
 * @property string $token
 * @property PartyStatus $status
 * @property float $position_key
 * @property int $skip_count
 * @property int $no_show_count
 * @property int $requeue_count
 * @property CarbonImmutable $issued_at
 * @property CarbonImmutable|null $called_at
 * @property CarbonImmutable|null $seated_at
 * @property CarbonImmutable|null $cancelled_at
 * @property int|null $est_low_at_issue
 * @property int|null $est_high_at_issue
 * @property int|null $est_low
 * @property int|null $est_high
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Seating|null $seating
 */
#[Fillable([
    'business_date', 'queue', 'number', 'ticket_code', 'size', 'name', 'phone', 'channel', 'token',
    'status', 'position_key', 'skip_count', 'no_show_count', 'requeue_count',
    'issued_at', 'called_at', 'seated_at', 'cancelled_at',
    'est_low_at_issue', 'est_high_at_issue', 'est_low', 'est_high',
])]
class Party extends Model
{
    /** @use HasFactory<PartyFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'queue' => QueueLetter::class,
            'channel' => Channel::class,
            'status' => PartyStatus::class,
            'position_key' => 'float',
            'issued_at' => 'immutable_datetime',
            'called_at' => 'immutable_datetime',
            'seated_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /** @return HasOne<Seating, $this> */
    public function seating(): HasOne
    {
        return $this->hasOne(Seating::class);
    }

    /** @return HasMany<DiningTable, $this> */
    public function heldTables(): HasMany
    {
        return $this->hasMany(DiningTable::class, 'held_for_party_id');
    }
}
