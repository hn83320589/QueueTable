<?php

namespace Database\Factories;

use App\Domain\BusinessDay;
use App\Domain\Enums\Channel;
use App\Domain\Enums\PartyStatus;
use App\Domain\QueueLetter;
use App\Models\Party;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Party>
 */
class PartyFactory extends Factory
{
    private static int $sequence = 0;

    public function definition(): array
    {
        $number = ++self::$sequence;
        $issuedAt = now();

        return [
            'business_date' => BusinessDay::for($issuedAt),
            'queue' => QueueLetter::A,
            'number' => $number,
            'ticket_code' => sprintf('A%03d', $number % 1000),
            'size' => 2,
            'name' => fake()->lastName().'先生',
            'phone' => '09'.str_pad((string) fake()->unique()->numberBetween(0, 99999999), 8, '0', STR_PAD_LEFT),
            'channel' => Channel::Remote,
            'token' => (string) Str::uuid(),
            'status' => PartyStatus::Waiting,
            'position_key' => $issuedAt->getTimestamp() + $number / 1000,
            'issued_at' => $issuedAt,
        ];
    }

    /**
     * 依人數設定 size 與佇列。
     */
    public function size(int $size): static
    {
        return $this->state(fn (array $attributes) => [
            'size' => $size,
            'queue' => QueueLetter::fromPartySize($size),
        ]);
    }
}
