<?php

declare(strict_types=1);

use App\Modules\Wallet\Actions\PostBookingPayment;
use App\Modules\Wallet\Actions\ReconcileLedger;
use App\Modules\Wallet\Data\SpendPlan;
use App\Modules\Wallet\Enums\AccountKind;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/../WalletHelpers.php';

/** A small realistic ledger: funded member, a booking using credit and card. */
function seedLedger(): int
{
    $member = newMemberId();
    fundMember($member, 50000);

    DB::transaction(fn () => app(PostBookingPayment::class)->handle(
        bookingInput($member, new SpendPlan(usd(0), usd(30000), usd(70000)))
    ));

    return $member;
}

function accountId(string $ownerType, int $ownerId, AccountKind $kind): int
{
    return (int) DB::table('ledger_accounts')
        ->where('owner_type', $ownerType)->where('owner_id', $ownerId)->where('kind', $kind->value)->value('id');
}

it('reports a clean ledger', function (): void {
    seedLedger();

    $report = app(ReconcileLedger::class)->handle();

    expect($report->clean())->toBeTrue()
        ->and($report->accountsChecked)->toBe(4)
        ->and($report->globalSumCents)->toBe(0)
        ->and($report->unbalancedTransactions)->toBe(0)
        ->and($report->drift)->toBe([]);
});

it('reports an empty ledger as clean', function (): void {
    expect(app(ReconcileLedger::class)->handle()->clean())->toBeTrue();
});

it('detects a corrupted cached balance on a credit-normal and on a debit-normal account', function (): void {
    $member = seedLedger();
    $credit = accountId('member', $member, AccountKind::Credit);
    $cash = accountId('system', 0, AccountKind::Cash);

    DB::table('ledger_accounts')->where('id', $credit)->update(['balance_cents' => 25000]);
    DB::table('ledger_accounts')->where('id', $cash)->update(['balance_cents' => 1]);

    $report = app(ReconcileLedger::class)->handle();

    expect($report->clean())->toBeFalse()
        ->and($report->drift)->toBe([
            ['account_id' => $credit, 'cached' => 25000, 'actual' => 20000],
            ['account_id' => $cash, 'cached' => 1, 'actual' => 70000],
        ]);
});

it('detects an unbalanced transaction and a non-zero global sum when the trigger is disabled', function (): void {
    $member = seedLedger();
    $credit = accountId('member', $member, AccountKind::Credit);

    DB::statement('ALTER TABLE ledger_entries DISABLE TRIGGER ledger_entries_balanced');
    DB::statement('ALTER TABLE ledger_transactions DISABLE TRIGGER ledger_transactions_have_entries');

    DB::statement(<<<SQL
        WITH tx AS (
            INSERT INTO ledger_transactions (type, reference_type, reference_id, idempotency_key, currency, created_at)
            VALUES ('goodwill_credit', 'member', 1, 'raw:bad', 'USD', now()) RETURNING id
        )
        INSERT INTO ledger_entries (transaction_id, account_id, amount_cents, created_at)
        SELECT tx.id, {$credit}, 100, now() FROM tx
        SQL);

    $report = app(ReconcileLedger::class)->handle();

    expect($report->clean())->toBeFalse()
        ->and($report->globalSumCents)->toBe(100)
        ->and($report->unbalancedTransactions)->toBe(1);
});

it('flags a transaction row with no entries', function (): void {
    seedLedger();

    DB::statement('ALTER TABLE ledger_transactions DISABLE TRIGGER ledger_transactions_have_entries');
    DB::statement("INSERT INTO ledger_transactions (type, reference_type, reference_id, idempotency_key, currency, created_at) VALUES ('goodwill_credit', 'member', 1, 'raw:empty', 'USD', now())");

    $report = app(ReconcileLedger::class)->handle();

    expect($report->unbalancedTransactions)->toBe(1)
        ->and($report->globalSumCents)->toBe(0)
        ->and($report->clean())->toBeFalse();
});

it('flags a transaction whose entries use another currency', function (): void {
    seedLedger();
    $eur = DB::table('ledger_accounts')->insertGetId([
        'owner_type' => 'venue', 'owner_id' => 99, 'kind' => 'venue_payable', 'currency' => 'EUR',
        'balance_cents' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $platform = accountId('system', 0, AccountKind::Cash);

    DB::statement('ALTER TABLE ledger_entries DISABLE TRIGGER ledger_entries_balanced');
    DB::statement(<<<SQL
        WITH tx AS (
            INSERT INTO ledger_transactions (type, reference_type, reference_id, idempotency_key, currency, created_at)
            VALUES ('goodwill_credit', 'member', 1, 'raw:fx', 'USD', now()) RETURNING id
        )
        INSERT INTO ledger_entries (transaction_id, account_id, amount_cents, created_at)
        SELECT tx.id, v.a, v.c, now() FROM tx CROSS JOIN (VALUES ({$eur}::bigint, 10::bigint), ({$platform}::bigint, -10::bigint)) AS v(a, c)
        SQL);

    expect(app(ReconcileLedger::class)->handle()->unbalancedTransactions)->toBe(1);
});

it('rebuilds cached balances from entries and a re-run is clean', function (): void {
    $member = seedLedger();
    $credit = accountId('member', $member, AccountKind::Credit);
    DB::table('ledger_accounts')->where('id', $credit)->update(['balance_cents' => 25000]);

    $rebuilt = app(ReconcileLedger::class)->handle(rebuild: true);

    expect($rebuilt->drift)->toBe([['account_id' => $credit, 'cached' => 25000, 'actual' => 20000]])
        ->and(walletBalance('member', $member, AccountKind::Credit))->toBe(20000)
        ->and(app(ReconcileLedger::class)->handle()->clean())->toBeTrue();
});

it('wallet:reconcile exits 0 on a clean ledger', function (): void {
    seedLedger();

    $this->artisan('wallet:reconcile')
        ->expectsOutputToContain('Ledger is clean.')
        ->assertExitCode(0);
});

it('wallet:reconcile exits 1 on drift, names the account and logs critical; --rebuild fixes it', function (): void {
    $member = seedLedger();
    $credit = accountId('member', $member, AccountKind::Credit);
    DB::table('ledger_accounts')->where('id', $credit)->update(['balance_cents' => 25000]);

    Log::spy();

    $this->artisan('wallet:reconcile')
        ->expectsOutputToContain("Drifted accounts: {$credit}")
        ->assertExitCode(1);

    Log::shouldHaveReceived('critical')->once()->withArgs(
        fn (string $message, array $context): bool => $context['drifted_accounts'] === (string) $credit
    );

    $this->artisan('wallet:reconcile', ['--rebuild' => true])->assertExitCode(1);

    expect(walletBalance('member', $member, AccountKind::Credit))->toBe(20000);

    $this->artisan('wallet:reconcile')->assertExitCode(0);
});

it('schedules wallet:reconcile daily at 03:00 UTC without overlap, on one server', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'wallet:reconcile'));

    expect($events)->toHaveCount(1);

    /** @var Event $event */
    $event = $events->first();

    expect($event->expression)->toBe('0 3 * * *')
        ->and($event->timezone)->toBe('UTC')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});
