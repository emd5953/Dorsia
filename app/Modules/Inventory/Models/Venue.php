<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Database\Factories\VenueFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $city_id
 * @property string $name
 * @property string $slug
 * @property string $timezone
 * @property string $status
 * @property int $base_per_person_cents
 * @property string $currency
 */
final class Venue extends Model
{
    /** @use HasFactory<VenueFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected function casts(): array
    {
        return ['base_per_person_cents' => 'integer'];
    }

    /**
     * @return VenueFactory
     */
    protected static function newFactory(): Factory
    {
        return VenueFactory::new();
    }
}
