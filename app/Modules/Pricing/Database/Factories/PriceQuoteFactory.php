<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Database\Factories;

use App\Modules\Pricing\Models\PriceQuote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceQuote>
 */
final class PriceQuoteFactory extends Factory
{
    protected $model = PriceQuote::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'hold_id' => fake()->unique()->numberBetween(1, 1_000_000),
            'slot_id' => 1,
            'venue_id' => 1,
            'party_size' => 2,
            'per_person_cents' => 37500,
            'amount_cents' => 75000,
            'currency' => 'USD',
            'inputs' => [],
            'expires_at' => now()->addMinutes(5),
            'created_at' => now(),
        ];
    }
}
