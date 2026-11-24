<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SeatingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * 一次入座（可能是單桌或兩張併桌）。
 *
 * @property int $id
 * @property int $party_id
 * @property string $business_date
 * @property CarbonImmutable $seated_at
 * @property CarbonImmutable|null $left_at
 * @property int|null $user_id
 * @property int|null $device_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Party $party
 */
#[Fillable(['party_id', 'business_date', 'seated_at', 'left_at', 'user_id', 'device_id'])]
class Seating extends Model
{
    /** @use HasFactory<SeatingFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'seated_at' => 'immutable_datetime',
            'left_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /** @return BelongsToMany<DiningTable, $this> */
    public function tables(): BelongsToMany
    {
        return $this->belongsToMany(DiningTable::class, 'seating_tables', 'seating_id', 'table_id')
            ->withPivot('released_at');
    }
}
