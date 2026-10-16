<?php

declare(strict_types=1);

use App\Shared\Idempotency\IdempotencyRecord;
use App\Shared\Members\Member;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

const IDEMPOTENCY_TEST_URI = '/api/_test/idempotent';

beforeEach(function (): void {
    $this->handlerCalls = 0;
    $this->failNext = false;

    Route::middleware(['api', 'auth:sanctum', 'idempotent'])
        ->post(IDEMPOTENCY_TEST_URI, function () {
            $this->handlerCalls++;

            if ($this->failNext) {
                $this->failNext = false;

                throw new RuntimeException('boom');
            }

            return response()->json(['data' => ['call' => $this->handlerCalls]], 201);
        });

    Route::middleware(['api', 'auth:sanctum', 'idempotent'])
        ->post('/api/_test/idempotent-conflict', function () {
            $this->handlerCalls++;

            return response()->json(['error' => ['code' => 'sold_out', 'message' => 'x', 'details' => (object) []]], 409);
        });

    $this->member = Member::factory()->create();
    $this->token = $this->member->createToken('test')->plainTextToken;
});

/**
 * @param  array<string, mixed>  $body
 */
function idempotentPost(object $test, string $token, ?string $key, array $body = ['a' => 1], string $uri = IDEMPOTENCY_TEST_URI): TestResponse
{
    app('auth')->forgetGuards();

    $headers = $key === null ? [] : ['Idempotency-Key' => $key];

    return $test->withToken($token)->postJson($uri, $body, $headers);
}

it('rejects a missing key with 400 idempotency_key_required', function (): void {
    idempotentPost($this, $this->token, null)
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'idempotency_key_required');

    expect($this->handlerCalls)->toBe(0);
});

it('rejects an invalid key with 400 idempotency_key_invalid', function (): void {
    idempotentPost($this, $this->token, 'not a valid key!')
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'idempotency_key_invalid');

    idempotentPost($this, $this->token, str_repeat('k', 201))
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'idempotency_key_invalid');

    expect($this->handlerCalls)->toBe(0);
});

it('stores the first call as completed', function (): void {
    idempotentPost($this, $this->token, 'first-1')
        ->assertCreated()
        ->assertHeaderMissing('Idempotent-Replayed')
        ->assertJsonPath('data.call', 1);

    $row = IdempotencyRecord::query()->sole();

    expect($row->member_id)->toBe($this->member->id)
        ->and($row->key)->toBe('first-1')
        ->and($row->method)->toBe('POST')
        ->and($row->path)->toBe(IDEMPOTENCY_TEST_URI)
        ->and($row->request_hash)->toHaveLength(64)
        ->and($row->status)->toBe('completed')
        ->and($row->response_status)->toBe(201)
        ->and(json_decode((string) $row->getRawOriginal('response_body'), true))->toBe(['data' => ['call' => 1]])
        ->and($row->completed_at)->not->toBeNull();
});

it('replays status, body and header for the same key and body without re-running the handler', function (): void {
    $first = idempotentPost($this, $this->token, 'replay-1');
    $second = idempotentPost($this, $this->token, 'replay-1');

    $second->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertExactJson($first->json());

    expect($this->handlerCalls)->toBe(1)
        ->and(IdempotencyRecord::query()->count())->toBe(1);
});

it('replays stored 4xx responses (D6)', function (): void {
    $uri = '/api/_test/idempotent-conflict';

    idempotentPost($this, $this->token, 'conflict-1', uri: $uri)->assertStatus(409);

    idempotentPost($this, $this->token, 'conflict-1', uri: $uri)
        ->assertStatus(409)
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('error.code', 'sold_out');

    expect($this->handlerCalls)->toBe(1);
});

it('returns 422 idempotency_mismatch for the same key with a different body', function (): void {
    idempotentPost($this, $this->token, 'mismatch-1', ['a' => 1])->assertCreated();

    idempotentPost($this, $this->token, 'mismatch-1', ['a' => 2])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'idempotency_mismatch');

    expect($this->handlerCalls)->toBe(1);
});

