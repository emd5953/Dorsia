<?php

declare(strict_types=1);

use App\Modules\Inventory\Events\AvailabilitySearched;
use App\Modules\Inventory\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Tests\Modules\Inventory\Support\Fixtures;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00'));
    $this->member = Fixtures::member();
    $this->token = Fixtures::token($this->member);
});

function availability(Venue $venue, string $date, int $hour, int $party)
{
    return test()->withToken(test()->token)->getJson("/api/venues/{$venue->id}/availability?date={$date}&hour={$hour}&party_size={$party}");
}

it('returns every slot of the venue-local date ordered by starts_at, with the C§8 wrapper', function (): void {
    $venue = Venue::factory()->create();
    $late = Fixtures::slot(['starts_at' => '2026-10-25 02:00:00', 'min_party_size' => 1, 'max_party_size' => 2], $venue); // 22:00 EDT on the 24th
    $early = Fixtures::slot(['starts_at' => '2026-10-24 22:00:00', 'min_party_size' => 3, 'max_party_size' => 4], $venue); // 18:00 EDT on the 24th
    Fixtures::slot(['starts_at' => '2026-10-25 04:00:00'], $venue); // 00:00 EDT on the 25th: next day
    Fixtures::slot(['starts_at' => '2026-10-24 03:59:59'], $venue); // 23:59:59 EDT on the 23rd: previous day
    Fixtures::slot(['starts_at' => '2026-10-24 22:00:00'], Venue::factory()->create()); // another venue

    $response = availability($venue, '2026-10-24', 18, 3)->assertOk();

    expect($response->json('data.slots.*.id'))->toBe([$early->id, $late->id])
        ->and($response->json('data.venue_id'))->toBe($venue->id)
        ->and($response->json('data.timezone'))->toBe('America/New_York')
        ->and($response->json('data.date'))->toBe('2026-10-24')
        ->and($response->json('data.hour'))->toBe(18)
        ->and($response->json('data.party_size'))->toBe(3)
        ->and($response->json('data.had_availability'))->toBeTrue()
        ->and($response->json('data.slots.0.local_starts_at'))->toBe('2026-10-24T18:00:00-04:00');
});

it('includes the first local instant of the day and excludes the first instant of the next', function (): void {
    $venue = Venue::factory()->create();
    $first = Fixtures::slot(['starts_at' => '2026-10-24 04:00:00'], $venue);
    Fixtures::slot(['starts_at' => '2026-10-25 04:00:00'], $venue);

    expect(availability($venue, '2026-10-24', 0, 1)->json('data.slots.*.id'))->toBe([$first->id]);
});

it('computes had_availability per C§8', function (array $override, int $hour, int $party, bool $expected): void {
    $venue = Venue::factory()->create();
    Fixtures::slot(array_merge(['starts_at' => '2026-10-24 23:00:00', 'min_party_size' => 2, 'max_party_size' => 4, 'capacity' => 2], $override), $venue); // 19:00 EDT

    availability($venue, '2026-10-24', $hour, $party)->assertOk()->assertJsonPath('data.had_availability', $expected);
})->with([
    'fits' => [[], 19, 3, true],
    'party at min' => [[], 19, 2, true],
    'party at max' => [[], 19, 4, true],
    'party below min' => [[], 19, 1, false],
    'party above max' => [[], 19, 5, false],
    'other hour' => [[], 20, 3, false],
    'held out' => [['held_count' => 2], 19, 3, false],
    'booked out' => [['booked_count' => 2], 19, 3, false],
    'one table left' => [['held_count' => 1], 19, 3, true],
    'not yet released' => [['released_at' => '2026-10-20 12:00:01'], 19, 3, false],
    'released exactly now' => [['released_at' => '2026-10-20 12:00:00'], 19, 3, true],
]);

