<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Actions;

use App\Modules\Wallet\Data\SpendPlan;
use App\Modules\Wallet\Support\MemberBalances;
use App\Shared\Money\Money;

class PlanSpend
{
    /**
     * Spend order (§5): coupon first, then credit, remainder on card. Read-only, takes no locks.
     */
    public function handle(int $memberId, Money $amount): SpendPlan
    {
        $balances = MemberBalances::for($memberId, $amount->currency);

        $coupon = $amount->min(Money::of($balances['coupon_cents'], $amount->currency));
        $remaining = $amount->subtract($coupon);
        $credit = $remaining->min(Money::of($balances['credit_cents'], $amount->currency));

        return new SpendPlan($coupon, $credit, $remaining->subtract($credit));
    }
}
