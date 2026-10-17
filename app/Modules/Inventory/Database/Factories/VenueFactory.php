<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Database\Factories;

use App\Modules\Inventory\Models\City;
use App\Modules\Inventory\Models\Venue;
use DateTimeZone;
use Illuminate\Database\Eloquent\Factories\Factory;
use InvalidArgumentException;

/**
 * @extends Factory<Venue>
 */
final class VenueFactory extends Factory
{
    protected $model = Venue::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $number = fake()->unique()->numberBetween(1, 1_000_000);

        return [
            'city_id' => City::factory(),
            'name' => "Venue {$number}",
            'slug' => "venue-{$number}",
            'timezone' => 'America/New_York',
            'status' => 'active',
            'base_per_person_cents' => 25000,
            'currency' => 'USD',
        ];
    }

    public function timezone(string $timezone): self
    {
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException("Unknown timezone {$timezone}.");
        }

        return $this->state(['timezone' => $timezone]);
    }

    public function inactive(): self
    {
        return $this->state(['status' => 'inactive']);
    }
}
