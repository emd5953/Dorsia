<?php

declare(strict_types=1);

use App\Modules\Pricing\Support\DemandSnapshot;
use App\Modules\Pricing\Support\PriceCalculator;
use Carbon\CarbonImmutable;

function priceQuote(
    int $base,
    string $localTime,
    ?DemandSnapshot $signals = null,
    string $timezone = 'America/New_York',
    string $band = '1-2',
): array {
    $breakdown = PriceCalculator::fromConfig()->calculate(
        $base,
        CarbonImmutable::parse($localTime, $timezone),
        $timezone,
        $band,
        $signals,
    );

    return ['per_person' => $breakdown->perPersonCents, 'inputs' => $breakdown->inputs];
}

function demandSnap(int $bandUnmet = 0, int $venueHourUnmet = 0, ?int $p50 = null): DemandSnapshot
{
    return new DemandSnapshot('2026-11-01T00:00:00Z', $bandUnmet, $venueHourUnmet, $p50);
}

it('reproduces the C§10 example', function (): void {
    $result = priceQuote(25000, '2026-11-07 20:00');

    expect($result['per_person'])->toBe(37500)
        ->and($result['per_person'] * 2)->toBe(75000)
        ->and($result['inputs']['tier'])->toBe('peak')
        ->and($result['inputs']['demand_bps'])->toBe(10000);
});

it('picks the tier in venue-local time', function (string $localTime, string $tier, int $bps): void {
    $result = priceQuote(10000, $localTime);

    expect($result['inputs']['tier'])->toBe($tier)
        ->and($result['inputs']['tier_bps'])->toBe($bps)
        ->and($result['per_person'])->toBe(intdiv(10000 * $bps, 10000));
})->with([
    'fri 19:00 peak' => ['2026-11-06 19:00', 'peak', 15000],
    'fri 21:59 peak' => ['2026-11-06 21:59', 'peak', 15000],
    'fri 18:00 shoulder' => ['2026-11-06 18:00', 'shoulder', 12000],
    'fri 22:00 shoulder' => ['2026-11-06 22:00', 'shoulder', 12000],
    'fri 23:00 off-peak' => ['2026-11-06 23:00', 'off_peak', 10000],
    'fri 17:59 off-peak' => ['2026-11-06 17:59', 'off_peak', 10000],
    'sat 20:00 peak' => ['2026-11-07 20:00', 'peak', 15000],
    'thu 19:00 shoulder' => ['2026-11-05 19:00', 'shoulder', 12000],
    'thu 17:00 off-peak' => ['2026-11-05 17:00', 'off_peak', 10000],
    'wed 20:00 off-peak' => ['2026-11-04 20:00', 'off_peak', 10000],
    'sun 22:00 shoulder' => ['2026-11-08 22:00', 'shoulder', 12000],
    'sun 23:00 off-peak' => ['2026-11-08 23:00', 'off_peak', 10000],
    'mon 20:00 off-peak' => ['2026-11-09 20:00', 'off_peak', 10000],
]);

it('derives weekday and hour from the venue timezone, not UTC', function (): void {
    // 01:00 UTC Saturday is Friday 20:00 in New York (EST).
    $result = priceQuote(25000, '2026-11-07 01:00', timezone: 'UTC');
    expect($result['inputs']['tier'])->toBe('off_peak')->and($result['inputs']['local_weekday'])->toBe(6);

    $instant = CarbonImmutable::parse('2026-11-07 01:00:00', 'UTC');
    $breakdown = PriceCalculator::fromConfig()->calculate(25000, $instant, 'America/New_York', '1-2', null);

    expect($breakdown->inputs['tier'])->toBe('peak')
        ->and($breakdown->inputs['local_weekday'])->toBe(5)
        ->and($breakdown->inputs['local_hour'])->toBe(20)
        ->and($breakdown->perPersonCents)->toBe(37500);
});

it('handles a half-hour offset timezone', function (): void {
    // 14:30 UTC Friday is 20:00 Friday in Kolkata.
    $instant = CarbonImmutable::parse('2026-11-06 14:30:00', 'UTC');
    $breakdown = PriceCalculator::fromConfig()->calculate(10000, $instant, 'Asia/Kolkata', '1-2', null);

    expect($breakdown->inputs['local_hour'])->toBe(20)->and($breakdown->inputs['tier'])->toBe('peak');
});

