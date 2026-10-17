<?php

declare(strict_types=1);

use App\Modules\Wallet\Actions\PlanSpend;
use App\Modules\Wallet\Actions\PostBookingPayment;
use App\Modules\Wallet\Data\LedgerTransactionData;
use App\Modules\Wallet\Data\SpendPlan;
use App\Modules\Wallet\Enums\AccountKind;
use App\Modules\Wallet\Enums\LedgerTransactionType;
use App\Modules\Wallet\Exceptions\InsufficientWalletBalanceException;
use App\Shared\Outbox\OutboxEvent;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../WalletHelpers.php';

function book(int $memberId, SpendPlan $plan, int $holdId = 1): LedgerTransactionData
{
    return DB::transaction(fn () => app(PostBookingPayment::class)->handle(bookingInput($memberId, $plan, $holdId)));
}

it('books $1,000 by card: debit card clearing, credit venue_payable', function (): void {
    $member = newMemberId();

    $tx = book($member, cardOnly(100000));

    expect($tx->type)->toBe(LedgerTransactionType::Booking)
        ->and($tx->idempotencyKey)->toBe('hold:1:booking')
        ->and(walletEntries($tx->id))->toBe(['system:0:cash' => 100000, 'venue:7:venue_payable' => -100000])
        ->and(walletBalance('system', 0, AccountKind::Cash))->toBe(100000)
        ->and(walletBalance('venue', 7, AccountKind::VenuePayable))->toBe(100000)
        ->and(walletAccountExists('member', $member, AccountKind::Credit))->toBeFalse();
});

it('posts successfully inside nested Laravel savepoints', function (): void {
    $member = newMemberId();

    $transaction = DB::transaction(fn () => DB::transaction(fn () => DB::transaction(
        fn () => app(PostBookingPayment::class)->handle(bookingInput($member, cardOnly(100000), 31)),
    )));

    expect(DB::table('ledger_transactions')->where('id', $transaction->id)->exists())->toBeTrue()
        ->and(DB::table('ledger_entries')->where('transaction_id', $transaction->id)->count())->toBe(2);
});

it('returns entry DTOs with account ids, owners, kinds and amounts', function (): void {
    $tx = book(newMemberId(), cardOnly(5000));

    expect($tx->entries)->toHaveCount(2)
        ->and($tx->entries[0]->kind)->toBe(AccountKind::Cash)
        ->and($tx->entries[0]->amountCents)->toBe(5000)
        ->and($tx->entries[1]->kind)->toBe(AccountKind::VenuePayable)
        ->and($tx->entries[1]->ownerId)->toBe(7)
        ->and($tx->entries[1]->amountCents)->toBe(-5000)
        ->and($tx->currency->value)->toBe('USD');
});

it('books $1,000 with credit only: no card line, member credit drops by the whole amount', function (): void {
    $member = newMemberId();
    fundMember($member, 100000);

    $plan = app(PlanSpend::class)->handle($member, usd(100000));
    $tx = book($member, $plan);

    expect(walletEntries($tx->id))->toBe(['member:'.$member.':credit' => 100000, 'venue:7:venue_payable' => -100000])
        ->and(walletBalance('member', $member, AccountKind::Credit))->toBe(0)
        ->and(walletBalance('venue', 7, AccountKind::VenuePayable))->toBe(100000)
        ->and(walletAccountExists('system', 0, AccountKind::Cash))->toBeFalse();
});

it('books credit 30000 plus card 70000: three lines, credit falls by exactly the credit part', function (): void {
    $member = newMemberId();
    fundMember($member, 50000);

    $tx = book($member, new SpendPlan(usd(0), usd(30000), usd(70000)));

    expect(walletEntries($tx->id))->toBe([
        'member:'.$member.':credit' => 30000,
        'system:0:cash' => 70000,
        'venue:7:venue_payable' => -100000,
    ])
        ->and(walletBalance('member', $member, AccountKind::Credit))->toBe(20000)
        ->and(walletBalance('system', 0, AccountKind::Cash))->toBe(70000)
        ->and(walletBalance('venue', 7, AccountKind::VenuePayable))->toBe(100000);
});

it('books with coupons only', function (): void {
    $member = newMemberId();
    fundMember($member, 100000, AccountKind::Coupon);

    $tx = book($member, new SpendPlan(usd(100000), usd(0), usd(0)));

    expect(walletEntries($tx->id))->toBe(['member:'.$member.':coupon' => 100000, 'venue:7:venue_payable' => -100000])
        ->and(walletBalance('member', $member, AccountKind::Coupon))->toBe(0)
        ->and(walletBalance('venue', 7, AccountKind::VenuePayable))->toBe(100000);
});

