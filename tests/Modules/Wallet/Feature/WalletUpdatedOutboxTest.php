<?php

declare(strict_types=1);

use App\Modules\Wallet\Actions\IssueGoodwillCredit;
use App\Modules\Wallet\Actions\PostBookingPayment;
use App\Modules\Wallet\Actions\PostCancellationRefund;
use App\Modules\Wallet\Data\CancellationRefundInput;
use App\Modules\Wallet\Data\SpendPlan;
use App\Modules\Wallet\Enums\AccountKind;
use App\Shared\Outbox\OutboxEvent;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../WalletHelpers.php';

/** @return list<array<string, mixed>> */
function walletEvents(): array
{
    return OutboxEvent::query()
        ->where('topic', 'wallet.updated')
        ->orderBy('id')
        ->get()
        ->map(fn (OutboxEvent $event): array => $event->payload)
        ->all();
}

it('writes wallet.updated with post-transaction balances on a goodwill credit', function (): void {
    $member = newMemberId();

    app(IssueGoodwillCredit::class)->handle($member, usd(5000), 'goodwill:a');

    expect(walletEvents())->toEqual([
        ['member_id' => $member, 'currency' => 'USD', 'credit_cents' => 5000, 'coupon_cents' => 0],
    ]);
});

it('includes the untouched coupon balance in the payload', function (): void {
    $member = newMemberId();
    fundMember($member, 700, AccountKind::Coupon);
    OutboxEvent::query()->delete();

    app(IssueGoodwillCredit::class)->handle($member, usd(5000), 'goodwill:b');

    expect(walletEvents())->toEqual([
        ['member_id' => $member, 'currency' => 'USD', 'credit_cents' => 5000, 'coupon_cents' => 700],
    ]);
});

it('writes wallet.updated after a booking with the balances after spending', function (): void {
    $member = newMemberId();
    fundMember($member, 50000);
    fundMember($member, 9000, AccountKind::Coupon);
    OutboxEvent::query()->delete();

    DB::transaction(fn () => app(PostBookingPayment::class)->handle(
        bookingInput($member, new SpendPlan(usd(9000), usd(30000), usd(61000)))
    ));

    expect(walletEvents())->toEqual([
        ['member_id' => $member, 'currency' => 'USD', 'credit_cents' => 20000, 'coupon_cents' => 0],
    ]);
});

it('writes nothing for a card-only booking, which touches no member account', function (): void {
    DB::transaction(fn () => app(PostBookingPayment::class)->handle(bookingInput(newMemberId(), cardOnly(100000))));

    expect(walletEvents())->toBe([]);
});

it('writes wallet.updated after a cancellation, early and late', function (bool $late, int $credit): void {
    $member = newMemberId();

    DB::transaction(fn () => app(PostCancellationRefund::class)->handle(
        new CancellationRefundInput($member, 7, 1, usd(100000), $late)
    ));

    expect(walletEvents())->toEqual([
        ['member_id' => $member, 'currency' => 'USD', 'credit_cents' => $credit, 'coupon_cents' => 0],
    ]);
})->with([[false, 100000], [true, 50000]]);

it('rolls the outbox row back with the posting', function (): void {
    try {
        DB::transaction(function (): void {
            app(IssueGoodwillCredit::class)->handle(newMemberId(), usd(100), 'goodwill:rollback');

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(walletEvents())->toBe([])
        ->and(DB::table('ledger_transactions')->count())->toBe(0);
});

it('writes no second wallet.updated when a posting replays', function (): void {
    $member = newMemberId();
    $issue = app(IssueGoodwillCredit::class);

    $first = $issue->handle($member, usd(5000), 'goodwill:replay');
    $second = $issue->handle($member, usd(5000), 'goodwill:replay');

    expect($second->id)->toBe($first->id)
        ->and(walletEvents())->toHaveCount(1)
        ->and(walletBalance('member', $member, AccountKind::Credit))->toBe(5000);
});
