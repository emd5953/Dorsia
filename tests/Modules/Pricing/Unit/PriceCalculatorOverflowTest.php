<?php

declare(strict_types=1);

use App\Modules\Pricing\Support\PriceCalculator;
use Carbon\CarbonImmutable;

function overflowQuote(int $base): int
{
    return PriceCalculator::fromConfig()->calculate(
        $base,
        CarbonImmutable::parse('2026-11-07 20:00', 'America/New_York'),
        'America/New_York',
        '1-2',
        null,
    )->perPersonCents;
}

it('throws OverflowException instead of overflowing int64 on an absurd base price', function (): void {
    overflowQuote(PHP_INT_MAX);
})->throws(OverflowException::class);

it('prices a large base well under the overflow limit', function (): void {
    expect(overflowQuote(10_000_000_000))->toBe(15_000_000_000);
});

it('guards the exact overflow boundary', function (): void {
    // peak tier 15000 bps x demand 10000 bps
    $limit = intdiv(PHP_INT_MAX - 10000 * 10000 * 100, 15000 * 10000);

    expect(fn () => overflowQuote($limit))->not->toThrow(OverflowException::class)
        ->and(fn () => overflowQuote($limit + 1))->toThrow(OverflowException::class);
});
