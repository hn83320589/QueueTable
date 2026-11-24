<?php

namespace App\Models;

use App\Domain\Enums\HoldReason;
use App\Domain\Enums\TableArea;
use App\Domain\Enums\TableStatus;
use Carbon\CarbonImmutable;
use Database\Factories\DiningTableFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * 桌子。保留（叫號保留、保護保留）以 held_for_party_id + hold_reason 表示，不是第五種狀態。
 *
 * @property int $id
 * @property string $code
 * @property int $capacity
 * @property TableArea $area
 * @property TableStatus $status
 * @property int|null $held_for_party_id
 * @property HoldReason|null $hold_reason
 * @property CarbonImmutable|null $status_changed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Party|null $heldFor
 */
#[Fillable(['code', 'capacity', 'area', 'status', 'held_for_party_id', 'hold_reason', 'status_changed_at'])]
class DiningTable extends Model
{
    /** @use HasFactory<DiningTableFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'area' => TableArea::class,
            'status' => TableStatus::class,
            'hold_reason' => HoldReason::class,
            'status_changed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Party, $this> */
    public function heldFor(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'held_for_party_id');
    }

    /**
     * 可以和這張桌子併桌的相鄰桌 id。
     *
     * @return list<int>
     */
    public function adjacentIds(): array
    {
        $ids = DB::table('table_adjacencies')
            ->where('table_a_id', $this->id)->pluck('table_b_id')
            ->merge(DB::table('table_adjacencies')->where('table_b_id', $this->id)->pluck('table_a_id'))
            ->map(fn ($id): int => (int) $id)
            ->sort()
            ->all();

        return array_values($ids);
    }

    /**
     * 設定相鄰關係（雙向，存成 a < b）。
     */
    public static function linkAdjacent(int $a, int $b): void
    {
        DB::table('table_adjacencies')->insertOrIgnore([
            'table_a_id' => min($a, $b),
            'table_b_id' => max($a, $b),
        ]);
    }
}
