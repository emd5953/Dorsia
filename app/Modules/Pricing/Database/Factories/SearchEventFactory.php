<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Database\Factories;

use App\Modules\Pricing\Models\SearchEvent;
use App\Shared\Members\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SearchEvent>
 */
final class SearchEventFactory extends Factory
{
    protected $model = SearchEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'member_id' => Member::factory(),
            'venue_id' => 1,
            'requested_date' => '2026-11-06',
            'requested_hour' => 20,
            'requested_at' => '2026-11-07 01:00:00+00',
            'local_weekday' => 5,
            'party_size' => 2,
            'party_band' => '1-2',
            'had_availability' => false,
            'created_at' => now(),
        ];
    }
}
