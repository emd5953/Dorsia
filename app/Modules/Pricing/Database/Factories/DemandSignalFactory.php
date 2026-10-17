<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Database\Factories;

use App\Modules\Pricing\Models\DemandSignal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DemandSignal>
 */
final class DemandSignalFactory extends Factory
{
    protected $model = DemandSignal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'venue_id' => 1,
            'weekday' => 6,
            'hour' => 20,
            'party_band' => '1-2',
            'sellout_seconds_p50' => null,
            'unmet_searches' => 0,
            'bid_count' => 0,
            'computed_at' => now(),
        ];
    }
}
