<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Data\SlotData;
use App\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Reads slot rows joined with their venue and maps them to DTOs and outbox payloads.
 */
final class SlotRows
{
    /** Slot columns plus venue_timezone, venue_status, venue_base_per_person_cents, venue_currency. */
    public static function joined(): Builder
    {
        return DB::table('slots')
            ->join('venues', 'venues.id', '=', 'slots.venue_id')
            ->select(
                'slots.*',
                'venues.timezone as venue_timezone',
                'venues.status as venue_status',
                'venues.base_per_person_cents as venue_base_per_person_cents',
                'venues.currency as venue_currency',
            );
    }

    public static function find(int $slotId): ?stdClass
    {
        $row = self::joined()->where('slots.id', $slotId)->first();

        return $row instanceof stdClass ? $row : null;
    }

    /** Overlays the columns of $changed (e.g. an UPDATE ... RETURNING * row) on a joined row. */
    public static function merge(stdClass $joined, stdClass $changed): stdClass
    {
        return (object) array_merge((array) $joined, (array) $changed);
    }

    public static function time(mixed $value): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $value)->utc();
    }

    /** Microsecond-precision UTC string for any timestamp bound into SQL (C§3). */
    public static function ts(CarbonImmutable $time): string
    {
        return $time->utc()->format('Y-m-d H:i:s.uP');
    }

    public static function iso(CarbonImmutable $time): string
    {
        return $time->utc()->format('Y-m-d\TH:i:s\Z');
    }

    public static function toData(stdClass $row): SlotData
    {
        return new SlotData(
            id: (int) $row->id,
            venueId: (int) $row->venue_id,
            dropId: $row->drop_id === null ? null : (int) $row->drop_id,
            venueTimezone: (string) $row->venue_timezone,
            startsAt: self::time($row->starts_at),
            releasedAt: self::time($row->released_at),
            capacity: (int) $row->capacity,
            heldCount: (int) $row->held_count,
            bookedCount: (int) $row->booked_count,
            minPartySize: (int) $row->min_party_size,
            maxPartySize: (int) $row->max_party_size,
            basePerPerson: Money::of((int) $row->venue_base_per_person_cents, (string) $row->venue_currency),
            soldOutAt: $row->sold_out_at === null ? null : self::time($row->sold_out_at),
        );
    }

    /**
     * C§7 slot.* payload; `available` comes from the same row as the counts.
     *
     * @return array<string, mixed>
     */
    public static function payload(stdClass $row, CarbonImmutable $now, ?string $reason = null): array
    {
        $payload = [
            'slot_id' => (int) $row->id,
            'venue_id' => (int) $row->venue_id,
            'drop_id' => $row->drop_id === null ? null : (int) $row->drop_id,
            'starts_at' => self::iso(self::time($row->starts_at)),
            'capacity' => (int) $row->capacity,
            'available' => (int) $row->capacity - (int) $row->held_count - (int) $row->booked_count,
            'occurred_at' => self::iso($now),
        ];

        if ($reason !== null) {
            $payload['reason'] = $reason;
        }

        return $payload;
    }
}
