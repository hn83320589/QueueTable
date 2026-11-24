<?php

namespace Database\Seeders;

use App\Domain\Enums\TableArea;
use App\Domain\Enums\TableStatus;
use App\Models\DiningTable;
use Illuminate\Database\Seeder;

/**
 * 20 張桌、80 席（設計文件 §5.17）。
 * 搭配浪費上限 2：1–8 人有單桌，9–10 人用 I10+I11，11–12 人用 I11+I12。
 */
class TableSeeder extends Seeder
{
    public const LAYOUT = [
        ['I01', 2, 'indoor'], ['I02', 2, 'indoor'], ['I03', 2, 'indoor'], ['I04', 2, 'indoor'],
        ['I05', 4, 'indoor'], ['I06', 4, 'indoor'], ['I07', 4, 'indoor'], ['I08', 4, 'indoor'],
        ['I09', 4, 'indoor'], ['I10', 4, 'indoor'], ['I11', 6, 'indoor'], ['I12', 6, 'indoor'],
        ['T01', 4, 'outdoor'], ['T02', 4, 'outdoor'], ['T03', 4, 'outdoor'],
        ['T04', 8, 'outdoor'], ['T05', 8, 'outdoor'],
        ['B01', 2, 'bar'], ['B02', 2, 'bar'], ['B03', 4, 'bar'],
    ];

    public const ADJACENT = [
        ['I01', 'I02'], ['I03', 'I04'], ['I05', 'I06'], ['I07', 'I08'], ['I09', 'I10'],
        ['I10', 'I11'], ['I11', 'I12'], ['T01', 'T02'], ['T02', 'T03'], ['T04', 'T05'], ['B01', 'B02'],
    ];

    public function run(): void
    {
        foreach (self::LAYOUT as [$code, $capacity, $area]) {
            DiningTable::create([
                'code' => $code,
                'capacity' => $capacity,
                'area' => TableArea::from($area),
                'status' => TableStatus::Available,
                'status_changed_at' => now(),
            ]);
        }

        $ids = DiningTable::query()->pluck('id', 'code')->all();

        foreach (self::ADJACENT as [$a, $b]) {
            DiningTable::linkAdjacent($ids[$a], $ids[$b]);
        }
    }
}
