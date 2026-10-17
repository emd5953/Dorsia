<?php

declare(strict_types=1);

use App\Modules\Wallet\Actions\PostBookingPayment;
use App\Modules\Wallet\Actions\PostCancellationRefund;
use App\Modules\Wallet\Data\CancellationRefund;
use App\Modules\Wallet\Data\CancellationRefundInput;
use App\Modules\Wallet\Enums\AccountKind;
use App\Modules\Wallet\Enums\LedgerTransactionType;
use App\Shared\Outbox\OutboxEvent;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../WalletHelpers.php';

function cancel(int $memberId, int $cents, bool $late, int $reservationId = 1): CancellationRefund
{
    return DB::transaction(fn () => app(PostCancellationRefund::class)->handle(
        new CancellationRefundInput($memberId, 7, $reservationId, usd($cents), $late)
    ));
}

function txType(int $id): string
{
    return (string) DB::table('ledger_transactions')->where('id', $id)->value('type');
}

it('cancels 8+ hours out: venue_payable debited, member credit gets the whole amount', function (): void {
    $member = newMemberId();
    DB::transaction(fn () => app(PostBookingPayment::class)->handle(
        bookingInput($member, cardOnly(100000), 1, 7, 1)
    ));

    $refund = cancel($member, 100000, false);

    expect(txType($refund->transactionId))->toBe(LedgerTransactionType::CancelEarly->value)
        ->and(walletEntries($refund->transactionId))->toBe(['venue:7:venue_payable' => 100000, 'member:'.$member.':credit' => -100000])
        ->and($refund->memberCredit->cents)->toBe(100000)
        ->and($refund->platformShare->cents)->toBe(0)
        ->and($refund->venueRetained->cents)->toBe(0)
        ->and(walletBalance('venue', 7, AccountKind::VenuePayable))->toBe(0)
        ->and(walletBalance('member', $member, AccountKind::Credit))->toBe(100000)
        ->and(walletAccountExists('system', 0, AccountKind::Platform))->toBeFalse();
});

it('cancels inside 8 hours: venue debited 750, member credit 500, platform 250, venue keeps 250', function (): void {
    $member = newMemberId();
    DB::transaction(fn () => app(PostBookingPayment::class)->handle(
        bookingInput($member, cardOnly(100000), 1, 7, 1)
    ));

    $refund = cancel($member, 100000, true);

    expect(txType($refund->transactionId))->toBe(LedgerTransactionType::CancelLate->value)
        ->and(walletEntries($refund->transactionId))->toBe([
            'venue:7:venue_payable' => 75000,
            'member:'.$member.':credit' => -50000,
            'system:0:platform' => -25000,
        ])
        ->and($refund->memberCredit->cents)->toBe(50000)
        ->and($refund->platformShare->cents)->toBe(25000)
        ->and($refund->venueRetained->cents)->toBe(25000)
        ->and(walletBalance('venue', 7, AccountKind::VenuePayable))->toBe(25000)
        ->and(walletBalance('member', $member, AccountKind::Credit))->toBe(50000)
        ->and(walletBalance('system', 0, AccountKind::Platform))->toBe(25000);
});

it('puts the odd cent on the venue for 99999 (D14)', function (): void {
    $member = newMemberId();

    $refund = cancel($member, 99999, true);

    expect(walletEntries($refund->transactionId))->toBe([
        'venue:7:venue_payable' => 74998,
        'member:'.$member.':credit' => -49999,
        'system:0:platform' => -24999,
    ])
        ->and($refund->memberCredit->cents)->toBe(49999)
        ->and($refund->platformShare->cents)->toBe(24999)
        ->and($refund->venueRetained->cents)->toBe(25001);
});

it('follows wallet.late_cancel.* config without code changes (6000/2000)', function (): void {
    config(['wallet.late_cancel.member_bps' => 6000, 'wallet.late_cancel.platform_bps' => 2000]);
    $member = newMemberId();

    $refund = cancel($member, 100000, true);

    expect(walletEntries($refund->transactionId))->toBe([
        'venue:7:venue_payable' => 80000,
        'member:'.$member.':credit' => -60000,
        'system:0:platform' => -20000,
    ])
        ->and($refund->venueRetained->cents)->toBe(20000);
});

it('omits the platform line when its share is zero', function (): void {
    config(['wallet.late_cancel.platform_bps' => 0]);
    $member = newMemberId();

    $refund = cancel($member, 100000, true);

    expect(walletEntries($refund->transactionId))->toBe(['venue:7:venue_payable' => 50000, 'member:'.$member.':credit' => -50000])
        ->and($refund->platformShare->cents)->toBe(0)
        ->and($refund->venueRetained->cents)->toBe(50000);
});

it('omits the member line when the member share floors to zero', function (): void {
    $member = newMemberId();

    // 1 cent: member 0, platform 0 -> nothing to post.
    expect(fn () => cancel($member, 1, true))->toThrow(InvalidArgumentException::class);

    // 3 cents: member 1, platform 0 -> two lines only.
    $refund = cancel($member, 3, true, 2);

    expect(walletEntries($refund->transactionId))->toBe(['venue:7:venue_payable' => 1, 'member:'.$member.':credit' => -1])
        ->and($refund->venueRetained->cents)->toBe(2);
});

it('refuses a split above 100% at runtime', function (): void {
    config(['wallet.late_cancel.member_bps' => 9000, 'wallet.late_cancel.platform_bps' => 2000]);

    cancel(newMemberId(), 1000, true);
})->throws(InvalidArgumentException::class);

it('replays on reservation:{id}:cancel with the stored result, posting nothing twice', function (bool $late): void {
    $member = newMemberId();

    $first = cancel($member, 99999, $late, 4);

    $counts = fn (): array => [
        DB::table('ledger_transactions')->count(),
        DB::table('ledger_entries')->count(),
        OutboxEvent::query()->count(),
        walletBalance('member', $member, AccountKind::Credit),
        walletBalance('venue', 7, AccountKind::VenuePayable),
        walletBalance('system', 0, AccountKind::Platform),
    ];
    $before = $counts();

    $second = cancel($member, 99999, $late, 4);

    expect($second)->toEqual($first)
        ->and($counts())->toBe($before);
})->with([true, false]);

it('returns the stored result even if the replay asks for a different amount', function (): void {
    $member = newMemberId();
    $first = cancel($member, 100000, true, 5);

    $second = cancel($member, 1000, false, 5);

    expect($second)->toEqual($first);
});

it('throws LogicException outside a transaction', function (): void {
    app(PostCancellationRefund::class)->handle(new CancellationRefundInput(newMemberId(), 7, 1, usd(1000), false));
})->throws(LogicException::class);
