<?php

declare(strict_types=1);

use App\Modules\Wallet\Enums\AccountKind;
use App\Modules\Wallet\Models\LedgerAccount;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../WalletHelpers.php';

/*
| These tests use real BEGIN / COMMIT (no wrapper transaction, no SET CONSTRAINTS IMMEDIATE),
| so they prove the constraint triggers are deferred to commit and fire there.
*/

/** @return array{int, int} */
function committedAccounts(): array
{
    $a = LedgerAccount::factory()->create();
    $b = LedgerAccount::factory()->create(['owner_type' => 'system', 'owner_id' => 0, 'kind' => AccountKind::Platform]);

    return [$a->id, $b->id];
}

function insertTransactionRow(string $key): int
{
    return (int) DB::selectOne(
        "INSERT INTO ledger_transactions (type, reference_type, reference_id, idempotency_key, currency, created_at) VALUES ('goodwill_credit', 'member', 1, ?, 'USD', now()) RETURNING id",
        [$key],
    )->id;
}

function commitFails(): ?string
{
    try {
        DB::unprepared('COMMIT');
    } catch (QueryException $e) {
        return $e->getMessage();
    }

    return null;
}

it('rejects an unbalanced transaction at commit, leaving nothing behind', function (): void {
    [$a, $b] = committedAccounts();

    DB::unprepared('BEGIN');
    $tx = insertTransactionRow('commit:unbalanced');
    // One INSERT of entries summing to 100.
    DB::statement('INSERT INTO ledger_entries (transaction_id, account_id, amount_cents, created_at) VALUES (?, ?, 300, now()), (?, ?, -200, now())', [$tx, $a, $tx, $b]);

    expect(commitFails())->toContain('does not balance')
        ->and(DB::table('ledger_transactions')->count())->toBe(0)
        ->and(DB::table('ledger_entries')->count())->toBe(0);
});

it('commits a balanced two-entry transaction, even when the entries arrive in a later statement', function (): void {
    [$a, $b] = committedAccounts();

    DB::unprepared('BEGIN');
    $tx = insertTransactionRow('commit:balanced');
    // The transaction row exists alone here; an immediate trigger would already have failed.
    DB::statement('INSERT INTO ledger_entries (transaction_id, account_id, amount_cents, created_at) VALUES (?, ?, 100, now()), (?, ?, -100, now())', [$tx, $a, $tx, $b]);

    expect(commitFails())->toBeNull()
        ->and(DB::table('ledger_transactions')->count())->toBe(1)
        ->and(DB::table('ledger_entries')->count())->toBe(2);
});

it('rejects a transaction row without entries at commit', function (): void {
    DB::unprepared('BEGIN');
    insertTransactionRow('commit:empty');

    expect(commitFails())->toContain('at least 2 required')
        ->and(DB::table('ledger_transactions')->count())->toBe(0);
});

it('rejects a transaction with a single entry at commit', function (): void {
    [$a] = committedAccounts();

    DB::unprepared('BEGIN');
    $tx = insertTransactionRow('commit:single');
    DB::statement('INSERT INTO ledger_entries (transaction_id, account_id, amount_cents, created_at) VALUES (?, ?, 100, now())', [$tx, $a]);

    expect(commitFails())->toContain('at least 2 required');
});

it('rejects entries in a different currency than the transaction at commit', function (): void {
    $a = LedgerAccount::factory()->create(['currency' => 'EUR']);
    $b = LedgerAccount::factory()->create(['currency' => 'EUR', 'owner_type' => 'system', 'owner_id' => 0, 'kind' => AccountKind::Platform]);

    DB::unprepared('BEGIN');
    $tx = insertTransactionRow('commit:currency');
    DB::statement('INSERT INTO ledger_entries (transaction_id, account_id, amount_cents, created_at) VALUES (?, ?, 100, now()), (?, ?, -100, now())', [$tx, $a->id, $tx, $b->id]);

    expect(commitFails())->toContain('does not balance')
        ->and(DB::table('ledger_entries')->count())->toBe(0);
});

it('rejects a later entry added to a committed transaction so that it no longer balances', function (): void {
    [$a, $b] = committedAccounts();

    DB::unprepared('BEGIN');
    $tx = insertTransactionRow('commit:later');
    DB::statement('INSERT INTO ledger_entries (transaction_id, account_id, amount_cents, created_at) VALUES (?, ?, 100, now()), (?, ?, -100, now())', [$tx, $a, $tx, $b]);
    DB::unprepared('COMMIT');

    DB::unprepared('BEGIN');
    DB::statement('INSERT INTO ledger_entries (transaction_id, account_id, amount_cents, created_at) VALUES (?, ?, 5, now())', [$tx, $a]);

    expect(commitFails())->toContain('does not balance')
        ->and(DB::table('ledger_entries')->count())->toBe(2);
});