it('still lists slots that are not bookable when had_availability is false', function (): void {
    $venue = Venue::factory()->create();
    $slot = Fixtures::slot(['starts_at' => '2026-10-24 23:00:00', 'capacity' => 1, 'booked_count' => 1], $venue);

    $response = availability($venue, '2026-10-24', 19, 2)->assertOk();

    expect($response->json('data.slots.*.id'))->toBe([$slot->id])
        ->and($response->json('data.slots.0.bookable'))->toBeFalse()
        ->and($response->json('data.slots.0.available'))->toBe(0);
});

it('has had_availability false for a past hour of today', function (): void {
    $venue = Venue::factory()->create();
    Fixtures::slot(['starts_at' => '2026-10-20 11:00:00', 'released_at' => '2026-10-01 00:00:00'], $venue); // 07:00 EDT, already started

    availability($venue, '2026-10-20', 7, 2)->assertOk()->assertJsonPath('data.had_availability', false);
});

it('dispatches exactly one AvailabilitySearched with venue-local date and hour for a New York venue', function (): void {
    Event::fake([AvailabilitySearched::class]);
    $venue = Venue::factory()->create();
    Fixtures::slot(['starts_at' => '2026-10-25 01:00:00'], $venue); // 21:00 EDT on the 24th

    availability($venue, '2026-10-24', 21, 2)->assertOk();

    Event::assertDispatchedTimes(AvailabilitySearched::class, 1);
    Event::assertDispatched(AvailabilitySearched::class, fn (AvailabilitySearched $e): bool => $e->memberId === $this->member->id
        && $e->venueId === $venue->id
        && $e->venueTimezone === 'America/New_York'
        && $e->requestedDate === '2026-10-24'
        && $e->requestedHour === 21
        && $e->partySize === 2
        && $e->hadAvailability === true
        && $e->searchedAt->equalTo(now()));
});

it('dispatches the event with hadAvailability false when nothing fits', function (): void {
    Event::fake([AvailabilitySearched::class]);
    $venue = Venue::factory()->create();

    availability($venue, '2026-10-24', 21, 2)->assertOk();

    Event::assertDispatched(AvailabilitySearched::class, fn (AvailabilitySearched $e): bool => $e->hadAvailability === false);
});

it('handles Honolulu, which has no daylight saving, across the US change on 2026-11-01', function (): void {
    Event::fake([AvailabilitySearched::class]);
    $venue = Venue::factory()->timezone('Pacific/Honolulu')->create();
    $slot = Fixtures::slot(['starts_at' => '2026-11-02 06:00:00'], $venue); // 20:00 HST on 11-01
    $before = Fixtures::slot(['starts_at' => '2026-11-01 09:59:59', 'min_party_size' => 3, 'max_party_size' => 4], $venue); // 23:59:59 HST on 10-31
    $after = Fixtures::slot(['starts_at' => '2026-11-02 10:00:00', 'min_party_size' => 5, 'max_party_size' => 6], $venue); // 00:00 HST on 11-02

    $response = availability($venue, '2026-11-01', 20, 2)->assertOk();

    expect($response->json('data.slots.*.id'))->toBe([$slot->id])
        ->and($response->json('data.slots.0.local_starts_at'))->toBe('2026-11-01T20:00:00-10:00')
        ->and($response->json('data.had_availability'))->toBeTrue()
        ->and($before->id)->not->toBe($after->id);

    Event::assertDispatched(AvailabilitySearched::class, fn (AvailabilitySearched $e): bool => $e->requestedDate === '2026-11-01'
        && $e->requestedHour === 20
        && $e->venueTimezone === 'Pacific/Honolulu');
});

