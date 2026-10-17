<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Database\Factories\CityFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $country_code
 */
final class City extends Model
{
    /** @use HasFactory<CityFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return CityFactory
     */
    protected static function newFactory(): Factory
    {
        return CityFactory::new();
    }
}
