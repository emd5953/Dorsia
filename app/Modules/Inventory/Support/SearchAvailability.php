<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Data\SlotData;
use App\Modules\Inventory\Events\AvailabilitySearched;
use App\Modules\Inventory\Exceptions\VenueNotFoundException;
use App\Modules\Inventory\Models\Venue;
use Carbon\CarbonImmutable;

/**
 * Lists a venue's slots for one venue-local date and reports whether the searched hour could be booked.
 */
final class SearchAvailability
{
    /**
     * @param  string  $date  Y-m-d in the venue's timezone
     *
     * @throws VenueNotFoundException
     */
    public function handle(int $memberId, int $venueId, string $date, int $hour, int $partySize): AvailabilityResult
    {
        $venue = Venue::query()->find($venueId) ?? throw new VenueNotFoundException;

        $dayStart = CarbonImmutable::createFromFormat('!Y-m-d', $date, $venue->timezone) ?? throw new VenueNotFoundException;
        $dayEnd = $dayStart->addDay();

        $slots = SlotRows::joined()
            ->where('slots.venue_id', $venueId)
            ->where('slots.starts_at', '>=', $dayStart->utc())
            ->where('slots.starts_at', '<', $dayEnd->utc())
            ->orderBy('slots.starts_at')
            ->orderBy('slots.id')
            ->get()
            ->map(fn (object $row): SlotData => SlotRows::toData((object) (array) $row))
            ->values()
            ->all();

        $now = now();

        $hadAvailability = false;

        foreach ($slots as $slot) {
            if (
                $slot->startsAt->setTimezone($venue->timezone)->hour === $hour
                && $slot->releasedAt->lessThanOrEqualTo($now)
                && $slot->startsAt->greaterThan($now)
                && $slot->available() > 0
                && $slot->minPartySize <= $partySize
                && $partySize <= $slot->maxPartySize
            ) {
                $hadAvailability = true;

                break;
            }
        }

        event(new AvailabilitySearched($memberId, $venueId, $venue->timezone, $date, $hour, $partySize, $hadAvailability, $now));

        return new AvailabilityResult($venueId, $venue->timezone, $date, $hour, $partySize, $hadAvailability, array_values($slots));
    }
}
