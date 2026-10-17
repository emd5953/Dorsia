<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Database\Seeders;

use App\Shared\Support\Timestamps;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Deterministic catalogue: fixed names and slugs, so migrate:fresh --seed (or a re-run) yields identical counts.
 */
final class InventorySeeder extends Seeder
{
    private const HOURS = [18, 19, 20, 21, 22];

    private const DAYS = 14;

    /** [min party, max party, capacity] */
    private const TABLE_SHAPES = [[1, 2, 6], [3, 4, 4], [5, 6, 2]];

    /**
     * [slug, name, timezone, [[venue slug, venue name, base per person cents], ...]]
     */
    private const CITIES = [
        ['new-york', 'New York', 'America/New_York', [['carbone', 'Carbone', 25000], ['the-grill', 'The Grill', 30000]]],
        ['chicago', 'Chicago', 'America/Chicago', [['alinea', 'Alinea', 28000], ['girl-and-the-goat', 'Girl & the Goat', 18000]]],
        ['los-angeles', 'Los Angeles', 'America/Los_Angeles', [['providence', 'Providence', 27000], ['bestia', 'Bestia', 20000]]],
        ['honolulu', 'Honolulu', 'Pacific/Honolulu', [['senia', 'Senia', 22000], ['mw-restaurant', 'MW Restaurant', 15000]]],
    ];

    public function run(): void
    {
        $now = CarbonImmutable::now();

        foreach (self::CITIES as [$citySlug, $cityName, $timezone, $venues]) {
            if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
                throw new InvalidArgumentException("Unknown timezone {$timezone}.");
            }

            DB::table('cities')->insertOrIgnore(['slug' => $citySlug, 'name' => $cityName, 'country_code' => 'US', 'created_at' => Timestamps::bind($now), 'updated_at' => Timestamps::bind($now)]);
            $cityId = (int) DB::table('cities')->where('slug', $citySlug)->value('id');

            foreach ($venues as [$venueSlug, $venueName, $baseCents]) {
                DB::table('venues')->insertOrIgnore([
                    'city_id' => $cityId,
                    'name' => $venueName,
                    'slug' => $venueSlug,
                    'timezone' => $timezone,
                    'status' => 'active',
                    'base_per_person_cents' => $baseCents,
                    'currency' => 'USD',
                    'created_at' => Timestamps::bind($now),
                    'updated_at' => Timestamps::bind($now),
                ]);
                $venueId = (int) DB::table('venues')->where('slug', $venueSlug)->value('id');

                $this->seedSlots($venueId, $timezone, $now);
            }
        }

        $this->seedDrop($now);
    }

    private function seedSlots(int $venueId, string $timezone, CarbonImmutable $now): void
    {
        $today = $now->setTimezone($timezone)->startOfDay();
        $rows = [];

        for ($day = 1; $day <= self::DAYS; $day++) {
            foreach (self::HOURS as $hour) {
                $startsAt = $today->addDays($day)->setTime($hour, 0);

                array_push($rows, ...$this->slotRows($venueId, null, $startsAt, $now, $now));
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('slots')->insertOrIgnore($chunk);
        }
    }

    /**
     * One upcoming drop at the first New York venue: opens tomorrow 12:00 local, with its own 20:00 slots
     * on the first day after the regular window (the unique key forbids a second 20:00 table of the same shape).
     */
    private function seedDrop(CarbonImmutable $now): void
    {
        $venueId = (int) DB::table('venues')->where('slug', 'carbone')->value('id');
        $timezone = 'America/New_York';
        $today = $now->setTimezone($timezone)->startOfDay();
        $opensAt = $today->addDay()->setTime(12, 0)->utc();
        $startsAt = $today->addDays(self::DAYS + 1)->setTime(20, 0);

        $dropId = DB::table('slots')
            ->where('venue_id', $venueId)
            ->whereNotNull('drop_id')
            ->value('drop_id');

        if ($dropId !== null) {
            return;
        }

        $dropId = DB::table('drops')->insertGetId([
            'venue_id' => $venueId,
            'opens_at' => Timestamps::bind($opensAt),
            'created_at' => Timestamps::bind($now),
            'updated_at' => Timestamps::bind($now),
        ]);

        DB::table('slots')->insertOrIgnore($this->slotRows($venueId, (int) $dropId, $startsAt, $opensAt, $now));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function slotRows(int $venueId, ?int $dropId, CarbonImmutable $startsAt, CarbonImmutable $releasedAt, CarbonImmutable $now): array
    {
        $rows = [];

        foreach (self::TABLE_SHAPES as [$min, $max, $capacity]) {
            $rows[] = [
                'venue_id' => $venueId,
                'drop_id' => $dropId,
                'starts_at' => Timestamps::bind($startsAt),
                'capacity' => $capacity,
                'held_count' => 0,
                'booked_count' => 0,
                'min_party_size' => $min,
                'max_party_size' => $max,
                'released_at' => Timestamps::bind($releasedAt),
                'created_at' => Timestamps::bind($now),
                'updated_at' => Timestamps::bind($now),
            ];
        }

        return $rows;
    }
}
