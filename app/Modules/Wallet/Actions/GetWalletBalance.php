<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Actions;

use App\Modules\Wallet\Data\WalletBalance;
use App\Modules\Wallet\Support\MemberBalances;
use App\Shared\Money\Currency;
use App\Shared\Money\Money;

class GetWalletBalance
{
    public function handle(int $memberId, Currency $currency): WalletBalance
    {
        $balances = MemberBalances::for($memberId, $currency);

        return new WalletBalance(
            $memberId,
            Money::of($balances['credit_cents'], $currency),
            Money::of($balances['coupon_cents'], $currency),
        );
    }
}
