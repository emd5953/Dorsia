<?php

declare(strict_types=1);

use App\Shared\Support\Transactions;
use Illuminate\Support\Facades\DB;

// Feature tests run inside RefreshDatabase's wrapper transaction; D17 makes that level the baseline.

it('uses the wrapper transaction level as the base level', function (): void {
    expect(Transactions::$baseLevel)->toBe(1)
        ->and(DB::transactionLevel())->toBe(1);
});

it('treats the wrapper as no transaction', function (): void {
    Transactions::assertNone();

    expect(fn () => Transactions::assertActive())->toThrow(LogicException::class);
});

it('sees a transaction opened by the code under test', function (): void {
    DB::transaction(function (): void {
        Transactions::assertActive();

        expect(fn () => Transactions::assertNone())->toThrow(LogicException::class);
    });

    Transactions::assertNone();
});

it('sees nested transactions as active', function (): void {
    DB::transaction(fn () => DB::transaction(function (): void {
        Transactions::assertActive();
    }));

    expect(true)->toBeTrue();
});
