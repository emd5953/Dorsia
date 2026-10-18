<?php

declare(strict_types=1);

use App\Shared\Idempotency\IdempotencyRecord;
use App\Shared\Members\Member;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

const HARDENING_URI = '/api/_test/hardening';

beforeEach(function (): void {
    $this->handlerCalls = 0;

    Route::middleware(['api', 'auth:sanctum', 'idempotent'])
        ->post(HARDENING_URI, function () {
            $this->handlerCalls++;

            return response()->json(['data' => ['call' => $this->handlerCalls]], 201);
        });

    Route::middleware(['api', 'auth:sanctum', 'idempotent'])
        ->post('/api/_test/hardening-bytes', function () {
            $this->handlerCalls++;

            return response(
                '{"z":1,"a":{},"list":[],"f":1.0,"path":"\/x","uni":"é"}',
                201,
                ['Content-Type' => 'application/json'],
            );
        });

    $this->member = Member::factory()->create();
    $this->token = $this->member->createToken('test')->plainTextToken;
});

function hardeningPost(object $test, string $key, string $rawBody = '{"a":1}', string $uri = HARDENING_URI): TestResponse
{
    app('auth')->forgetGuards();

    return $test->call('POST', $uri, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$test->token,
        'HTTP_IDEMPOTENCY_KEY' => $key,
    ], $rawBody);
}

/**
 * Runs $mutate once, right after the middleware's SELECT of the idempotency row, that is,
 * between its read and its takeover UPDATE.
 */
function hardeningAfterRead(Closure $mutate): void
{
    $fired = false;

    DB::listen(function ($query) use (&$fired, $mutate): void {
        if ($fired || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, '"idempotency_keys"')) {
            return;
        }

        $fired = true;
        $mutate();
    });
}

function hardeningMakeStale(object $test): void
{
    hardeningPost($test, 'cas-1')->assertCreated();

    DB::table('idempotency_keys')->update([
        'status' => 'processing',
        'response_status' => null,
        'response_body' => null,
        'response_content_type' => null,
        'completed_at' => null,
        'locked_at' => now()->subSeconds(120),
    ]);

    $test->handlerCalls = 0;
}

it('does not run the handler when another request takes over between the read and the takeover update', function (): void {
    hardeningMakeStale($this);

    // The other request wins the compare-and-swap: new token, fresh lock.
    hardeningAfterRead(fn () => DB::table('idempotency_keys')->update([
        'lock_token' => (string) Str::uuid(),
        'locked_at' => now(),
    ]));

    hardeningPost($this, 'cas-1')
        ->assertStatus(409)
        ->assertHeader('Retry-After', '1')
        ->assertJsonPath('error.code', 'idempotency_in_progress');

    expect($this->handlerCalls)->toBe(0);
});

it('replays instead of running the handler when the row completes between the read and the takeover update', function (): void {
    hardeningMakeStale($this);

    // Same token, so only the status = processing filter can stop the takeover.
    hardeningAfterRead(fn () => DB::table('idempotency_keys')->update([
        'status' => 'completed',
        'response_status' => 201,
        'response_body' => '{"data":"done elsewhere"}',
        'response_content_type' => 'application/json',
        'completed_at' => now(),
    ]));

    $response = hardeningPost($this, 'cas-1');

    $response->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect($response->getContent())->toBe('{"data":"done elsewhere"}')
        ->and($this->handlerCalls)->toBe(0);
});

it('replays the exact stored bytes, including {} and 1.0', function (): void {
    $uri = '/api/_test/hardening-bytes';
    $expected = '{"z":1,"a":{},"list":[],"f":1.0,"path":"\/x","uni":"é"}';

    $first = hardeningPost($this, 'bytes-1', uri: $uri);
    $second = hardeningPost($this, 'bytes-1', uri: $uri);

    expect($first->getContent())->toBe($expected)
        ->and($second->getContent())->toBe($expected)
        ->and($second->headers->get('Content-Type'))->toBe('application/json')
        ->and(IdempotencyRecord::query()->sole()->getRawOriginal('response_body'))->toBe($expected)
        ->and($this->handlerCalls)->toBe(1);
});

it('distinguishes objects from lists and ints from floats when hashing the body', function (string $first, string $second): void {
    hardeningPost($this, 'shape-1', $first)->assertCreated();

    hardeningPost($this, 'shape-1', $second)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'idempotency_mismatch');

    expect($this->handlerCalls)->toBe(1);
})->with([
    'empty object vs empty list' => ['{"x":{}}', '{"x":[]}'],
    'numeric-key object vs list' => ['{"1":"a","0":"b"}', '["b","a"]'],
    'int vs float' => ['{"n":1}', '{"n":1.0}'],
]);

it('still treats key order and whitespace as irrelevant', function (): void {
    hardeningPost($this, 'shape-2', '{"b":{"y":1,"x":{}},"a":[1,2]}')->assertCreated();

    hardeningPost($this, 'shape-2', "{ \"a\" : [1,2],\n \"b\":{\"x\":{},\"y\":1} }")
        ->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true');

    expect($this->handlerCalls)->toBe(1);
});

it('hashes form-encoded bodies independently of key order, nested keys included', function (): void {
    $send = fn (array $form): TestResponse => $this->call('POST', HARDENING_URI, $form, [], [], [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
        'HTTP_IDEMPOTENCY_KEY' => 'form-1',
    ]);

    $send(['a' => '1', 'b' => ['y' => '1', 'x' => '2']])->assertCreated();

    app('auth')->forgetGuards();

    $send(['b' => ['x' => '2', 'y' => '1'], 'a' => '1'])
        ->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true');

    expect($this->handlerCalls)->toBe(1);
});

it('logs a warning when ownership was lost to a takeover', function (): void {
    Log::spy();

    $takeoverToken = (string) Str::uuid();

    Route::middleware(['api', 'auth:sanctum', 'idempotent'])
        ->post('/api/_test/hardening-lost', function () use ($takeoverToken) {
            DB::table('idempotency_keys')->update(['lock_token' => $takeoverToken]);

            return response()->json(['data' => 'original'], 201);
        });

    hardeningPost($this, 'lost-1', uri: '/api/_test/hardening-lost')->assertCreated();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'lock lost')
            && $context['key'] === 'lost-1'
            && $context['member_id'] === $this->member->id)
        ->once();
});

it('does not log the warning when ownership is intact', function (): void {
    Log::spy();

    hardeningPost($this, 'fine-1')->assertCreated();

    Log::shouldNotHaveReceived('warning');
});
