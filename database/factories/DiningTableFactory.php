<?php

namespace Database\Factories;

use App\Domain\Enums\TableArea;
use App\Domain\Enums\TableStatus;
use App\Models\DiningTable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiningTable>
 */
class DiningTableFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'T'.fake()->unique()->numberBetween(100, 999),
            'capacity' => 4,
            'area' => TableArea::Indoor,
            'status' => TableStatus::Available,
        ];
    }
}
