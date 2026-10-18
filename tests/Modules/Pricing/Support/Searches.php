<?php

declare(strict_types=1);

namespace Tests\Modules\Pricing\Support;

use App\Modules\Inventory\Events\AvailabilitySearched;
use Carbon\CarbonImmutable;

final class Searches
{
    public static function event(
        int $memberId,
        int $venueId = 1,
        string $timezone = 'America/New_York',
        string $date = '2026-11-06',
        int $hour = 20,
        int $partySize = 2,
        bool $hadAvailability = false,
        ?CarbonImmutable $searchedAt = null,
    ): AvailabilitySearched {
        return new AvailabilitySearched(
            memberId: $memberId,
            venueId: $venueId,
            venueTimezone: $timezone,
            requestedDate: $date,
            requestedHour: $hour,
            partySize: $partySize,
            hadAvailability: $hadAvailability,
            searchedAt: $searchedAt ?? CarbonImmutable::now(),
        );
    }
}
