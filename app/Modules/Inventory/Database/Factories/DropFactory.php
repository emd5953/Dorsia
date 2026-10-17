<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Database\Factories;

use App\Modules\Inventory\Models\Drop;
use App\Modules\Inventory\Models\Venue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Drop>
 */
final class DropFactory extends Factory
{
    protected $model = Drop::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'venue_id' => Venue::factory(),
            'opens_at' => now()->addDay(),
            'opened_at' => null,
            'sold_out_at' => null,
        ];
    }
}
