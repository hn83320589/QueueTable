<?php

namespace App\Services;

use App\Domain\Seating\FloorSnapshot;
use App\Domain\Seating\TableSnapshot;
use App\Models\DiningTable;
use Illuminate\Support\Facades\DB;

/**
 * 從資料庫讀出 Domain 需要的快照（唯讀）。
 */
class FloorRepository
{
    public function floor(): FloorSnapshot
    {
        $tables = [];
        foreach (DiningTable::query()->orderBy('code')->get() as $table) {
            $tables[$table->id] = new TableSnapshot(
                $table->id,
                $table->code,
                $table->capacity,
                $table->status,
                $table->held_for_party_id,
                $table->hold_reason,
            );
        }

        $adjacency = DB::table('table_adjacencies')
            ->get(['table_a_id', 'table_b_id'])
            ->map(fn (object $row): array => [(int) $row->table_a_id, (int) $row->table_b_id])
            ->all();

        return new FloorSnapshot($tables, array_values($adjacency));
    }
}
