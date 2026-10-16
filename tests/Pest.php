<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\Support\Concurrency;
use Tests\TestCase;

/*
| Test layout (C§15): tests/Shared and tests/Modules/<Module> each hold
| Unit (no DB), Feature (RefreshDatabase) and Concurrency (DatabaseTruncation).
*/

pest()->extend(TestCase::class)->in('Arch', 'Shared/Unit', 'Modules/*/Unit');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Shared/Feature', 'Modules/*/Feature', 'Performance')
    ->beforeEach(function (): void {
        // Deferred constraint triggers never fire inside the rolled-back wrapper transaction.
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        Redis::connection()->flushdb();
    });

pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->group('concurrency')
    ->in('Shared/Concurrency', 'Modules/*/Concurrency')
    ->beforeEach(function (): void {
        Redis::connection()->flushdb();
        Redis::connection('cache')->flushdb();
    })
    ->afterEach(function (): void {
        // Concurrency tests commit their fixtures; leave no rows behind for RefreshDatabase tests.
        Concurrency::truncateAll();
    });
