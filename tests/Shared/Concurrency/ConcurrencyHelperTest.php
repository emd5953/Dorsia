<?php

declare(strict_types=1);

use App\Shared\Members\Member;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Concurrency;

function concurrencyScratchTable(): void
{
    Schema::create('concurrency_scratch', function ($table): void {
        $table->id();
        $table->integer('child');
    });
}

it('really runs 20 children in parallel', function (): void {
    concurrencyScratchTable();

    try {
        $closures = [];

        for ($i = 0; $i < 20; $i++) {
            $closures[] = function () use ($i): int {
                DB::insert(
                    'INSERT INTO concurrency_scratch (child) SELECT ? FROM (SELECT pg_sleep(0.5)) AS slept',
                    [$i],
                );

                return $i;
            };
        }

        $started = microtime(true);
        $results = Concurrency::run($closures);
        $elapsed = microtime(true) - $started;

        expect($results)->toBe(range(0, 19))
            ->and($elapsed)->toBeLessThan(5.0)
            ->and(DB::table('concurrency_scratch')->count())->toBe(20)
            ->and(DB::table('concurrency_scratch')->distinct()->count('child'))->toBe(20);
    } finally {
        Schema::dropIfExists('concurrency_scratch');
    }
});

it('really runs 20 HTTP requests in parallel through requests()', function (): void {
    concurrencyScratchTable();

    try {
        Route::middleware(['api', 'auth:sanctum'])->post('/api/_test/parallel', function (Request $request) {
            DB::insert(
                'INSERT INTO concurrency_scratch (child) SELECT ? FROM (SELECT pg_sleep(0.5)) AS slept',
                [(int) $request->input('i')],
            );

            return response()->json(['data' => ['i' => (int) $request->input('i')]], 201);
        });

        $token = Member::factory()->create()->createToken('parallel')->plainTextToken;

        $started = microtime(true);
        $responses = Concurrency::requests(20, fn (int $i): array => [
            'method' => 'POST',
            'uri' => '/api/_test/parallel',
            'token' => $token,
            'body' => ['i' => $i],
        ]);
        $elapsed = microtime(true) - $started;

        expect($responses)->toHaveCount(20)
            ->and(array_column($responses, 'status'))->toBe(array_fill(0, 20, 201))
            ->and(array_map(fn (array $r): int => $r['json']['data']['i'], $responses))->toBe(range(0, 19))
            ->and($elapsed)->toBeLessThan(5.0)
            ->and(DB::table('concurrency_scratch')->count())->toBe(20);
    } finally {
        Schema::dropIfExists('concurrency_scratch');
    }
});
