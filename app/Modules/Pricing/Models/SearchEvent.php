<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Models;

use App\Modules\Pricing\Database\Factories\SearchEventFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $member_id
 * @property int $venue_id
 * @property string $requested_date
 * @property int $requested_hour
 * @property CarbonImmutable $requested_at
 * @property int $local_weekday
 * @property int $party_size
 * @property string $party_band
 * @property bool $had_availability
 * @property CarbonImmutable $created_at
 */
final class SearchEvent extends Model
{
    /** @use HasFactory<SearchEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'search_events';

    protected $guarded = [];

    protected static function newFactory(): SearchEventFactory
    {
        return SearchEventFactory::new();
    }

    protected function casts(): array
    {
        return [
            'requested_date' => 'date:Y-m-d',
            'requested_at' => 'immutable_datetime',
            'had_availability' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }
}
