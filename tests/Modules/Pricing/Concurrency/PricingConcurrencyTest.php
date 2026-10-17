<?php

declare(strict_types=1);

use App\Modules\Pricing\Actions\QuoteHold;
use App\Modules\Pricing\Actions\RecordSearch;
use App\Modules\Pricing\Data\QuoteHoldInput;
use App\Modules\Pricing\Models\PriceQuote;
use App\Modules\Pricing\Models\SearchEvent;
use App\Shared\Members\Member;
use App\Shared\Money\Currency;
use App\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Modules\Pricing\Support\Searches;
use Tests\Support\Concurrency;

it('stores exactly one row for 20 parallel identical searches', function (): void {
    $member = Member::factory()->create();

    $results = Concurrency::run(array_map(
        fn (int $i): Closure => fn (): bool => app(RecordSearch::class)->handle(Searches::event($member->id)),
        range(1, 20),
    ));

    expect(array_filter($results))->toHaveCount(1)
        ->and(SearchEvent::query()->count())->toBe(1);
});

it('stores one row per distinct tuple when duplicates race', function (): void {
    $member = Member::factory()->create();

    $results = Concurrency::run(array_map(
        fn (int $i): Closure => fn (): bool => app(RecordSearch::class)->handle(
            Searches::event($member->id, partySize: 1 + ($i % 4) * 2),
        ),
        range(1, 20),
    ));

    expect(array_filter($results))->toHaveCount(4)
        ->and(SearchEvent::query()->count())->toBe(4);
});

it('creates exactly one quote for 20 parallel quotes of the same hold', function (): void {
    $input = new QuoteHoldInput(
        holdId: 77,
        slotId: 10,
        venueId: 1,
        venueTimezone: 'America/New_York',
        startsAt: CarbonImmutable::parse('2026-11-07 01:00:00', 'UTC'),
        partySize: 3,
        basePerPerson: Money::of(25000, Currency::USD),
        expiresAt: CarbonImmutable::now()->addMinutes(5),
    );

    $ids = Concurrency::run(array_map(
        fn (int $i): Closure => fn (): int => DB::transaction(fn () => app(QuoteHold::class)->handle($input))->id,
        range(1, 20),
    ));

    expect(array_unique($ids))->toHaveCount(1)
        ->and(PriceQuote::query()->count())->toBe(1)
        ->and(PriceQuote::query()->firstOrFail()->amount_cents)->toBe(37500 * 3);
});
