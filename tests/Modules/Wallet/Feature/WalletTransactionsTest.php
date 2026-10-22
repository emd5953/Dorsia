<?php

declare(strict_types=1);

use App\Modules\Wallet\Actions\IssueGoodwillCredit;
use App\Modules\Wallet\Actions\ListWalletTransactions;
use App\Modules\Wallet\Actions\PostBookingPayment;
use App\Modules\Wallet\Actions\PostCancellationRefund;
use App\Modules\Wallet\Data\CancellationRefundInput;
use App\Modules\Wallet\Data\SpendPlan;
use App\Modules\Wallet\Enums\AccountKind;
use App\Shared\Members\Member;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../WalletHelpers.php';

beforeEach(function (): void {
    $this->member = Member::factory()->create();
    $this->token = $this->member->createToken('wallet-history')->plainTextToken;
    $this->withToken($this->token);
});

it('shows actual booking allocations and refunds after policy changes with precise timestamps', function (bool $late, int $expectedRefund): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-07T15:00:00.123456Z'));
    app(IssueGoodwillCredit::class)->handle($this->member->id, usd(20000), 'history-credit');
    fundMember($this->member->id, 10000, AccountKind::Coupon);
    DB::transaction(fn () => app(PostBookingPayment::class)->handle(bookingInput($this->member->id, new SpendPlan(usd(10000), usd(20000), usd(45001)), 10, 7, 90)));
    DB::transaction(fn () => app(PostCancellationRefund::class)->handle(new CancellationRefundInput($this->member->id, 7, 90, usd(75001), $late)));
    $before = [DB::table('ledger_transactions')->count(), DB::table('ledger_entries')->count(), DB::table('outbox_events')->count(), walletBalance('member', $this->member->id, AccountKind::Credit)];
    config(['wallet.late_cancel.member_bps' => 10000, 'wallet.late_cancel.platform_bps' => 10000]);
    $response = $this->getJson('/api/wallet/transactions')->assertOk();
    expect($response->headers->get('cache-control'))->toContain('no-store');
    $items = $response->json('data.transactions');
    expect($items)->toHaveCount(4)
        ->and($items[0])->toMatchArray(['type' => $late ? 'cancel_late' : 'cancel_early', 'reservation_id' => 90, 'venue_id' => 7, 'amount_cents' => $expectedRefund, 'wallet_change_cents' => $expectedRefund, 'credit_cents' => $expectedRefund, 'coupon_cents' => 0, 'card_cents' => 0, 'forfeited_cents' => 75001 - $expectedRefund, 'occurred_at' => '2026-11-07T15:00:00.123456Z'])
        ->and($items[1])->toMatchArray(['type' => 'booking', 'reservation_id' => 90, 'amount_cents' => 75001, 'wallet_change_cents' => -30000, 'coupon_cents' => 10000, 'credit_cents' => 20000, 'card_cents' => 45001, 'forfeited_cents' => null])
        ->and($items[2])->toMatchArray(['type' => 'goodwill_credit', 'amount_cents' => 10000, 'wallet_change_cents' => 10000, 'coupon_cents' => 10000])
        ->and($items[3])->toMatchArray(['type' => 'goodwill_credit', 'reservation_id' => null, 'amount_cents' => 20000])
        ->and($response->json('data.next_before_id'))->toBeNull();
    expect($this->getJson('/api/wallet/transactions')->assertOk()->json())->toBe($response->json())
        ->and([DB::table('ledger_transactions')->count(), DB::table('ledger_entries')->count(), DB::table('outbox_events')->count(), walletBalance('member', $this->member->id, AccountKind::Credit)])->toBe($before);
})->with([[false, 75001], [true, 37500]]);

it('includes card-only payments without calling them a wallet debit', function (): void {
    DB::transaction(fn () => app(PostBookingPayment::class)->handle(bookingInput($this->member->id, cardOnly(50000))));
    $this->getJson('/api/wallet/transactions')->assertOk()->assertJsonPath('data.transactions.0.amount_cents', 50000)->assertJsonPath('data.transactions.0.card_cents', 50000)->assertJsonPath('data.transactions.0.wallet_change_cents', 0);
});

it('shows zero member refunds only when a cancellation transaction was posted', function (int $platformBps, int $count): void {
    config(['wallet.late_cancel.member_bps' => 0, 'wallet.late_cancel.platform_bps' => $platformBps]);
    DB::transaction(fn () => app(PostCancellationRefund::class)->handle(new CancellationRefundInput($this->member->id, 7, 91, usd(75000), true)));
    $response = $this->getJson('/api/wallet/transactions')->assertOk()->assertJsonCount($count, 'data.transactions');
    if ($count === 1) {
        $response->assertJsonPath('data.transactions.0.amount_cents', 0)->assertJsonPath('data.transactions.0.wallet_change_cents', 0)->assertJsonPath('data.transactions.0.forfeited_cents', 75000);
    }
})->with([[0, 0], [2500, 1]]);

it('keeps ownership on every page and requires authentication', function (): void {
    $other = Member::factory()->create();
    app(IssueGoodwillCredit::class)->handle($other->id, usd(1000), 'other-credit');
    DB::transaction(fn () => app(PostBookingPayment::class)->handle(bookingInput($other->id, cardOnly(10000))));
    $this->getJson('/api/wallet/transactions')->assertOk()->assertExactJson(['data' => ['transactions' => [], 'next_before_id' => null]]);
    $this->getJson('/api/wallet/transactions?before_id=999999')->assertOk()->assertJsonCount(0, 'data.transactions');
    app('auth')->forgetGuards();
    $this->flushHeaders();
    $this->getJson('/api/wallet/transactions')->assertUnauthorized();
});

it('paginates with no duplicates when a newer posting arrives and uses two queries per page', function (): void {
    $ids = [];
    for ($i = 0; $i < 23; $i++) {
        $ids[] = app(IssueGoodwillCredit::class)->handle($this->member->id, usd(100), 'history-page-'.$i)->id;
    }
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $page = app(ListWalletTransactions::class)->handle($this->member->id);
        expect(DB::getQueryLog())->toHaveCount(2);
    } finally {
        DB::disableQueryLog();
    }
    expect($page->transactions)->toHaveCount(20)->and($page->nextBeforeId)->toBe($ids[3]);
    app(IssueGoodwillCredit::class)->handle($this->member->id, usd(100), 'new-during-pagination');
    $older = $this->getJson('/api/wallet/transactions?before_id='.$page->nextBeforeId)->assertOk()->json('data');
    expect(array_column($older['transactions'], 'id'))->toBe(array_reverse(array_slice($ids, 0, 3)))
        ->and($older['next_before_id'])->toBeNull();
});

it('rejects malformed cursor values', function (string $cursor): void {
    $this->getJson('/api/wallet/transactions?before_id='.$cursor)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
})->with(['0', '-1', 'word', '1.5', '9007199254740992']);
