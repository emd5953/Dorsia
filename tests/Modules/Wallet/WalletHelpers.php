<?php

declare(strict_types=1);

use App\Modules\Wallet\Data\BookingPaymentInput;
use App\Modules\Wallet\Data\SpendPlan;
use App\Modules\Wallet\Enums\AccountKind;
use App\Modules\Wallet\Models\LedgerTransaction;
use App\Shared\Members\Member;
use App\Shared\Money\Currency;
use App\Shared\Money\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function usd(int $cents): Money
{
    return Money::of($cents, Currency::USD);
}

/** Cached balance of one account; 0 when the account does not exist yet. */
function walletBalance(string $ownerType, int $ownerId, AccountKind $kind): int
{
    return (int) DB::table('ledger_accounts')
        ->where('owner_type', $ownerType)
        ->where('owner_id', $ownerId)
        ->where('kind', $kind->value)
        ->value('balance_cents');
}

function walletAccountExists(string $ownerType, int $ownerId, AccountKind $kind): bool
{
    return DB::table('ledger_accounts')
        ->where('owner_type', $ownerType)
        ->where('owner_id', $ownerId)
        ->where('kind', $kind->value)
        ->exists();
}

/**
 * Entries of one transaction as "owner_type:owner_id:kind" => signed cents, in insertion order.
 *
 * @return array<string, int>
 */
function walletEntries(int $transactionId): array
{
    $entries = [];

    $rows = DB::table('ledger_entries')
        ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.account_id')
        ->where('ledger_entries.transaction_id', $transactionId)
        ->orderBy('ledger_entries.id')
        ->get(['ledger_accounts.owner_type', 'ledger_accounts.owner_id', 'ledger_accounts.kind', 'ledger_entries.amount_cents']);

    foreach ($rows as $row) {
        $entries["{$row->owner_type}:{$row->owner_id}:{$row->kind}"] = (int) $row->amount_cents;
    }

    return $entries;
}

/** Funds a member account through PostTransaction (platform debit, member credit). */
function fundMember(int $memberId, int $cents, AccountKind $kind = AccountKind::Credit): LedgerTransaction
{
    return LedgerTransaction::factory()->forMember($memberId)->kind($kind)->amount($cents)->create();
}

function newMemberId(): int
{
    return Member::factory()->create()->id;
}

function bookingInput(int $memberId, SpendPlan $plan, int $holdId = 1, int $venueId = 7, int $reservationId = 1): BookingPaymentInput
{
    return new BookingPaymentInput($memberId, $venueId, $holdId, $reservationId, $plan);
}

function cardOnly(int $cents): SpendPlan
{
    return new SpendPlan(usd(0), usd(0), usd($cents));
}

/** Runs the closure in its own savepoint so a database error does not poison the test transaction. */
function expectDatabaseRejects(Closure $statement, string $message): void
{
    $caught = null;

    try {
        DB::transaction($statement);
    } catch (QueryException $exception) {
        $caught = $exception;
    }

    expect($caught)->not->toBeNull('the database accepted a statement it must reject')
        ->and($caught?->getMessage())->toContain($message);
}
