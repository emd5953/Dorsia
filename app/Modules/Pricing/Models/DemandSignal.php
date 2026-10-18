<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Models;

use App\Modules\Pricing\Database\Factories\DemandSignalFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $venue_id
 * @property int $weekday
 * @property int $hour
 * @property string $party_band
 * @property int|null $sellout_seconds_p50
 * @property int $unmet_searches
 * @property int $bid_count
 * @property CarbonImmutable $computed_at
 */
final class DemandSignal extends Model
{
    /** @use HasFactory<DemandSignalFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $table = 'demand_signals';

    protected $guarded = [];

    protected static function newFactory(): DemandSignalFactory
    {
        return DemandSignalFactory::new();
    }

    protected function casts(): array
    {
        return [
            'computed_at' => 'immutable_datetime',
        ];
    }
}
