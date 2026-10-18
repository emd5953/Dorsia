<?php

declare(strict_types=1);

use App\Modules\Wallet\Actions\IssueGoodwillCredit;
use App\Modules\Wallet\Actions\PostBookingPayment;
use App\Modules\Wallet\Actions\PostCancellationRefund;
use App\Modules\Wallet\Actions\ReconcileLedger;
use App\Modules\Wallet\Data\BookingPaymentInput;
use App\Modules\Wallet\Data\CancellationRefundInput;
use App\Modules\Wallet\Data\SpendPlan;
use App\Modules\Wallet\Enums\AccountKind;
use App\Modules\Wallet\Exceptions\InsufficientWalletBalanceException;
use App\Shared\Outbox\OutboxEvent;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concurrency;

require_once __DIR__.'/../WalletHelpers.php';

/**
 * Runs the closure in a transaction in a forked child and reduces the outcome to a string.
 *
 * @param  Closure(): mixed  $work
 */
function outcome(Closure $work): string
{
    try {
        DB::transaction($work);

        return 'ok';
    } catch (InsufficientWalletBalanceException) {
        return 'insufficient';
    } catch (Throwable $e) {
        return $e::class.': '.$e->getMessage();
    }
}

it('never overdraws: 20 parallel bookings of 30000 credit against 100000 let exactly 3 succeed', function (): void {
    $member = newMemberId();
    fundMember($member, 100000);

    $results = Concurrency::run(array_map(
        fn (int $i): Closure => fn (): string => outcome(fn () => app(PostBookingPayment::class)->handle(
            bookingInput($member, new SpendPlan(usd(0), usd(30000), usd(70000)), holdId: $i + 1, reservationId: $i + 1)
        )),
        range(0, 19),
    ));

    expect(array_count_values($results))->toEqual(['ok' => 3, 'insufficient' => 17])
        ->and(walletBalance('member', $member, AccountKind::Credit))->toBe(10000)
        ->and(walletBalance('venue', 7, AccountKind::VenuePayable))->toBe(300000)
        ->and(walletBalance('system', 0, AccountKind::Cash))->toBe(210000)
        ->and(DB::table('ledger_transactions')->where('type', 'booking')->count())->toBe(3)
        ->and(DB::table('ledger_entries')->count())->toBe(2 + 3 * 3)
        ->and(OutboxEvent::query()->where('topic', 'wallet.updated')->count())->toBe(3)
        ->and(app(ReconcileLedger::class)->handle()->clean())->toBeTrue();
});

it('posts one transaction when 20 processes cancel the same reservation', function (): void {
    $member = newMemberId();

    DB::transaction(fn () => app(PostBookingPayment::class)->handle(bookingInput($member, cardOnly(100000))));

    $results = Concurrency::run(array_map(
        fn (int $i): Closure => function () use ($member): string {
            try {
                return 'tx:'.DB::transaction(fn () => app(PostCancellationRefund::class)->handle(
                    new CancellationRefundInput($member, 7, 1, usd(100000), true)
                ))->transactionId;
            } catch (Throwable $e) {
                return $e::class.': '.$e->getMessage();
            }
        },
        range(0, 19),
    ));

    expect(array_unique($results))->toHaveCount(1)
        ->and($results[0])->toStartWith('tx:')
        ->and(DB::table('ledger_transactions')->where('idempotency_key', 'reservation:1:cancel')->count())->toBe(1)
        ->and(DB::table('ledger_transactions')->count())->toBe(2)
        ->and(walletBalance('member', $member, AccountKind::Credit))->toBe(50000)
        ->and(walletBalance('system', 0, AccountKind::Platform))->toBe(25000)
        ->and(walletBalance('venue', 7, AccountKind::VenuePayable))->toBe(25000)
        ->and(OutboxEvent::query()->where('topic', 'wallet.updated')->count())->toBe(1)
        ->and(app(ReconcileLedger::class)->handle()->clean())->toBeTrue();
});

