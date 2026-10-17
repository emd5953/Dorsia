<?php

declare(strict_types=1);

use App\Modules\Inventory\Data\SlotData;
use App\Shared\Money\Money;
use Carbon\CarbonImmutable;

function slotData(int $capacity, int $held, int $booked): SlotData
{
    return new SlotData(
        id: 1,
        venueId: 1,
        dropId: null,
        venueTimezone: 'America/New_York',
        startsAt: CarbonImmutable::parse('2026-11-08T01:00:00Z'),
        releasedAt: CarbonImmutable::parse('2026-10-01T00:00:00Z'),
        capacity: $capacity,
        heldCount: $held,
        bookedCount: $booked,
        minPartySize: 1,
        maxPartySize: 2,
        basePerPerson: Money::of(25000, 'USD'),
        soldOutAt: null,
    );
}

it('computes available as capacity minus held minus booked', function (int $capacity, int $held, int $booked, int $expected): void {
    expect(slotData($capacity, $held, $booked)->available())->toBe($expected);
})->with([
    'empty' => [6, 0, 0, 6],
    'held only' => [6, 2, 0, 4],
    'booked only' => [6, 0, 3, 3],
    'both' => [6, 2, 3, 1],
    'capacity equals booked' => [4, 0, 4, 0],
    'full of holds' => [4, 4, 0, 0],
]);
