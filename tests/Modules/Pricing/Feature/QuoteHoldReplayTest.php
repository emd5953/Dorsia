<?php

declare(strict_types=1);

use App\Modules\Pricing\Actions\QuoteHold;
use App\Modules\Pricing\Data\QuoteHoldInput;
use App\Modules\Pricing\Models\DemandSignal;
use App\Modules\Pricing\Models\PriceQuote;
use App\Shared\Money\Currency;
use App\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function replayInput(int $holdId, int $partySize, string $startsAt, int $base): QuoteHoldInput
{
    return new QuoteHoldInput(
        holdId: $holdId,
        slotId: 10,
        venueId: 1,
        venueTimezone: 'America/New_York',
        startsAt: CarbonImmutable::parse($startsAt, 'UTC'),
        partySize: $partySize,
        basePerPerson: Money::of($base, Currency::USD),
        expiresAt: CarbonImmutable::parse('2026-11-01 12:05:00', 'UTC'),
    );
}

it('replays the first quote even if the replay carries a different party size, base or start', function (): void {
    $first = DB::transaction(fn () => app(QuoteHold::class)->handle(replayInput(21, 2, '2026-11-07 01:00:00', 25000)));

    $replay = DB::transaction(fn () => app(QuoteHold::class)->handle(replayInput(21, 6, '2026-11-09 01:00:00', 99000)));

    expect($replay)->toEqual($first)
        ->and($replay->partySize)->toBe(2)
        ->and($replay->amount->cents)->toBe(75000)
        ->and(PriceQuote::query()->where('hold_id', 21)->count())->toBe(1);
});

it('uses the newest computed_at when signal rows disagree', function (): void {
    DemandSignal::factory()->create(['party_band' => '1-2', 'weekday' => 5, 'hour' => 20, 'computed_at' => '2026-11-01 10:00:00+00', 'sellout_seconds_p50' => null]);
    DemandSignal::factory()->create(['party_band' => '3-4', 'weekday' => 5, 'hour' => 20, 'computed_at' => '2026-11-01 10:15:00+00', 'sellout_seconds_p50' => 30]);
    DemandSignal::factory()->create(['party_band' => '5+', 'weekday' => 5, 'hour' => 20, 'computed_at' => '2026-11-01 09:45:00+00', 'sellout_seconds_p50' => 500]);

    $data = DB::transaction(fn () => app(QuoteHold::class)->handle(replayInput(22, 2, '2026-11-07 01:00:00', 25000)));

    expect($data->inputs['signals_computed_at'])->toBe('2026-11-01T10:15:00Z');
});
