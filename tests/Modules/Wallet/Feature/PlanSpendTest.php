<?php

declare(strict_types=1);

use App\Modules\Wallet\Actions\PlanSpend;
use App\Modules\Wallet\Enums\AccountKind;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../WalletHelpers.php';

function planned(int $member, int $cents): array
{
    $plan = app(PlanSpend::class)->handle($member, usd($cents));

    return [$plan->coupon->cents, $plan->credit->cents, $plan->card->cents];
}

it('spends coupon first, then credit, then card', function (): void {
    $member = newMemberId();
    fundMember($member, 40000, AccountKind::Coupon);
    fundMember($member, 20000);

    expect(planned($member, 100000))->toBe([40000, 20000, 40000]);
});

it('takes only part of the coupon when the amount is smaller, leaving credit and card at zero', function (): void {
    $member = newMemberId();
    fundMember($member, 40000, AccountKind::Coupon);
    fundMember($member, 20000);

    expect(planned($member, 30000))->toBe([30000, 0, 0]);
});

it('uses credit before card, and card is zero when credit covers the amount', function (): void {
    $member = newMemberId();
    fundMember($member, 150000);

    expect(planned($member, 100000))->toBe([0, 100000, 0])
        ->and(planned($member, 150000))->toBe([0, 150000, 0])
        ->and(planned($member, 150001))->toBe([0, 150000, 1]);
});

it('puts everything on the card when balances are zero or the accounts do not exist', function (): void {
    expect(planned(newMemberId(), 100000))->toBe([0, 0, 100000]);
});

it('ignores another member\'s balances', function (): void {
    $other = newMemberId();
    fundMember($other, 500000);

    expect(planned(newMemberId(), 100000))->toBe([0, 0, 100000]);
});

it('plans a zero amount as an all-zero plan', function (): void {
    expect(planned(newMemberId(), 0))->toBe([0, 0, 0]);
});

it('takes no locks and writes nothing', function (): void {
    $member = newMemberId();
    fundMember($member, 1000);
    $before = [DB::table('ledger_transactions')->count(), DB::table('ledger_entries')->count()];

    planned($member, 5000);

    expect([DB::table('ledger_transactions')->count(), DB::table('ledger_entries')->count()])->toBe($before);
});
