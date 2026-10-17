<?php

declare(strict_types=1);

use App\Modules\Inventory\Models\Hold;
use App\Modules\Inventory\Models\Venue;
use App\Modules\Reservations\Actions\PlaceHold;
use App\Shared\Idempotency\IdempotencyKey;
use App\Shared\Outbox\OutboxEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\Modules\Inventory\Support\Fixtures;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-07 15:00:00.250000'));
    $this->member = Fixtures::member();
    $this->token = Fixtures::token($this->member);
});

function postHold(array $body, ?string $key = null, ?string $token = null)
{
    // The guard caches the resolved member between requests of one test; a different token must re-resolve.
    app('auth')->forgetGuards();

    return test()->withToken($token ?? test()->token)
        ->withHeaders(['Idempotency-Key' => $key ?? (string) Str::uuid()])
        ->postJson('/api/holds', $body);
}

it('creates a hold: 201, the C§8 Hold shape with its quote, held_count + 1, one slot.held row and the gate', function (): void {
    $slot = Fixtures::slot([
        'starts_at' => '2026-11-08 01:00:00',
        'released_at' => '2026-10-01 00:00:00',
        'capacity' => 6,
        'min_party_size' => 1,
        'max_party_size' => 2,
    ]);

    $response = postHold(['slot_id' => $slot->id, 'party_size' => 2])->assertCreated();

    $hold = Hold::query()->sole();

    expect($response->json())->toBe(['data' => [
        'id' => $hold->id,
        'status' => 'active',
        'party_size' => 2,
        'expires_at' => '2026-11-07T15:05:00Z',
        'slot' => [
            'id' => $slot->id,
            'venue_id' => $slot->venue_id,
            'drop_id' => null,
            'starts_at' => '2026-11-08T01:00:00Z',
            'local_starts_at' => '2026-11-07T20:00:00-05:00',
            'capacity' => 6,
            'available' => 5,
            'min_party_size' => 1,
            'max_party_size' => 2,
            'released_at' => '2026-10-01T00:00:00Z',
            'bookable' => true,
        ],
        'quote' => [
            'per_person_cents' => 37500,
            'amount_cents' => 75000,
            'currency' => 'USD',
            'expires_at' => '2026-11-07T15:05:00Z',
        ],
    ]]);

    expect($slot->fresh()->held_count)->toBe(1)
        ->and($hold->member_id)->toBe($this->member->id)
        ->and($hold->idempotency_key)->toStartWith("{$this->member->id}:")
        ->and(Fixtures::outbox('slot.held'))->toHaveCount(1)
        ->and(Fixtures::outbox('slot.held')->first()->payload['available'])->toBe(5)
        ->and(Fixtures::gate($slot))->toBe(5);
});

it('requires a token and an Idempotency-Key', function (): void {
    $slot = Fixtures::slot();

    $this->postJson('/api/holds', ['slot_id' => $slot->id, 'party_size' => 2], ['Idempotency-Key' => 'x'])
        ->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');

    $this->withToken($this->token)->postJson('/api/holds', ['slot_id' => $slot->id, 'party_size' => 2])
        ->assertStatus(400)->assertJsonPath('error.code', 'idempotency_key_required');

    $this->withToken($this->token)->withHeaders(['Idempotency-Key' => 'bad key!'])->postJson('/api/holds', ['slot_id' => $slot->id, 'party_size' => 2])
        ->assertStatus(400)->assertJsonPath('error.code', 'idempotency_key_invalid');

    expect(Hold::query()->count())->toBe(0);
});

it('validates the body', function (array $body): void {
    postHold($body)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

    expect(Hold::query()->count())->toBe(0);
})->with([
    'empty' => [[]],
    'no slot' => [['party_size' => 2]],
    'no party' => [['slot_id' => 1]],
    'party 0' => [['slot_id' => 1, 'party_size' => 0]],
    'party 21' => [['slot_id' => 1, 'party_size' => 21]],
    'slot string' => [['slot_id' => 'abc', 'party_size' => 2]],
    'slot negative' => [['slot_id' => -1, 'party_size' => 2]],
    'party fractional' => [['slot_id' => 1, 'party_size' => 1.5]],
]);

