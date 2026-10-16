<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Events;

use Carbon\CarbonImmutable;

final readonly class AvailabilitySearched
{
    public function __construct(
        public int $memberId,
        public int $venueId,
        public string $venueTimezone,
        public string $requestedDate,     // 'Y-m-d', venue-local
        public int $requestedHour,        // 0..23, venue-local
        public int $partySize,
        public bool $hadAvailability,
        public CarbonImmutable $searchedAt,
    ) {}
}