it('books with coupon, credit and card together', function (): void {
    $member = newMemberId();
    fundMember($member, 40000, AccountKind::Coupon);
    fundMember($member, 20000);

    $plan = app(PlanSpend::class)->handle($member, usd(100000));
    $tx = book($member, $plan);

    expect(walletEntries($tx->id))->toBe([
        'member:'.$member.':coupon' => 40000,
        'member:'.$member.':credit' => 20000,
        'system:0:cash' => 40000,
        'venue:7:venue_payable' => -100000,
    ])
        ->and(walletBalance('member', $member, AccountKind::Coupon))->toBe(0)
        ->and(walletBalance('member', $member, AccountKind::Credit))->toBe(0);
});

it('rejects a plan whose credit exceeds the current balance and writes nothing', function (): void {
    $member = newMemberId();
    fundMember($member, 10000);

    $before = [
        'transactions' => DB::table('ledger_transactions')->count(),
        'entries' => DB::table('ledger_entries')->count(),
        'outbox' => OutboxEvent::query()->count(),
        'credit' => walletBalance('member', $member, AccountKind::Credit),
    ];

    $exception = null;

    try {
        DB::transaction(fn () => app(PostBookingPayment::class)->handle(
            bookingInput($member, new SpendPlan(usd(0), usd(30000), usd(70000)))
        ));
    } catch (InsufficientWalletBalanceException $e) {
        $exception = $e;
    }

    expect($exception)->toBeInstanceOf(InsufficientWalletBalanceException::class)
        ->and($exception?->errorCode())->toBe('wallet_balance_changed')
        ->and($exception?->httpStatus())->toBe(409)
        ->and(DB::table('ledger_transactions')->count())->toBe($before['transactions'])
        ->and(DB::table('ledger_entries')->count())->toBe($before['entries'])
        ->and(OutboxEvent::query()->count())->toBe($before['outbox'])
        ->and(walletBalance('member', $member, AccountKind::Credit))->toBe($before['credit'])
        ->and(walletAccountExists('venue', 7, AccountKind::VenuePayable))->toBeFalse()
        ->and(walletAccountExists('system', 0, AccountKind::Cash))->toBeFalse();
});

it('rejects a coupon spend when the member has no coupon account at all', function (): void {
    $member = newMemberId();

    expect(fn () => book($member, new SpendPlan(usd(1), usd(0), usd(0))))
        ->toThrow(InsufficientWalletBalanceException::class);
});

it('allows spending exactly the whole balance', function (): void {
    $member = newMemberId();
    fundMember($member, 30000);

    book($member, new SpendPlan(usd(0), usd(30000), usd(0)));

    expect(walletBalance('member', $member, AccountKind::Credit))->toBe(0);
});

it('rejects spending one cent more than the whole balance', function (): void {
    $member = newMemberId();
    fundMember($member, 30000);

    expect(fn () => book($member, new SpendPlan(usd(0), usd(30001), usd(0))))
        ->toThrow(InsufficientWalletBalanceException::class);
});

it('replays on hold:{id}:booking: same transaction, no new rows, same balances, no second outbox row', function (): void {
    $member = newMemberId();
    fundMember($member, 50000);
    $plan = new SpendPlan(usd(0), usd(30000), usd(70000));

    $first = book($member, $plan, 9);

    $counts = fn (): array => [
        DB::table('ledger_transactions')->count(),
        DB::table('ledger_entries')->count(),
        OutboxEvent::query()->where('topic', 'wallet.updated')->count(),
        walletBalance('member', $member, AccountKind::Credit),
        walletBalance('system', 0, AccountKind::Cash),
        walletBalance('venue', 7, AccountKind::VenuePayable),
    ];
    $before = $counts();

    $second = book($member, $plan, 9);

    expect($second->id)->toBe($first->id)
        ->and($second->idempotencyKey)->toBe('hold:9:booking')
        ->and($second->entries)->toEqual($first->entries)
        ->and($counts())->toBe($before);
});

it('does not re-check balances on a replay', function (): void {
    $member = newMemberId();
    fundMember($member, 30000);
    $plan = new SpendPlan(usd(0), usd(30000), usd(1));

    $first = book($member, $plan, 3);
    // Credit is now 0; a fresh posting would be rejected, the replay must still return the stored result.
    $replay = book($member, $plan, 3);

    expect($replay->id)->toBe($first->id);
});

it('throws LogicException outside a transaction', function (): void {
    app(PostBookingPayment::class)->handle(bookingInput(newMemberId(), cardOnly(1000)));
})->throws(LogicException::class);

it('writes nothing when the surrounding transaction rolls back', function (): void {
    try {
        DB::transaction(function (): void {
            app(PostBookingPayment::class)->handle(bookingInput(newMemberId(), cardOnly(1000)));

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(DB::table('ledger_transactions')->count())->toBe(0)
        ->and(DB::table('ledger_entries')->count())->toBe(0);
});

it('rejects a plan that totals zero', function (): void {
    book(newMemberId(), cardOnly(0));
})->throws(InvalidArgumentException::class);
