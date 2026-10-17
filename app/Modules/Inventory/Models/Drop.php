<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Database\Factories\DropFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $venue_id
 * @property CarbonImmutable $opens_at
 * @property CarbonImmutable|null $opened_at
 * @property CarbonImmutable|null $sold_out_at
 */
final class Drop extends Model
{
    /** @use HasFactory<DropFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'opens_at' => 'immutable_datetime',
            'opened_at' => 'immutable_datetime',
            'sold_out_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return DropFactory
     */
    protected static function newFactory(): Factory
    {
        return DropFactory::new();
    }
}
