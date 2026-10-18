<?php

declare(strict_types=1);

use App\Shared\Support\Transactions;
use Illuminate\Support\Facades\DB;

// Plain code (no RefreshDatabase wrapper): the base level is 0, as in production.

it('has base level 0 outside Feature tests', function (): void {
    expect(Transactions::$baseLevel)->toBe(0)
        ->and(DB::transactionLevel())->toBe(0);
});

it('asserts none when no transaction is open and active when one is', function (): void {
    Transactions::assertNone();

    expect(fn () => Transactions::assertActive())->toThrow(LogicException::class);

    DB::transaction(function (): void {
        Transactions::assertActive();

        expect(fn () => Transactions::assertNone())->toThrow(LogicException::class);
    });

    Transactions::assertNone();
});