it('falls back to the venue-hour total when the band is thin', function (
    int $bandUnmet,
    int $venueHourUnmet,
    int $unmetUsed,
    bool $fallback,
): void {
    $inputs = priceQuote(10000, '2026-11-09 20:00', demandSnap($bandUnmet, $venueHourUnmet))['inputs'];

    expect($inputs['unmet_used'])->toBe($unmetUsed)
        ->and($inputs['used_fallback'])->toBe($fallback)
        ->and($inputs['band_unmet'])->toBe($bandUnmet)
        ->and($inputs['venue_hour_unmet'])->toBe($venueHourUnmet)
        ->and($inputs['unmet_bps'])->toBe($unmetUsed * 100);
})->with([
    'band 4 falls back to 12' => [4, 12, 12, true],
    'band 5 uses itself' => [5, 12, 5, false],
    'band 0 falls back' => [0, 7, 7, true],
    'band 6 uses itself' => [6, 6, 6, false],
    'thin with empty venue hour' => [0, 0, 0, true],
]);

it('caps the unmet uplift at 5000 bps', function (): void {
    $result = priceQuote(20000, '2026-11-09 20:00', demandSnap(80, 80));

    expect($result['inputs']['unmet_bps'])->toBe(5000)
        ->and($result['inputs']['demand_bps'])->toBe(15000)
        ->and($result['per_person'])->toBe(30000);
});

it('adds sell-out speed uplift by p50 seconds', function (?int $p50, int $bps): void {
    $result = priceQuote(10000, '2026-11-09 20:00', demandSnap(p50: $p50));

    expect($result['inputs']['sellout_bps'])->toBe($bps)
        ->and($result['inputs']['sellout_seconds_p50'])->toBe($p50)
        ->and($result['per_person'])->toBe(10000 + $bps);
})->with([
    '45s' => [45, 2500],
    '60s' => [60, 2500],
    '61s' => [61, 1000],
    '600s' => [600, 1000],
    '601s' => [601, 0],
    'null' => [null, 0],
]);

it('rounds to whole dollars half-up', function (int $base, int $expected): void {
    expect(priceQuote($base, '2026-11-09 20:00')['per_person'])->toBe($expected);
})->with([
    'exact half rounds up' => [10050, 10100],
    'just under half rounds down' => [10049, 10000],
    'exact dollars unchanged' => [12300, 12300],
]);

it('rounds after combining tier and demand multipliers', function (): void {
    // 10033 * 1.2 * 1.01 = 12159.9 -> 12200 (not rounded per step).
    $result = priceQuote(10033, '2026-11-06 18:00', demandSnap(1, 1));

    expect($result['inputs']['demand_bps'])->toBe(10100)->and($result['per_person'])->toBe(12200);
});

it('applies the minimum per-person floor', function (): void {
    $result = priceQuote(500, '2026-11-09 20:00');

    expect($result['per_person'])->toBe(1000);
});

it('treats missing signals as 1.0x with exactly the C§10 input keys', function (): void {
    $inputs = priceQuote(25000, '2026-11-07 20:00', band: '3-4')['inputs'];

    expect(array_keys($inputs))->toBe([
        'formula_version', 'base_per_person_cents', 'local_weekday', 'local_hour', 'tier', 'tier_bps',
        'party_band', 'signals_computed_at', 'band_unmet', 'venue_hour_unmet', 'used_fallback', 'unmet_used',
        'sellout_seconds_p50', 'unmet_bps', 'sellout_bps', 'demand_bps', 'rounding_cents', 'min_per_person_cents',
    ])
        ->and($inputs['formula_version'])->toBe(1)
        ->and($inputs['signals_computed_at'])->toBeNull()
        ->and($inputs['used_fallback'])->toBeFalse()
        ->and($inputs['party_band'])->toBe('3-4')
        ->and($inputs['rounding_cents'])->toBe(100)
        ->and($inputs['min_per_person_cents'])->toBe(1000);
});

it('records the signals timestamp', function (): void {
    expect(priceQuote(10000, '2026-11-09 20:00', demandSnap())['inputs']['signals_computed_at'])->toBe('2026-11-01T00:00:00Z');
});