it('maps domain failures to C§9 codes', function (): void {
    $venue = Venue::factory()->create();
    $full = Fixtures::slot(['capacity' => 1, 'booked_count' => 1], $venue);
    $past = Fixtures::slot(['starts_at' => '2026-11-07 14:00:00', 'released_at' => '2026-10-01 00:00:00', 'min_party_size' => 3, 'max_party_size' => 3], $venue);
    $drop = Fixtures::drop($venue);
    $closedDrop = Fixtures::slot(['drop_id' => $drop->id, 'released_at' => '2026-11-07 16:00:00', 'min_party_size' => 4, 'max_party_size' => 4], $venue);
    $narrow = Fixtures::slot(['min_party_size' => 2, 'max_party_size' => 4], $venue);
    $inactive = Fixtures::slot([], Venue::factory()->inactive()->create());

    postHold(['slot_id' => $full->id, 'party_size' => 2])->assertStatus(409)->assertJsonPath('error.code', 'sold_out');
    postHold(['slot_id' => $past->id, 'party_size' => 3])->assertStatus(409)->assertJsonPath('error.code', 'slot_unavailable');
    postHold(['slot_id' => $inactive->id, 'party_size' => 2])->assertStatus(409)->assertJsonPath('error.code', 'slot_unavailable');
    postHold(['slot_id' => $closedDrop->id, 'party_size' => 4])->assertStatus(409)->assertJsonPath('error.code', 'drop_not_open');
    postHold(['slot_id' => 999_999, 'party_size' => 2])->assertNotFound()->assertJsonPath('error.code', 'not_found');
    postHold(['slot_id' => $narrow->id, 'party_size' => 5])->assertStatus(422)
        ->assertJsonPath('error.code', 'party_size_not_allowed')
        ->assertJsonPath('error.details', ['min_party_size' => 2, 'max_party_size' => 4]);

    expect(Hold::query()->count())->toBe(0)
        ->and(OutboxEvent::query()->count())->toBe(0);
});

it('answers 409 active_hold_exists to a second hold with a different key', function (): void {
    $slot = Fixtures::slot(['capacity' => 4]);

    postHold(['slot_id' => $slot->id, 'party_size' => 2])->assertCreated();
    postHold(['slot_id' => $slot->id, 'party_size' => 2])->assertStatus(409)->assertJsonPath('error.code', 'active_hold_exists');

    expect(Hold::query()->count())->toBe(1)
        ->and($slot->fresh()->held_count)->toBe(1)
        ->and(Fixtures::gate($slot))->toBe(3);
});

it('accepts a hold at exactly released_at', function (): void {
    $venue = Venue::factory()->create();
    $drop = Fixtures::drop($venue, ['opens_at' => '2026-11-07 15:00:00.250000']);
    $slot = Fixtures::slot(['drop_id' => $drop->id, 'released_at' => '2026-11-07 15:00:00.250000'], $venue);

    postHold(['slot_id' => $slot->id, 'party_size' => 2])->assertCreated();
});

it('shows an active hold, hides it from other members, and 404s unknown ids', function (): void {
    $slot = Fixtures::slot();
    $id = postHold(['slot_id' => $slot->id, 'party_size' => 2])->json('data.id');

    $this->withToken($this->token)->getJson("/api/holds/{$id}")->assertOk()
        ->assertJsonPath('data.id', $id)->assertJsonPath('data.status', 'active')->assertJsonPath('data.quote.amount_cents', 50000)->assertJsonPath('data.quote.per_person_cents', 25000);

    $other = Fixtures::token(Fixtures::member());
    app('auth')->forgetGuards();
    $this->withToken($other)->getJson("/api/holds/{$id}")->assertNotFound()->assertJsonPath('error.code', 'not_found');
    app('auth')->forgetGuards();
    $this->withToken($this->token)->getJson('/api/holds/999999')->assertNotFound()->assertJsonPath('error.code', 'not_found');
    app('auth')->forgetGuards();
    $this->flushHeaders()->getJson("/api/holds/{$id}")->assertUnauthorized();
});

it('expires a hold on read: active at expires_at - 1s, expired exactly at expires_at with the slot restored', function (): void {
    $slot = Fixtures::slot(['capacity' => 3]);
    $response = postHold(['slot_id' => $slot->id, 'party_size' => 2])->assertCreated();
    $id = $response->json('data.id');
    $expiresAt = Hold::query()->findOrFail($id)->expires_at;

    $this->travelTo($expiresAt->subSecond());
    $this->withToken($this->token)->getJson("/api/holds/{$id}")->assertOk()->assertJsonPath('data.status', 'active');
    expect($slot->fresh()->held_count)->toBe(1);

    $this->travelTo($expiresAt);
    $this->withToken($this->token)->getJson("/api/holds/{$id}")->assertOk()
        ->assertJsonPath('data.status', 'expired')
        ->assertJsonPath('data.slot.available', 3);

    expect($slot->fresh()->held_count)->toBe(0)
        ->and(Fixtures::gate($slot))->toBe(3)
        ->and(Fixtures::outbox('slot.released'))->toHaveCount(1)
        ->and(Fixtures::outbox('slot.released')->first()->payload['reason'])->toBe('expired');

    $this->withToken($this->token)->getJson("/api/holds/{$id}")->assertJsonPath('data.status', 'expired');
    expect(Fixtures::outbox('slot.released'))->toHaveCount(1);
});

