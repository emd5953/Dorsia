<?php

declare(strict_types=1);

use App\Modules\Pricing\Actions\GetQuoteForHold;
use App\Modules\Pricing\Actions\QuoteHold;
use App\Modules\Pricing\Data\QuoteHoldInput;
use App\Modules\Pricing\Exceptions\QuoteNotFoundException;
use App\Modules\Pricing\Models\DemandSignal;
use App\Modules\Pricing\Models\PriceQuote;
use App\Shared\Money\Currency;
use App\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function quoteInput(
    int $holdId = 1,
    int $partySize = 2,
    string $startsAt = '2026-11-07 01:00:00',
    int $base = 25000,
    string $timezone = 'America/New_York',
): QuoteHoldInput {
    return new QuoteHoldInput(
        holdId: $holdId,
        slotId: 10,
        venueId: 1,
        venueTimezone: $timezone,
        startsAt: CarbonImmutable::parse($startsAt, 'UTC'),
        partySize: $partySize,
        basePerPerson: Money::of($base, Currency::USD),
        expiresAt: CarbonImmutable::parse('2026-11-01 12:05:00', 'UTC'),
    );
}

it('inserts one quote with amount = per person x party size and the hold expiry', function (): void {
    $data = DB::transaction(fn () => app(QuoteHold::class)->handle(quoteInput(partySize: 2)));

    expect($data->perPerson->cents)->toBe(37500)
        ->and($data->amount->cents)->toBe(75000)
        ->and($data->amount->currency)->toBe(Currency::USD)
        ->and($data->holdId)->toBe(1)
        ->and($data->slotId)->toBe(10)
        ->and($data->expiresAt->equalTo(CarbonImmutable::parse('2026-11-01 12:05:00', 'UTC')))->toBeTrue()
        ->and(PriceQuote::query()->count())->toBe(1);

    $row = PriceQuote::query()->firstOrFail();

    expect($row->amount_cents)->toBe(75000)
        ->and($row->venue_id)->toBe(1)
        ->and($row->currency)->toBe('USD');
});

it('saves the C§10 inputs on the quote', function (): void {
    DemandSignal::factory()->create(['weekday' => 5, 'hour' => 20, 'party_band' => '3-4', 'unmet_searches' => 6, 'sellout_seconds_p50' => 30]);
    DemandSignal::factory()->create(['weekday' => 5, 'hour' => 20, 'party_band' => '1-2', 'unmet_searches' => 2]);

    $data = DB::transaction(fn () => app(QuoteHold::class)->handle(quoteInput(partySize: 4)));

    expect($data->inputs)->toMatchArray([
        'formula_version' => 1,
        'base_per_person_cents' => 25000,
        'local_weekday' => 5,
        'local_hour' => 20,
        'tier' => 'peak',
        'tier_bps' => 15000,
        'party_band' => '3-4',
        'band_unmet' => 6,
        'venue_hour_unmet' => 8,
        'used_fallback' => false,
        'unmet_used' => 6,
        'sellout_seconds_p50' => 30,
        'unmet_bps' => 600,
        'sellout_bps' => 2500,
        'demand_bps' => 13100,
    ])
        ->and($data->inputs['signals_computed_at'])->toBeString()
        // 25000 * 1.5 * 1.31 = 49125 -> 49100
        ->and($data->perPerson->cents)->toBe(49100)
        ->and(PriceQuote::query()->firstOrFail()->inputs)->toBe($data->inputs);
});

it('uses the venue-hour total when the hold band row is missing or thin', function (): void {
    DemandSignal::factory()->create(['weekday' => 5, 'hour' => 20, 'party_band' => '1-2', 'unmet_searches' => 4]);

    $data = DB::transaction(fn () => app(QuoteHold::class)->handle(quoteInput(partySize: 6)));

    expect($data->inputs['band_unmet'])->toBe(0)
        ->and($data->inputs['used_fallback'])->toBeTrue()
        ->and($data->inputs['unmet_used'])->toBe(4);
});

