<?php

declare(strict_types=1);

use App\Shared\Idempotency\IdempotencyRecord;
use App\Shared\Members\Member;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Concurrency;

it('executes one handler for 20 parallel identical requests and never returns a 5xx', function (): void {
    Schema::create('idempotency_race_rows', function ($table): void {
        $table->id();
        $table->integer('pid');
    });

    try {
        Route::middleware(['api', 'auth:sanctum', 'idempotent'])
            ->post('/api/_test/race', function () {
                usleep(300_000);
                DB::table('idempotency_race_rows')->insert(['pid' => getmypid()]);

                return response()->json(['data' => ['ok' => true]], 201);
            });

        $member = Member::factory()->create();
        $token = $member->createToken('race')->plainTextToken;

        $responses = Concurrency::requests(20, fn (int $i): array => [
            'method' => 'POST',
            'uri' => '/api/_test/race',
            'token' => $token,
            'body' => ['slot' => 1],
            'headers' => ['Idempotency-Key' => 'race-key-1'],
        ]);

        expect($responses)->toHaveCount(20)
            ->and(DB::table('idempotency_race_rows')->count())->toBe(1);

        $created = 0;

        foreach ($responses as $response) {
            expect($response['status'])->toBeLessThan(500);

            if ($response['status'] === 201) {
                $created++;
                expect($response['json'])->toBe(['data' => ['ok' => true]]);
            } else {
                expect($response['status'])->toBe(409)
                    ->and($response['json']['error']['code'])->toBe('idempotency_in_progress')
                    ->and($response['headers']['retry-after'])->toBe('1');
            }
        }

        expect($created)->toBeGreaterThanOrEqual(1);

        $row = IdempotencyRecord::query()->sole();
        expect($row->status)->toBe('completed')
            ->and($row->response_status)->toBe(201);

        // Once settled, a replay is served from the stored response.
        [$replay] = Concurrency::requests(1, fn (): array => [
            'method' => 'POST',
            'uri' => '/api/_test/race',
            'token' => $token,
            'body' => ['slot' => 1],
            'headers' => ['Idempotency-Key' => 'race-key-1'],
        ]);

        expect($replay['status'])->toBe(201)
            ->and($replay['headers']['idempotent-replayed'])->toBe('true')
            ->and(DB::table('idempotency_race_rows')->count())->toBe(1);
    } finally {
        Schema::dropIfExists('idempotency_race_rows');
    }
});
