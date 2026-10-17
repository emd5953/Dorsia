<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Database\Factories\SlotFactory;
use App\Shared\Support\Timestamps;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $venue_id
 * @property int|null $drop_id
 * @property CarbonImmutable $starts_at
 * @property int $capacity
 * @property int $held_count
 * @property int $booked_count
 * @property int $min_party_size
 * @property int $max_party_size
 * @property CarbonImmutable $released_at
 * @property CarbonImmutable|null $sold_out_at
 */
final class Slot extends Model
{
    /** @use HasFactory<SlotFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $dateFormat = Timestamps::FORMAT;

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
            'sold_out_at' => 'immutable_datetime',
            'capacity' => 'integer',
            'held_count' => 'integer',
            'booked_count' => 'integer',
            'min_party_size' => 'integer',
            'max_party_size' => 'integer',
        ];
    }

    /**
     * @return SlotFactory
     */
    protected static function newFactory(): Factory
    {
        return SlotFactory::new();
    }
}
