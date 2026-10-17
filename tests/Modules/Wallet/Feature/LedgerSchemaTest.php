<?php

declare(strict_types=1);

use App\Modules\Wallet\Enums\AccountKind;
use App\Modules\Wallet\Models\LedgerAccount;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../WalletHelpers.php';

/**
 * Raw single-statement insert of a transaction and its entries, bypassing PHP's PostTransaction.
 *
 * @param  list<array{int, int}>  $entries  [account id, signed cents]
 */
function rawLedgerInsert(array $entries, string $currency = 'USD', string $key = 'raw:1'): void
{
    DB::statement('SET CONSTRAINTS ledger_transactions_have_entries DEFERRED');
    $transactionId = DB::table('ledger_transactions')->insertGetId([
        'type' => 'goodwill_credit', 'reference_type' => 'member', 'reference_id' => 1,
        'idempotency_key' => $key, 'currency' => $currency, 'created_at' => now(),
    ]);
    $values = implode(', ', array_map(fn (array $e): string => "({$transactionId}::bigint, {$e[0]}::bigint, {$e[1]}::bigint, now())", $entries));
    DB::statement("INSERT INTO ledger_entries (transaction_id, account_id, amount_cents, created_at) VALUES {$values}");
    DB::statement('SET CONSTRAINTS ledger_transactions_have_entries IMMEDIATE');
}

/** @return array{int, int} two account ids: a venue payable and a platform account */
function twoAccounts(string $currency = 'USD'): array
{
    $a = LedgerAccount::factory()->create(['currency' => $currency]);
    $b = LedgerAccount::factory()->create([
        'owner_type' => 'system',
        'owner_id' => 0,
        'kind' => AccountKind::Platform,
        'currency' => $currency,
    ]);

    return [$a->id, $b->id];
}

it('accepts a balanced two-entry transaction', function (): void {
    [$a, $b] = twoAccounts();

    rawLedgerInsert([[$a, 100], [$b, -100]]);

    expect(DB::table('ledger_entries')->count())->toBe(2);
});

it('rejects an unbalanced transaction with the trigger message', function (): void {
    [$a, $b] = twoAccounts();

    // One INSERT of entries summing to 100.
    expectDatabaseRejects(fn () => rawLedgerInsert([[$a, 300], [$b, -200]]), 'does not balance');

    expect(DB::table('ledger_entries')->count())->toBe(0)
        ->and(DB::table('ledger_transactions')->count())->toBe(0);
});

it('rejects a transaction with a single entry', function (): void {
    [$a] = twoAccounts();

    expectDatabaseRejects(fn () => rawLedgerInsert([[$a, 100]]), 'does not balance');
});

it('rejects a transaction row that has no entries at all', function (): void {
    expectDatabaseRejects(
        fn () => DB::statement("INSERT INTO ledger_transactions (type, reference_type, reference_id, idempotency_key, currency, created_at) VALUES ('goodwill_credit', 'member', 1, 'raw:empty', 'USD', now())"),
        'has 0 entries, at least 2 required',
    );
});

it('rejects entries whose account currency differs from the transaction currency', function (): void {
    [$a, $b] = twoAccounts('EUR');

    expectDatabaseRejects(fn () => rawLedgerInsert([[$a, 100], [$b, -100]], 'USD'), 'does not balance');

    // The same entries in the accounts' own currency are fine.
    rawLedgerInsert([[$a, 100], [$b, -100]], 'EUR', 'raw:eur');

    expect(DB::table('ledger_entries')->count())->toBe(2);
});

it('rejects a zero-amount entry', function (): void {
    [$a, $b] = twoAccounts();

    expectDatabaseRejects(fn () => rawLedgerInsert([[$a, 0], [$b, 0]]), 'ledger_entries_amount_non_zero');
});

it('rejects a second transaction with the same idempotency key', function (): void {
    [$a, $b] = twoAccounts();

    rawLedgerInsert([[$a, 100], [$b, -100]]);

    expectDatabaseRejects(fn () => rawLedgerInsert([[$a, 100], [$b, -100]]), 'ledger_transactions_idempotency_key_unique');
});

it('rejects an unknown transaction type', function (): void {
    expectDatabaseRejects(
        fn () => DB::statement("INSERT INTO ledger_transactions (type, reference_type, reference_id, idempotency_key, currency, created_at) VALUES ('payout', 'member', 1, 'raw:type', 'USD', now())"),
        'ledger_transactions_type_valid',
    );
});