it('returns 422 idempotency_mismatch for the same key on a different path', function (): void {
    idempotentPost($this, $this->token, 'path-1')->assertCreated();

    idempotentPost($this, $this->token, 'path-1', uri: '/api/_test/idempotent-conflict')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'idempotency_mismatch');
});

it('treats the same key from a different member as independent', function (): void {
    $other = Member::factory()->create();
    $otherToken = $other->createToken('test')->plainTextToken;

    idempotentPost($this, $this->token, 'shared-key')->assertCreated()->assertJsonPath('data.call', 1);
    idempotentPost($this, $otherToken, 'shared-key')
        ->assertCreated()
        ->assertHeaderMissing('Idempotent-Replayed')
        ->assertJsonPath('data.call', 2);

    expect($this->handlerCalls)->toBe(2)
        ->and(IdempotencyRecord::query()->count())->toBe(2);
});

it('does not store a 5xx response and re-executes on retry', function (): void {
    $this->failNext = true;

    idempotentPost($this, $this->token, 'retry-1')
        ->assertStatus(500)
        ->assertJsonPath('error.code', 'server_error');

    expect(IdempotencyRecord::query()->count())->toBe(0);

    idempotentPost($this, $this->token, 'retry-1')
        ->assertCreated()
        ->assertHeaderMissing('Idempotent-Replayed');

    expect($this->handlerCalls)->toBe(2)
        ->and(IdempotencyRecord::query()->where('status', 'completed')->count())->toBe(1);
});

it('hashes canonical JSON so key order does not matter', function (): void {
    idempotentPost($this, $this->token, 'canon-1', ['a' => 1, 'b' => ['x' => 1, 'y' => [3, 2, 1]]])->assertCreated();

    idempotentPost($this, $this->token, 'canon-1', ['b' => ['y' => [3, 2, 1], 'x' => 1], 'a' => 1])
        ->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true');

    // List order is significant, so this is a different request.
    idempotentPost($this, $this->token, 'canon-1', ['a' => 1, 'b' => ['x' => 1, 'y' => [1, 2, 3]]])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'idempotency_mismatch');

    expect($this->handlerCalls)->toBe(1);
});

it('takes over a stale processing row older than 30 seconds', function (): void {
    idempotentPost($this, $this->token, 'stale-1')->assertCreated();

    DB::table('idempotency_keys')->update([
        'status' => 'processing',
        'response_status' => null,
        'response_body' => null,
        'completed_at' => null,
        'locked_at' => now()->subSeconds(31),
    ]);

    idempotentPost($this, $this->token, 'stale-1')
        ->assertCreated()
        ->assertHeaderMissing('Idempotent-Replayed')
        ->assertJsonPath('data.call', 2);

    $row = IdempotencyRecord::query()->sole();

    expect($this->handlerCalls)->toBe(2)
        ->and($row->status)->toBe('completed')
        ->and($row->response_status)->toBe(201);
});

it('answers 409 idempotency_in_progress with Retry-After for a fresh processing row', function (): void {
    idempotentPost($this, $this->token, 'busy-1')->assertCreated();

    DB::table('idempotency_keys')->update([
        'status' => 'processing',
        'response_status' => null,
        'response_body' => null,
        'completed_at' => null,
        'locked_at' => now()->subSeconds(5),
    ]);

    idempotentPost($this, $this->token, 'busy-1')
        ->assertStatus(409)
        ->assertHeader('Retry-After', '1')
        ->assertJsonPath('error.code', 'idempotency_in_progress');

    expect($this->handlerCalls)->toBe(1)
        ->and(IdempotencyRecord::query()->sole()->status)->toBe('processing');
});

it('prunes only rows older than 24 hours', function (): void {
    $make = fn (string $key, int $hoursAgo) => IdempotencyRecord::query()->create([
        'member_id' => $this->member->id,
        'key' => $key,
        'method' => 'POST',
        'path' => '/x',
        'request_hash' => str_repeat('a', 64),
        'status' => 'completed',
        'locked_at' => now()->subHours($hoursAgo),
        'created_at' => now()->subHours($hoursAgo),
    ]);

    $make('old', 25);
    $make('recent', 23);

    Artisan::call('idempotency:prune');

    expect(IdempotencyRecord::query()->pluck('key')->all())->toBe(['recent']);
});
