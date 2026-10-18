<?php

declare(strict_types=1);

use App\Modules\Pricing\Actions\RecordSearch;
use App\Modules\Pricing\Models\SearchEvent;
use App\Shared\Members\Member;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Modules\Pricing\Support\Searches;

it('stores venue-local date and hour, UTC requested_at, ISO weekday and band', function (): void {
    $member = Member::factory()->create();
    $searchedAt = CarbonImmutable::parse('2026-11-01 09:30:15', 'UTC');

    $inserted = app(RecordSearch::class)->handle(Searches::event(
        $member->id,
        venueId: 3,
        timezone: 'America/New_York',
        date: '2026-11-06',
        hour: 20,
        partySize: 4,
        hadAvailability: true,
        searchedAt: $searchedAt,
    ));

    $row = SearchEvent::query()->firstOrFail();

    expect($inserted)->toBeTrue()
        ->and($row->member_id)->toBe($member->id)
        ->and($row->venue_id)->toBe(3)
        ->and($row->requested_date->format('Y-m-d'))->toBe('2026-11-06')
        ->and($row->requested_hour)->toBe(20)
        // Friday 20:00 EST is 01:00 UTC Saturday
        ->and($row->requested_at->utc()->toDateTimeString())->toBe('2026-11-07 01:00:00')
        ->and($row->local_weekday)->toBe(5)
        ->and($row->party_size)->toBe(4)
        ->and($row->party_band)->toBe('3-4')
        ->and($row->had_availability)->toBeTrue()
        ->and($row->created_at->utc()->toDateTimeString())->toBe('2026-11-01 09:30:15');
});

it('derives local_weekday from the local date for every weekday', function (string $date, int $weekday): void {
    $member = Member::factory()->create();

    app(RecordSearch::class)->handle(Searches::event($member->id, date: $date, hour: 1));

    expect(SearchEvent::query()->firstOrFail()->local_weekday)->toBe($weekday);
})->with([
    'monday' => ['2026-11-02', 1],
    'thursday' => ['2026-11-05', 4],
    'saturday' => ['2026-11-07', 6],
    'sunday' => ['2026-11-08', 7],
]);

it('stores the right local hour for a half-hour offset timezone', function (): void {
    $member = Member::factory()->create();

    app(RecordSearch::class)->handle(Searches::event($member->id, timezone: 'Asia/Kolkata', date: '2026-11-06', hour: 20));

    $row = SearchEvent::query()->firstOrFail();

    expect($row->requested_hour)->toBe(20)
        ->and($row->requested_date->format('Y-m-d'))->toBe('2026-11-06')
        // 20:00 IST is 14:30 UTC
        ->and($row->requested_at->utc()->toDateTimeString())->toBe('2026-11-06 14:30:00')
        ->and($row->local_weekday)->toBe(5);
});

it('keeps local date and hour when the UTC date differs', function (): void {
    $member = Member::factory()->create();

    // 23:00 in Honolulu (UTC-10) is 09:00 UTC the next day.
    app(RecordSearch::class)->handle(Searches::event($member->id, timezone: 'Pacific/Honolulu', date: '2026-11-06', hour: 23));

    $row = SearchEvent::query()->firstOrFail();

    expect($row->requested_hour)->toBe(23)
        ->and($row->local_weekday)->toBe(5)
        ->and($row->requested_at->utc()->toDateTimeString())->toBe('2026-11-07 09:00:00');
});

it('dedupes the same member, venue, date, hour and party size', function (): void {
    $member = Member::factory()->create();
    $action = app(RecordSearch::class);

    $first = $action->handle(Searches::event($member->id, hadAvailability: false));
    $second = $action->handle(Searches::event($member->id, hadAvailability: false));
    $third = $action->handle(Searches::event($member->id, hadAvailability: true));

    expect($first)->toBeTrue()
        ->and($second)->toBeFalse()
        ->and($third)->toBeFalse()
        ->and(SearchEvent::query()->count())->toBe(1)
        // the first result stands
        ->and(SearchEvent::query()->firstOrFail()->had_availability)->toBeFalse();
});

it('counts each dimension of the dedupe key separately', function (string $dimension, array $change): void {
    $member = Member::factory()->create();
    $other = Member::factory()->create();
    $action = app(RecordSearch::class);

    $base = ['memberId' => $member->id, 'venueId' => 1, 'date' => '2026-11-06', 'hour' => 20, 'partySize' => 2];
    $changed = array_merge($base, $change);

    if ($dimension === 'member') {
        $changed['memberId'] = $other->id;
    }

    $action->handle(Searches::event(...$base));

    expect($action->handle(Searches::event(...$changed)))->toBeTrue()
        ->and(SearchEvent::query()->count())->toBe(2);
})->with([
    'party size' => ['party size', ['partySize' => 3]],
    'party size in the same band' => ['party size band', ['partySize' => 1]],
    'hour' => ['hour', ['hour' => 21]],
    'date' => ['date', ['date' => '2026-11-07']],
    'venue' => ['venue', ['venueId' => 2]],
    'member' => ['member', []],
]);

it('rejects an out-of-range party size at the database level', function (): void {
    $member = Member::factory()->create();

    DB::statement('SAVEPOINT bad_search');

    try {
        app(RecordSearch::class)->handle(Searches::event($member->id, partySize: 21));
    } finally {
        DB::statement('ROLLBACK TO SAVEPOINT bad_search');
    }
})->throws(QueryException::class, 'search_events_party_size_check');

it('rejects an out-of-range hour at the database level', function (): void {
    $member = Member::factory()->create();

    DB::statement('SAVEPOINT bad_hour');

    try {
        app(RecordSearch::class)->handle(Searches::event($member->id, hour: 24));
    } finally {
        DB::statement('ROLLBACK TO SAVEPOINT bad_hour');
    }
})->throws(QueryException::class, 'search_events_hour_check');
