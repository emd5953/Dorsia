<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Concurrency;

it('really runs 20 children in parallel', function (): void {
    Schema::create('concurrency_scratch', function ($table): void {
        $table->id();
        $table->integer('child');
    });

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