it('posts one booking when 20 processes replay the same hold', function (): void {
    $member = newMemberId();
    fundMember($member, 50000);

    $results = Concurrency::run(array_map(
        fn (int $i): Closure => function () use ($member): string {
            try {
                return 'tx:'.DB::transaction(fn () => app(PostBookingPayment::class)->handle(
                    bookingInput($member, new SpendPlan(usd(0), usd(30000), usd(70000)), holdId: 5)
                ))->id;
            } catch (Throwable $e) {
                return $e::class.': '.$e->getMessage();
            }
        },
        range(0, 19),
    ));

    expect(array_unique($results))->toHaveCount(1)
        ->and($results[0])->toStartWith('tx:')
        ->and(DB::table('ledger_transactions')->where('type', 'booking')->count())->toBe(1)
        ->and(walletBalance('member', $member, AccountKind::Credit))->toBe(20000)
        ->and(walletBalance('venue', 7, AccountKind::VenuePayable))->toBe(100000)
        ->and(OutboxEvent::query()->where('topic', 'wallet.updated')->count())->toBe(1)
        ->and(app(ReconcileLedger::class)->handle()->clean())->toBeTrue();
});

it('issues one goodwill credit when 20 processes use the same key', function (): void {
    $member = newMemberId();

    $results = Concurrency::run(array_map(
        fn (int $i): Closure => function () use ($member): string {
            try {
                return 'tx:'.app(IssueGoodwillCredit::class)->handle($member, usd(5000), 'goodwill:race')->id;
            } catch (Throwable $e) {
                return $e::class.': '.$e->getMessage();
            }
        },
        range(0, 19),
    ));

    expect(array_unique($results))->toHaveCount(1)
        ->and(walletBalance('member', $member, AccountKind::Credit))->toBe(5000)
        ->and(DB::table('ledger_transactions')->count())->toBe(1);
});

it('books 20 different members into one venue_payable without deadlock; balance is the sum', function (): void {
    $members = array_map(fn (int $i): int => newMemberId(), range(0, 19));

    // Every second member pays part with credit; their accounts are distinct, the venue and card accounts are shared.
    foreach ($members as $i => $member) {
        if ($i % 2 === 0) {
            fundMember($member, 5000);
        }
    }

    $amounts = array_map(fn (int $i): int => 10000 + $i * 137, range(0, 19));

    $results = Concurrency::run(array_map(
        fn (int $i): Closure => fn (): string => outcome(fn () => app(PostBookingPayment::class)->handle(
            bookingInput(
                $members[$i],
                $i % 2 === 0
                    ? new SpendPlan(usd(0), usd(2000), usd($amounts[$i] - 2000))
                    : cardOnly($amounts[$i]),
                holdId: $i + 1,
                reservationId: $i + 1,
            )
        )),
        range(0, 19),
    ));

    expect(array_count_values($results))->toBe(['ok' => 20])
        ->and(walletBalance('venue', 7, AccountKind::VenuePayable))->toBe(array_sum($amounts))
        ->and(walletBalance('system', 0, AccountKind::Cash))->toBe(array_sum($amounts) - 10 * 2000)
        ->and(DB::table('ledger_transactions')->where('type', 'booking')->count())->toBe(20)
        ->and(DB::table('ledger_accounts')->where('owner_type', 'venue')->count())->toBe(1)
        ->and(DB::table('ledger_accounts')->where('owner_type', 'system')->where('kind', 'cash')->count())->toBe(1)
        ->and(app(ReconcileLedger::class)->handle()->clean())->toBeTrue();
});

it('keeps balances exact when bookings and cancellations interleave across venues', function (): void {
    $member = newMemberId();
    fundMember($member, 200000);

    // 10 bookings (venue 7 and 8 alternating, 10000 credit each) while 10 goodwill credits arrive at once.
    $closures = [];

    foreach (range(0, 9) as $i) {
        $closures[] = fn (): string => outcome(fn () => app(PostBookingPayment::class)->handle(
            new BookingPaymentInput($member, 7 + ($i % 2), $i + 1, $i + 1, new SpendPlan(usd(0), usd(10000), usd(0)))
        ));
        $closures[] = fn (): string => outcome(fn () => app(IssueGoodwillCredit::class)->handle($member, usd(1000), "goodwill:mix:{$i}"));
    }

    $results = Concurrency::run($closures);

    expect(array_count_values($results))->toBe(['ok' => 20])
        ->and(walletBalance('member', $member, AccountKind::Credit))->toBe(200000 - 100000 + 10000)
        ->and(walletBalance('venue', 7, AccountKind::VenuePayable))->toBe(50000)
        ->and(walletBalance('venue', 8, AccountKind::VenuePayable))->toBe(50000)
        ->and(app(ReconcileLedger::class)->handle()->clean())->toBeTrue();
});
