<?php

declare(strict_types=1);

use App\Modules\Wallet\Enums\AccountKind;
use App\Modules\Wallet\Enums\LedgerTransactionType;
use App\Modules\Wallet\Models\LedgerAccount;
use App\Modules\Wallet\Support\PostingLine;
use App\Modules\Wallet\Support\PostTransaction;
use App\Shared\Money\Currency;
use Illuminate\Database\Events\QueryExecuted;
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

    expect(fn () => DB::statement('INSERT INTO ledger_entries (transaction_id, account_id, amount_cents, created_at) VALUES (?, ?, 5, now())', [$tx, $a]))
        ->toThrow(QueryException::class, 'only be added in the database transaction')
        ->and(DB::table('ledger_entries')->where('transaction_id', $tx)->count())->toBe(2);
});

it('rejects a balanced pair appended to a committed transaction', function (): void {
    [$a, $b] = committedAccounts();

    DB::unprepared('BEGIN');
    $tx = insertTransactionRow('commit:append-balanced');
    DB::statement('INSERT INTO ledger_entries (transaction_id, account_id, amount_cents, created_at) VALUES (?, ?, 100, now()), (?, ?, -100, now())', [$tx, $a, $tx, $b]);
    DB::unprepared('COMMIT');

    expect(fn () => DB::statement('INSERT INTO ledger_entries (transaction_id, account_id, amount_cents, created_at) VALUES (?, ?, 25, now()), (?, ?, -25, now())', [$tx, $a, $tx, $b]))
        ->toThrow(QueryException::class, 'only be added in the database transaction');

    expect(DB::table('ledger_entries')->where('transaction_id', $tx)->count())->toBe(2);
});

it('uses the insert conflict fallback when another connection commits the key after lookup', function (): void {
    $cash = LedgerAccount::factory()->create(['owner_type' => 'system', 'owner_id' => 0, 'kind' => AccountKind::Cash]);
    $venue = LedgerAccount::factory()->create(['owner_type' => 'venue', 'owner_id' => 777, 'kind' => AccountKind::VenuePayable]);
    $otherCash = LedgerAccount::factory()->create(['owner_type' => 'system', 'owner_id' => 1, 'kind' => AccountKind::Cash]);
    $otherVenue = LedgerAccount::factory()->create(['owner_type' => 'venue', 'owner_id' => 778, 'kind' => AccountKind::VenuePayable]);
    $race = config('database.connections.pgsql');
    config(['database.connections.ledger_race' => $race]);
    DB::purge('ledger_race');
    $other = DB::connection('ledger_race');
    $inserted = false;
    $key = 'race:insert-conflict';

    DB::listen(function (QueryExecuted $query) use (&$inserted, $other, $key, $otherCash, $otherVenue): void {
        if ($inserted || ! str_starts_with(strtolower(trim($query->sql)), 'select') || ! str_contains($query->sql, 'ledger_transactions') || ! str_contains($query->sql, 'idempotency_key')) {
            return;
        }

        $inserted = true;
        $other->transaction(function () use ($other, $key, $otherCash, $otherVenue): void {
            $now = now();
            $id = $other->table('ledger_transactions')->insertGetId([
                'type' => LedgerTransactionType::Booking->value,
                'reference_type' => 'hold', 'reference_id' => 777,
                'idempotency_key' => $key, 'currency' => 'USD', 'metadata' => '{}', 'created_at' => $now,
            ]);
            $other->table('ledger_entries')->insert([
                ['transaction_id' => $id, 'account_id' => $otherCash->id, 'amount_cents' => 100, 'created_at' => $now],
                ['transaction_id' => $id, 'account_id' => $otherVenue->id, 'amount_cents' => -100, 'created_at' => $now],
            ]);
        });
    });

    $result = DB::transaction(fn () => app(PostTransaction::class)->post(
        LedgerTransactionType::Booking,
        'hold',
        777,
        $key,
        Currency::USD,
        [PostingLine::cardClearing(100), PostingLine::venuePayable(777, -100)],
    ));

    expect($inserted)->toBeTrue()
        ->and($result->replayed)->toBeTrue()
        ->and($result->transaction->id)->toBe((int) $other->table('ledger_transactions')->where('idempotency_key', $key)->value('id'));
});