it('treats the 25-hour New York day of 2026-11-01 as one local date', function (): void {
    Event::fake([AvailabilitySearched::class]);
    $venue = Venue::factory()->create();
    $firstOne = Fixtures::slot(['starts_at' => '2026-11-01 05:00:00', 'min_party_size' => 1, 'max_party_size' => 1], $venue); // 01:00 EDT
    $secondOne = Fixtures::slot(['starts_at' => '2026-11-01 06:00:00', 'min_party_size' => 2, 'max_party_size' => 2], $venue); // 01:00 EST
    $evening = Fixtures::slot(['starts_at' => '2026-11-02 01:00:00'], $venue); // 20:00 EST
    $lastMinute = Fixtures::slot(['starts_at' => '2026-11-02 04:59:59', 'min_party_size' => 7, 'max_party_size' => 8], $venue); // 23:59:59 EST
    Fixtures::slot(['starts_at' => '2026-11-02 05:00:00', 'min_party_size' => 9, 'max_party_size' => 10], $venue); // next local day
    Fixtures::slot(['starts_at' => '2026-11-01 03:59:59', 'min_party_size' => 11, 'max_party_size' => 12], $venue); // previous local day

    $response = availability($venue, '2026-11-01', 20, 2)->assertOk();

    expect($response->json('data.slots.*.id'))->toBe([$firstOne->id, $secondOne->id, $evening->id, $lastMinute->id])
        ->and($response->json('data.slots.*.local_starts_at'))->toBe([
            '2026-11-01T01:00:00-04:00',
            '2026-11-01T01:00:00-05:00',
            '2026-11-01T20:00:00-05:00',
            '2026-11-01T23:59:59-05:00',
        ])
        ->and($response->json('data.had_availability'))->toBeTrue();

    // Local hour 1 happens twice that day: both slots match it.
    $oneAm = availability($venue, '2026-11-01', 1, 1)->assertOk();
    expect($oneAm->json('data.had_availability'))->toBeTrue();

    Event::assertDispatched(AvailabilitySearched::class, fn (AvailabilitySearched $e): bool => $e->requestedDate === '2026-11-01' && $e->requestedHour === 20);
});

it('keeps a spring-forward local date (23 hours) whole', function (): void {
    $venue = Venue::factory()->create();
    $first = Fixtures::slot(['starts_at' => '2027-03-14 05:00:00'], $venue); // 00:00 EST
    $last = Fixtures::slot(['starts_at' => '2027-03-15 03:59:59', 'min_party_size' => 5, 'max_party_size' => 6], $venue); // 23:59:59 EDT
    Fixtures::slot(['starts_at' => '2027-03-15 04:00:00', 'min_party_size' => 7, 'max_party_size' => 8], $venue); // next day

    $this->travelTo(CarbonImmutable::parse('2027-03-01 12:00:00'));

    expect(availability($venue, '2027-03-14', 0, 1)->json('data.slots.*.id'))->toBe([$first->id, $last->id]);
});

it('validates the query and returns 404 for an unknown venue', function (string $query): void {
    $venue = Venue::factory()->create();

    test()->withToken(test()->token)->getJson("/api/venues/{$venue->id}/availability{$query}")
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
})->with([
    'nothing' => [''],
    'bad date' => ['?date=2026-13-01&hour=1&party_size=2'],
    'date format' => ['?date=10/24/2026&hour=1&party_size=2'],
    'hour too high' => ['?date=2026-10-24&hour=24&party_size=2'],
    'hour negative' => ['?date=2026-10-24&hour=-1&party_size=2'],
    'party zero' => ['?date=2026-10-24&hour=1&party_size=0'],
    'party 21' => ['?date=2026-10-24&hour=1&party_size=21'],
    'missing party' => ['?date=2026-10-24&hour=1'],
]);

it('accepts the boundary values hour 0, hour 23, party 1 and party 20', function (int $hour, int $party): void {
    $venue = Venue::factory()->create();

    availability($venue, '2026-10-24', $hour, $party)->assertOk();
})->with([[0, 1], [23, 20]]);

it('returns 404 for an unknown venue availability and does not dispatch the event', function (): void {
    Event::fake([AvailabilitySearched::class]);

    test()->withToken(test()->token)->getJson('/api/venues/999999/availability?date=2026-10-24&hour=1&party_size=2')
        ->assertNotFound()->assertJsonPath('error.code', 'not_found');

    Event::assertNotDispatched(AvailabilitySearched::class);
});
