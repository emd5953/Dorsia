<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Database\Factories;

use App\Modules\Inventory\Models\City;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<City>
 */
final class CityFactory extends Factory
{
    protected $model = City::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $number = fake()->unique()->numberBetween(1, 1_000_000);

        return [
            'name' => "City {$number}",
            'slug' => "city-{$number}",
            'country_code' => 'US',
        ];
    }
}
