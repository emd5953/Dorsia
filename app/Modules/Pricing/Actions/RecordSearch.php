<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Actions;

use App\Modules\Inventory\Events\AvailabilitySearched;
use App\Modules\Pricing\Support\PartyBands;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class RecordSearch
{
    private const TIMESTAMP_FORMAT = 'Y-m-d H:i:s.uP';

    /**
     * Stores the search once per member, venue, venue-local date, hour and party size.
     *
     * @return bool true if a new row was inserted, false if it was a duplicate
     */
    public function handle(AvailabilitySearched $event): bool
    {
        // Date and hour are venue-local; requested_at is the UTC instant of that local wall time.
        $requestedAt = CarbonImmutable::parse($event->requestedDate, $event->venueTimezone)
            ->setTime($event->requestedHour, 0);
        $requestedDate = CarbonImmutable::parse($event->requestedDate, 'UTC');

        $inserted = DB::table('search_events')->insertOrIgnore([
            'member_id' => $event->memberId,
            'venue_id' => $event->venueId,
            'requested_date' => $event->requestedDate,
            'requested_hour' => $event->requestedHour,
            'requested_at' => $requestedAt->utc()->format(self::TIMESTAMP_FORMAT),
            'local_weekday' => $requestedDate->dayOfWeekIso,
            'party_size' => $event->partySize,
            'party_band' => PartyBands::forPartySize($event->partySize),
            'had_availability' => $event->hadAvailability,
            'created_at' => $event->searchedAt->utc()->format(self::TIMESTAMP_FORMAT),
        ]);

        return $inserted === 1;
    }
}
