<?php

declare(strict_types=1);

use App\Modules\Inventory\Actions\GetSelloutStats;
use App\Modules\Inventory\Data\SelloutStat;
use App\Modules\Inventory\Models\Venue;
use Carbon\CarbonImmutable;
use Tests\Modules\Inventory\Support\Fixtures;

/**
 * A sold-out slot at $startsAt (UTC) released at 10:00Z and sold out $seconds later, in distinct shapes so the unique key holds.
 */
function soldOutSlot(Venue $venue, string $startsAt, int $seconds, int $shape): void
{
    $released = CarbonImmutable::parse('2026-10-01 10:00:00');

    Fixtures::slot([
        'starts_at' => $startsAt,
        'released_at' => $released,
        'sold_out_at' => $released->addSeconds($seconds),
        'min_party_size' => $shape,
        'max_party_size' => $shape,
    ], $venue);
}

it('returns the median sell-out seconds for an odd sample count', function (): void {
    $venue = Venue::factory()->create();
    // 2026-11-07 is a Saturday; 01:00Z on the 8th = 20:00 EST on the 7th.
    soldOutSlot($venue, '2026-11-08 01:00:00', 100, 1);
    soldOutSlot($venue, '2026-11-08 01:00:00', 900, 2);
    soldOutSlot($venue, '2026-11-08 01:00:00', 300, 3);

    $stats = app(GetSelloutStats::class)->handle(CarbonImmutable::parse('2026-10-01'));

    expect($stats)->toEqual([new SelloutStat($venue->id, 6, 20, 300)]);
});

it('returns the mean of the two middle samples for an even count, rounded half up', function (): void {
    $venue = Venue::factory()->create();
    soldOutSlot($venue, '2026-11-08 01:00:00', 100, 1);
    soldOutSlot($venue, '2026-11-08 01:00:00', 201, 2);

    $stats = app(GetSelloutStats::class)->handle(CarbonImmutable::parse('2026-10-01'));

    expect($stats)->toEqual([new SelloutStat($venue->id, 6, 20, 151)]);
});

it('even counts with four samples use only the two middle values', function (): void {
    $venue = Venue::factory()->create();

    foreach ([10, 20, 30, 1000] as $i => $seconds) {
        soldOutSlot($venue, '2026-11-08 01:00:00', $seconds, $i + 1);
    }

    expect(app(GetSelloutStats::class)->handle(CarbonImmutable::parse('2026-10-01')))->toEqual([new SelloutStat($venue->id, 6, 20, 25)]);
});

it('groups by venue-local ISO weekday and hour, using each venue\'s own timezone', function (): void {
    $ny = Venue::factory()->create();
    $hnl = Venue::factory()->timezone('Pacific/Honolulu')->create();

    soldOutSlot($ny, '2026-11-08 01:00:00', 60, 1);   // Sat 20:00 EST
    soldOutSlot($ny, '2026-11-08 06:00:00', 120, 1);  // Sun 01:00 EST
    soldOutSlot($hnl, '2026-11-08 06:00:00', 180, 1); // Sat 20:00 HST (same instant as Sun 01:00 EST)

    $stats = collect(app(GetSelloutStats::class)->handle(CarbonImmutable::parse('2026-10-01')))
        ->map(fn (SelloutStat $s): array => [$s->venueId, $s->weekday, $s->hour, $s->p50Seconds])
        ->all();

    expect($stats)->toBe([
        [$ny->id, 6, 20, 60],
        [$ny->id, 7, 1, 120],
        [$hnl->id, 6, 20, 180],
    ]);
});

it('maps Sunday to ISO weekday 7 and Monday to 1', function (): void {
    $venue = Venue::factory()->create();
    soldOutSlot($venue, '2026-11-09 01:00:00', 60, 1); // Sun 20:00 EST
    soldOutSlot($venue, '2026-11-10 01:00:00', 90, 1); // Mon 20:00 EST

    $weekdays = collect(app(GetSelloutStats::class)->handle(CarbonImmutable::parse('2026-10-01')))->pluck('weekday')->all();

    expect($weekdays)->toBe([1, 7]);
});

it('uses the local hour across the DST change: 01:00 UTC is 21:00 EDT before and 20:00 EST after', function (): void {
    $venue = Venue::factory()->create();
    soldOutSlot($venue, '2026-10-31 01:00:00', 60, 1); // Fri 21:00 EDT
    soldOutSlot($venue, '2026-11-08 01:00:00', 60, 1); // Sat 20:00 EST

    $stats = collect(app(GetSelloutStats::class)->handle(CarbonImmutable::parse('2026-10-01')))
        ->map(fn (SelloutStat $s): array => [$s->weekday, $s->hour])->all();

    expect($stats)->toBe([[5, 21], [6, 20]]);
});

it('only counts slots sold out at or after $since and ignores slots never sold out', function (): void {
    $venue = Venue::factory()->create();
    $released = CarbonImmutable::parse('2026-10-01 10:00:00');
    soldOutSlot($venue, '2026-11-08 01:00:00', 100, 1);
    Fixtures::slot(['starts_at' => '2026-11-08 01:00:00', 'released_at' => $released, 'min_party_size' => 2, 'max_party_size' => 2], $venue);

    $exact = $released->addSeconds(100);

    expect(app(GetSelloutStats::class)->handle($exact))->toHaveCount(1)
        ->and(app(GetSelloutStats::class)->handle($exact->addMicrosecond()))->toBe([])
        ->and(app(GetSelloutStats::class)->handle($exact->subSecond()))->toHaveCount(1);
});

it('returns an empty list when nothing sold out', function (): void {
    Fixtures::slot();

    expect(app(GetSelloutStats::class)->handle(CarbonImmutable::parse('2020-01-01')))->toBe([]);
});
