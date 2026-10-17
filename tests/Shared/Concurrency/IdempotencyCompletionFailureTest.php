<?php

declare(strict_types=1);

use App\Shared\Idempotency\IdempotencyRecord;
use App\Shared\Members\Member;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

// Runs without RefreshDatabase's wrapper transaction: a failed statement would poison that
// transaction, whereas production autocommit lets the middleware clean up after the failure.
it('returns the handler response and frees the key when storing the response fails', function (): void {
    Log::spy();

    Route::middleware(['api', 'auth:sanctum', 'idempotent'])
        ->post('/api/_test/oversized-content-type', fn () => response('{"data":1}', 201, [
            // response_content_type is varchar(100)
            'Content-Type' => 'application/json; x='.str_repeat('a', 140),
        ]));

    $token = Member::factory()->create()->createToken('t')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/_test/oversized-content-type', [], ['Idempotency-Key' => 'oversized-1'])
        ->assertStatus(201);

    expect(IdempotencyRecord::query()->count())->toBe(0);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'completion failed'))
        ->once();
});
