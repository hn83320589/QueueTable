<?php

namespace Database\Factories;

use App\Domain\BusinessDay;
use App\Domain\Enums\PartyStatus;
use App\Models\Party;
use App\Models\Seating;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Seating>
 */
class SeatingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'party_id' => Party::factory()->state(['status' => PartyStatus::Seated]),
            'business_date' => BusinessDay::for(now()),
            'seated_at' => now(),
        ];
    }
}
