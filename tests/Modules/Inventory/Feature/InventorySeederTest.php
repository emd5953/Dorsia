<?php

declare(strict_types=1);

use App\Modules\Inventory\Database\Seeders\InventorySeeder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function inventoryCounts(): array
{
    return [
        'cities' => DB::table('cities')->count(),
        'venues' => DB::table('venues')->count(),
        'drops' => DB::table('drops')->count(),
        'slots' => DB::table('slots')->count(),
        'drop_slots' => DB::table('slots')->whereNotNull('drop_id')->count(),
    ];
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 15:00:00'));
});

it('seeds 4 cities, 2 venues each, 14 days of 5 hourly times with three table shapes, and one drop', function (): void {
    $this->seed(InventorySeeder::class);

    expect(inventoryCounts())->toBe(['cities' => 4, 'venues' => 8, 'drops' => 1, 'slots' => 8 * 14 * 5 * 3 + 3, 'drop_slots' => 3]);

    $timezones = DB::table('cities')->orderBy('id')->get()->mapWithKeys(
        fn (object $city): array => [$city->name => DB::table('venues')->where('city_id', $city->id)->distinct()->pluck('timezone')->all()],
    )->all();

    expect($timezones)->toBe([
        'New York' => ['America/New_York'],
        'Chicago' => ['America/Chicago'],
        'Los Angeles' => ['America/Los_Angeles'],
        'Honolulu' => ['Pacific/Honolulu'],
    ]);
});

it('gives every venue USD, a base price between 150 and 300 dollars and the active status', function (): void {
    $this->seed(InventorySeeder::class);

    expect(DB::table('venues')->where('currency', '<>', 'USD')->count())->toBe(0)
        ->and(DB::table('venues')->where('status', '<>', 'active')->count())->toBe(0)
        ->and(DB::table('venues')->where('base_per_person_cents', '<', 15000)->orWhere('base_per_person_cents', '>', 30000)->count())->toBe(0)
        ->and(DB::table('venues')->select('city_id')->get()->countBy('city_id')->values()->all())->toBe([2, 2, 2, 2]);
});

it('seeds shapes 1-2 x6, 3-4 x4 and 5-6 x2 released now at 18:00 to 22:00 venue-local', function (): void {
    $this->seed(InventorySeeder::class);

    $shapes = DB::table('slots')->whereNull('drop_id')
        ->select('min_party_size', 'max_party_size', 'capacity')->distinct()->orderBy('min_party_size')->get()
        ->map(fn (object $r): array => [$r->min_party_size, $r->max_party_size, $r->capacity])->all();

    expect($shapes)->toBe([[1, 2, 6], [3, 4, 4], [5, 6, 2]])
        ->and(DB::table('slots')->whereNull('drop_id')->where('released_at', '<>', now())->count())->toBe(0);

    $rows = DB::table('slots')->join('venues', 'venues.id', '=', 'slots.venue_id')->whereNull('drop_id')->get(['slots.starts_at', 'venues.timezone']);

    $hours = $rows->map(fn (object $r): int => CarbonImmutable::parse($r->starts_at)->setTimezone($r->timezone)->hour)->unique()->sort()->values()->all();
    $minutes = $rows->map(fn (object $r): int => CarbonImmutable::parse($r->starts_at)->setTimezone($r->timezone)->minute)->unique()->all();

    expect($hours)->toBe([18, 19, 20, 21, 22])->and($minutes)->toBe([0]);
});

it('covers the next 14 local days of every venue, none in the past', function (): void {
    $this->seed(InventorySeeder::class);

    foreach (DB::table('venues')->get() as $venue) {
        $dates = DB::table('slots')->where('venue_id', $venue->id)->whereNull('drop_id')->pluck('starts_at')
            ->map(fn (string $t): string => CarbonImmutable::parse($t)->setTimezone($venue->timezone)->toDateString())
            ->unique()->sort()->values();

        expect($dates)->toHaveCount(14)
            ->and($dates->first())->toBe(CarbonImmutable::now($venue->timezone)->addDay()->toDateString())
            ->and($dates->last())->toBe(CarbonImmutable::now($venue->timezone)->addDays(14)->toDateString());
    }
});

it('seeds one future drop at a New York venue opening tomorrow 12:00 local with three 20:00 slots', function (): void {
    $this->seed(InventorySeeder::class);

    $drop = DB::table('drops')->sole();
    $venue = DB::table('venues')->where('id', $drop->venue_id)->sole();
    $opensAt = CarbonImmutable::parse($drop->opens_at)->setTimezone('America/New_York');

    expect($venue->timezone)->toBe('America/New_York')
        ->and($opensAt->format('Y-m-d H:i'))->toBe('2026-10-21 12:00')
        ->and($drop->opened_at)->toBeNull();

    $slots = DB::table('slots')->where('drop_id', $drop->id)->get();

    expect($slots)->toHaveCount(3);

    foreach ($slots as $slot) {
        expect(CarbonImmutable::parse($slot->starts_at)->setTimezone('America/New_York')->format('H:i'))->toBe('20:00')
            ->and(CarbonImmutable::parse($slot->released_at)->equalTo(CarbonImmutable::parse($drop->opens_at)))->toBeTrue()
            ->and($slot->venue_id)->toBe($drop->venue_id);
    }
});

it('is re-runnable: seeding twice gives identical counts', function (): void {
    $this->seed(InventorySeeder::class);
    $first = inventoryCounts();

    $this->seed(InventorySeeder::class);

    expect(inventoryCounts())->toBe($first);
});

it('is deterministic: fixed slugs and names', function (): void {
    $this->seed(InventorySeeder::class);

    expect(DB::table('cities')->orderBy('id')->pluck('slug')->all())->toBe(['new-york', 'chicago', 'los-angeles', 'honolulu'])
        ->and(DB::table('venues')->orderBy('id')->pluck('slug')->all())->toBe([
            'carbone', 'the-grill', 'alinea', 'girl-and-the-goat', 'providence', 'bestia', 'senia', 'mw-restaurant',
        ]);
});
