<?php

declare(strict_types=1);

use App\Modules\Inventory\Models\City;
use App\Modules\Inventory\Models\Venue;
use Carbon\CarbonImmutable;
use Tests\Modules\Inventory\Support\Fixtures;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00'));
    $this->token = Fixtures::token(Fixtures::member());
});

function catalogGet(string $uri)
{
    return test()->withToken(test()->token)->getJson($uri);
}

it('requires authentication on every inventory endpoint', function (string $uri): void {
    $this->getJson($uri)->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
})->with(['/api/cities', '/api/venues', '/api/venues/1', '/api/venues/1/availability', '/api/slots/1', '/api/drops/1']);

it('lists cities ordered by name', function (): void {
    $zurich = City::factory()->create(['name' => 'Zurich', 'slug' => 'zurich', 'country_code' => 'CH']);
    $austin = City::factory()->create(['name' => 'Austin', 'slug' => 'austin']);

    catalogGet('/api/cities')->assertOk()->assertExactJson(['data' => [
        ['id' => $austin->id, 'name' => 'Austin', 'slug' => 'austin', 'country_code' => 'US'],
        ['id' => $zurich->id, 'name' => 'Zurich', 'slug' => 'zurich', 'country_code' => 'CH'],
    ]]);
});

it('lists active venues by name, optionally filtered by city', function (): void {
    $nyc = City::factory()->create();
    $chi = City::factory()->create();
    $b = Venue::factory()->create(['city_id' => $nyc->id, 'name' => 'Beta']);
    $a = Venue::factory()->create(['city_id' => $nyc->id, 'name' => 'Alpha']);
    Venue::factory()->inactive()->create(['city_id' => $nyc->id, 'name' => 'Closed']);
    $c = Venue::factory()->create(['city_id' => $chi->id, 'name' => 'Gamma', 'timezone' => 'America/Chicago']);

    expect(catalogGet('/api/venues')->assertOk()->json('data.*.id'))->toBe([$a->id, $b->id, $c->id])
        ->and(catalogGet("/api/venues?city_id={$chi->id}")->assertOk()->json('data.*.id'))->toBe([$c->id])
        ->and(catalogGet('/api/venues?city_id=999999')->assertOk()->json('data'))->toBe([]);

    catalogGet('/api/venues?city_id=abc')->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
});

it('shows a venue with the C§8 shape, including an inactive one', function (): void {
    $venue = Venue::factory()->create(['name' => 'Carbone', 'slug' => 'carbone', 'base_per_person_cents' => 25000]);

    catalogGet("/api/venues/{$venue->id}")->assertOk()->assertExactJson(['data' => [
        'id' => $venue->id,
        'city_id' => $venue->city_id,
        'name' => 'Carbone',
        'slug' => 'carbone',
        'timezone' => 'America/New_York',
        'status' => 'active',
        'base_per_person_cents' => 25000,
        'currency' => 'USD',
    ]]);

    $inactive = Venue::factory()->inactive()->create();
    catalogGet("/api/venues/{$inactive->id}")->assertOk()->assertJsonPath('data.status', 'inactive');
});

it('returns 404 not_found for an unknown venue, slot, drop and route', function (string $uri): void {
    catalogGet($uri)->assertNotFound()->assertJsonPath('error.code', 'not_found');
})->with(['/api/venues/999999', '/api/slots/999999', '/api/drops/999999', '/api/venues/abc', '/api/slots/abc']);

it('shows a slot with the exact C§8 shape in venue-local time', function (): void {
    $slot = Fixtures::slot([
        'starts_at' => CarbonImmutable::parse('2026-11-08T01:00:00Z'),
        'released_at' => CarbonImmutable::parse('2026-10-01T00:00:00Z'),
        'capacity' => 6,
        'held_count' => 3,
        'booked_count' => 1,
        'min_party_size' => 1,
        'max_party_size' => 2,
    ]);

    catalogGet("/api/slots/{$slot->id}")->assertOk()->assertExactJson(['data' => [
        'id' => $slot->id,
        'venue_id' => $slot->venue_id,
        'drop_id' => null,
        'starts_at' => '2026-11-08T01:00:00Z',
        'local_starts_at' => '2026-11-07T20:00:00-05:00',
        'capacity' => 6,
        'available' => 2,
        'min_party_size' => 1,
        'max_party_size' => 2,
        'released_at' => '2026-10-01T00:00:00Z',
        'bookable' => true,
    ]]);
});

it('marks a slot bookable only when released, upcoming and with a free table', function (array $override, bool $bookable): void {
    $slot = Fixtures::slot($override);

    catalogGet("/api/slots/{$slot->id}")->assertOk()->assertJsonPath('data.bookable', $bookable);
})->with([
    'open' => [[], true],
    'full of holds' => [['capacity' => 2, 'held_count' => 2], false],
    'full of bookings' => [['capacity' => 2, 'booked_count' => 2], false],
    'last table free' => [['capacity' => 2, 'held_count' => 1], true],
    'not released yet' => [['released_at' => '2026-10-20 12:00:01'], false],
    'released exactly now' => [['released_at' => '2026-10-20 12:00:00'], true],
    'already started' => [['starts_at' => '2026-10-20 11:59:59', 'released_at' => '2026-10-01 00:00:00'], false],
    'starts exactly now' => [['starts_at' => '2026-10-20 12:00:00', 'released_at' => '2026-10-01 00:00:00'], false],
]);

it('shows a drop with the C§8 shape and its slot ids in order', function (): void {
    $venue = Venue::factory()->create();
    $drop = Fixtures::drop($venue, ['opens_at' => CarbonImmutable::parse('2026-10-21 16:00:00')]);
    $a = Fixtures::slot(['drop_id' => $drop->id, 'min_party_size' => 1, 'max_party_size' => 2], $venue);
    $b = Fixtures::slot(['drop_id' => $drop->id, 'min_party_size' => 3, 'max_party_size' => 4], $venue);
    Fixtures::slot([], $venue);

    catalogGet("/api/drops/{$drop->id}")->assertOk()->assertExactJson(['data' => [
        'id' => $drop->id,
        'venue_id' => $venue->id,
        'opens_at' => '2026-10-21T16:00:00Z',
        'opened' => false,
        'sold_out' => false,
        'slot_ids' => [$a->id, $b->id],
    ]]);

    $drop->forceFill(['opened_at' => now(), 'sold_out_at' => now()])->save();

    catalogGet("/api/drops/{$drop->id}")->assertJsonPath('data.opened', true)->assertJsonPath('data.sold_out', true);
});