it('ignores signals of other venues, weekdays and hours', function (): void {
    DemandSignal::factory()->create(['venue_id' => 2, 'weekday' => 5, 'hour' => 20, 'unmet_searches' => 50]);
    DemandSignal::factory()->create(['weekday' => 6, 'hour' => 20, 'unmet_searches' => 50]);
    DemandSignal::factory()->create(['weekday' => 5, 'hour' => 21, 'unmet_searches' => 50]);

    $data = DB::transaction(fn () => app(QuoteHold::class)->handle(quoteInput()));

    expect($data->inputs['demand_bps'])->toBe(10000)->and($data->inputs['signals_computed_at'])->toBeNull();
});

it('quotes with demand 10000 when the signals job has never run', function (): void {
    expect(DemandSignal::query()->count())->toBe(0);

    $data = DB::transaction(fn () => app(QuoteHold::class)->handle(quoteInput()));

    expect($data->inputs['demand_bps'])->toBe(10000)
        ->and($data->inputs['signals_computed_at'])->toBeNull()
        ->and($data->perPerson->cents)->toBe(37500);
});

it('quotes from stale signals without complaint', function (): void {
    DemandSignal::factory()->create(['weekday' => 5, 'hour' => 20, 'unmet_searches' => 10, 'computed_at' => now()->subDays(60)]);

    $data = DB::transaction(fn () => app(QuoteHold::class)->handle(quoteInput()));

    expect($data->inputs['unmet_bps'])->toBe(1000)->and($data->perPerson->cents)->toBe(41300);
});

it('returns the existing row on a second call for the same hold', function (): void {
    $first = DB::transaction(fn () => app(QuoteHold::class)->handle(quoteInput(holdId: 7, partySize: 2)));

    // Signals change between calls; the locked quote must not.
    DemandSignal::factory()->create(['weekday' => 5, 'hour' => 20, 'unmet_searches' => 50]);

    $second = DB::transaction(fn () => app(QuoteHold::class)->handle(quoteInput(holdId: 7, partySize: 3)));

    expect($second->id)->toBe($first->id)
        ->and($second->amount->cents)->toBe($first->amount->cents)
        ->and($second->partySize)->toBe(2)
        ->and(PriceQuote::query()->count())->toBe(1);
});

it('throws LogicException outside a transaction', function (): void {
    app(QuoteHold::class)->handle(quoteInput());
})->throws(LogicException::class);

it('does not write when called outside a transaction', function (): void {
    try {
        app(QuoteHold::class)->handle(quoteInput());
    } catch (LogicException) {
    }

    expect(PriceQuote::query()->count())->toBe(0);
});

it('prices in the venue timezone for a half-hour offset venue', function (): void {
    // 14:30 UTC Friday 2026-11-06 is 20:00 in Kolkata: peak.
    $data = DB::transaction(fn () => app(QuoteHold::class)->handle(
        quoteInput(startsAt: '2026-11-06 14:30:00', timezone: 'Asia/Kolkata'),
    ));

    expect($data->inputs['local_hour'])->toBe(20)->and($data->inputs['tier'])->toBe('peak');
});

it('rejects a mismatched amount at the database level', function (): void {
    DB::statement('SAVEPOINT before_bad_quote');

    try {
        DB::statement(
            "INSERT INTO price_quotes (hold_id, slot_id, venue_id, party_size, per_person_cents, amount_cents, currency, inputs, expires_at, created_at)
             VALUES (99, 1, 1, 2, 37500, 37500, 'USD', '{}', now(), now())",
        );
    } finally {
        DB::statement('ROLLBACK TO SAVEPOINT before_bad_quote');
    }
})->throws(QueryException::class, 'price_quotes_amount_check');

it('rejects a non-positive per-person price at the database level', function (): void {
    PriceQuote::factory()->create(['per_person_cents' => 0, 'amount_cents' => 0]);
})->throws(QueryException::class, 'price_quotes_per_person_check');

it('fetches a quote by hold id', function (): void {
    $created = DB::transaction(fn () => app(QuoteHold::class)->handle(quoteInput(holdId: 5)));

    $fetched = app(GetQuoteForHold::class)->handle(5);

    expect($fetched)->toEqual($created);
});

it('throws QuoteNotFoundException for a hold without a quote', function (): void {
    app(GetQuoteForHold::class)->handle(404);
})->throws(QuoteNotFoundException::class);

it('does not leave a quote after a rolled back transaction', function (): void {
    try {
        DB::transaction(function (): void {
            app(QuoteHold::class)->handle(quoteInput(holdId: 11));

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect(PriceQuote::query()->where('hold_id', 11)->count())->toBe(0);
});