it('is append-only: UPDATE and DELETE on entries and transactions fail', function (string $statement): void {
    [$a, $b] = twoAccounts();
    rawLedgerInsert([[$a, 100], [$b, -100]]);

    expectDatabaseRejects(fn () => DB::statement($statement), 'ledger is append-only');

    expect(DB::table('ledger_entries')->sum('amount_cents'))->toEqual(0)
        ->and(DB::table('ledger_entries')->count())->toBe(2)
        ->and(DB::table('ledger_transactions')->count())->toBe(1);
})->with([
    'update entry' => 'UPDATE ledger_entries SET amount_cents = 5',
    'delete entry' => 'DELETE FROM ledger_entries',
    'update transaction' => "UPDATE ledger_transactions SET metadata = '{\"x\":1}'",
    'delete transaction' => 'DELETE FROM ledger_transactions',
]);

it('never lets a member account go negative, even for a direct UPDATE', function (): void {
    $account = LedgerAccount::factory()->forMember(newMemberId())->create();

    expectDatabaseRejects(
        fn () => DB::statement('UPDATE ledger_accounts SET balance_cents = -1 WHERE id = ?', [$account->id]),
        'ledger_accounts_member_non_negative',
    );

    expect(DB::table('ledger_accounts')->where('id', $account->id)->value('balance_cents'))->toBe(0);
});

it('lets a venue_payable account go negative (only member accounts are protected)', function (): void {
    $account = LedgerAccount::factory()->create();

    DB::statement('UPDATE ledger_accounts SET balance_cents = -1 WHERE id = ?', [$account->id]);

    expect(DB::table('ledger_accounts')->where('id', $account->id)->value('balance_cents'))->toBe(-1);
});

it('keeps ledger account identity columns immutable', function (string $column, mixed $value): void {
    $account = LedgerAccount::factory()->create();

    expectDatabaseRejects(
        fn () => DB::table('ledger_accounts')->where('id', $account->id)->update([$column => $value]),
        'identity is immutable',
    );
})->with([
    'owner_id' => ['owner_id', 99],
    'owner_type' => ['owner_type', 'system'],
    'currency' => ['currency', 'EUR'],
    'kind' => ['kind', 'platform'],
]);

it('enforces owner/kind pairing and one account per owner, kind and currency', function (): void {
    expectDatabaseRejects(
        fn () => LedgerAccount::factory()->create(['owner_type' => 'member', 'owner_id' => 1, 'kind' => AccountKind::VenuePayable]),
        'ledger_accounts_owner_kind',
    );
    expectDatabaseRejects(
        fn () => LedgerAccount::factory()->create(['owner_type' => 'system', 'owner_id' => 0, 'kind' => AccountKind::Credit]),
        'ledger_accounts_owner_kind',
    );

    LedgerAccount::factory()->create(['owner_id' => 5]);

    expectDatabaseRejects(fn () => LedgerAccount::factory()->create(['owner_id' => 5]), 'ledger_accounts_owner_kind_currency_unique');
});

it('defines the three triggers as the contract says', function (): void {
    $triggers = DB::table('pg_trigger')
        ->join('pg_class', 'pg_class.oid', '=', 'pg_trigger.tgrelid')
        ->whereIn('pg_class.relname', ['ledger_entries', 'ledger_transactions'])
        ->where('pg_trigger.tgisinternal', false)
        ->get(['pg_trigger.tgname', 'pg_class.relname', 'pg_trigger.tgdeferrable', 'pg_trigger.tginitdeferred', 'pg_trigger.tgtype', 'pg_trigger.tgconstraint'])
        ->keyBy('tgname');

    expect($triggers->keys()->sort()->values()->all())->toBe([
        'ledger_entries_append_only',
        'ledger_entries_balanced',
        'ledger_entries_parent_is_current',
        'ledger_transactions_append_only',
        'ledger_transactions_have_entries',
    ]);

    foreach (['ledger_entries_balanced' => 'ledger_entries', 'ledger_transactions_have_entries' => 'ledger_transactions'] as $name => $table) {
        $trigger = $triggers[$name];

        expect($trigger->relname)->toBe($table)
            ->and($trigger->tgdeferrable)->toBeTrue()
            ->and($trigger->tginitdeferred)->toBeTrue()
            ->and($trigger->tgconstraint)->not->toBe(0)
            ->and($trigger->tgtype & 1)->toBe(1)      // row-level
            ->and($trigger->tgtype & 2)->toBe(0)      // not BEFORE: AFTER
            ->and($trigger->tgtype & 4)->toBe(4);     // INSERT
    }

    foreach (['ledger_entries_append_only', 'ledger_transactions_append_only'] as $name) {
        expect($triggers[$name]->tgtype & 1)->toBe(1)  // row-level
            ->and($triggers[$name]->tgtype & 2)->toBe(2) // BEFORE
            ->and($triggers[$name]->tgtype & 8)->toBe(8)   // DELETE
            ->and($triggers[$name]->tgtype & 16)->toBe(16); // UPDATE
    }
});