it('expires at the microsecond: one microsecond before expires_at is still active', function (): void {
    $slot = Fixtures::slot();
    $id = postHold(['slot_id' => $slot->id, 'party_size' => 2])->json('data.id');
    $expiresAt = Hold::query()->findOrFail($id)->expires_at;

    expect($expiresAt->format('u'))->toBe('250000');

    $this->travelTo($expiresAt->subMicrosecond());
    $this->withToken($this->token)->getJson("/api/holds/{$id}")->assertJsonPath('data.status', 'active');

    $this->travelTo($expiresAt);
    $this->withToken($this->token)->getJson("/api/holds/{$id}")->assertJsonPath('data.status', 'expired');
});

it('replays the first response byte for byte: same key and body, no second hold, outbox row or gate unit', function (): void {
    $slot = Fixtures::slot(['capacity' => 4]);
    $key = (string) Str::uuid();

    $first = postHold(['slot_id' => $slot->id, 'party_size' => 2], $key)->assertCreated();
    $second = postHold(['slot_id' => $slot->id, 'party_size' => 2], $key);

    $second->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect($second->getContent())->toBe($first->getContent())
        ->and($first->headers->get('Idempotent-Replayed'))->toBeNull()
        ->and(Hold::query()->count())->toBe(1)
        ->and(OutboxEvent::query()->count())->toBe(1)
        ->and($slot->fresh()->held_count)->toBe(1)
        ->and(Fixtures::gate($slot))->toBe(3);
});

it('replays a stored 409 sold_out for the same key instead of re-executing', function (): void {
    $slot = Fixtures::slot(['capacity' => 1, 'booked_count' => 1]);
    $key = (string) Str::uuid();

    $first = postHold(['slot_id' => $slot->id, 'party_size' => 2], $key)->assertStatus(409);

    $slot->forceFill(['booked_count' => 0])->save();
    Redis::connection()->flushdb();

    $second = postHold(['slot_id' => $slot->id, 'party_size' => 2], $key);

    $second->assertStatus(409)->assertHeader('Idempotent-Replayed', 'true');
    expect($second->getContent())->toBe($first->getContent())
        ->and(Hold::query()->count())->toBe(0);
});

it('rejects the same key with a different party_size as 422 idempotency_mismatch and changes nothing', function (): void {
    $slot = Fixtures::slot(['capacity' => 4]);
    $key = (string) Str::uuid();

    postHold(['slot_id' => $slot->id, 'party_size' => 2], $key)->assertCreated();
    postHold(['slot_id' => $slot->id, 'party_size' => 3], $key)->assertStatus(422)->assertJsonPath('error.code', 'idempotency_mismatch');
    postHold(['slot_id' => $slot->id + 1, 'party_size' => 2], $key)->assertStatus(422)->assertJsonPath('error.code', 'idempotency_mismatch');

    expect(Hold::query()->count())->toBe(1)
        ->and(OutboxEvent::query()->count())->toBe(1)
        ->and(Fixtures::gate($slot))->toBe(3);
});

it('scopes keys per member: two members may use the same key on one slot', function (): void {
    $slot = Fixtures::slot(['capacity' => 4]);
    $otherToken = Fixtures::token(Fixtures::member());

    postHold(['slot_id' => $slot->id, 'party_size' => 2], 'shared-key')->assertCreated();
    postHold(['slot_id' => $slot->id, 'party_size' => 2], 'shared-key', $otherToken)->assertCreated();

    expect(Hold::query()->count())->toBe(2)
        ->and(Hold::query()->pluck('idempotency_key')->unique())->toHaveCount(2);
});

it('lets PlaceHold return a HoldView whose quote is the stored price_quotes row', function (): void {
    $slot = Fixtures::slot();

    $view = app(PlaceHold::class)->handle($this->member->id, $slot->id, 2, new IdempotencyKey($this->member->id, 'abc'));

    expect($view->quote)->not->toBeNull()
        ->and($view->quote?->amount->cents)->toBe((int) DB::table('price_quotes')->where('hold_id', $view->hold->id)->value('amount_cents'))
        ->and($view->hold->partySize)->toBe(2)
        ->and(Hold::query()->sole()->idempotency_key)->toBe("{$this->member->id}:abc");
});
