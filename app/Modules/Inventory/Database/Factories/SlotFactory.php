<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Database\Factories;

use App\Modules\Inventory\Models\Slot;
use App\Modules\Inventory\Models\Venue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Slot>
 */
final class SlotFactory extends Factory
{
    protected $model = Slot::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'venue_id' => Venue::factory(),
            'drop_id' => null,
            'starts_at' => now()->addDays(3)->startOfHour(),
            'capacity' => 4,
            'held_count' => 0,
            'booked_count' => 0,
            'min_party_size' => 1,
            'max_party_size' => 4,
            'released_at' => now()->subHour(),
            'sold_out_at' => null,
        ];
    }
}
