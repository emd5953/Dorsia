<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Http\Controllers;

use App\Modules\Wallet\Actions\GetWalletBalance;
use App\Modules\Wallet\Http\Resources\WalletBalanceResource;
use App\Shared\Members\Member;
use App\Shared\Money\Currency;
use Illuminate\Http\Request;

final class WalletController
{
    public function __invoke(Request $request, GetWalletBalance $balance): WalletBalanceResource
    {
        /** @var Member $member */
        $member = $request->user();

        return new WalletBalanceResource(
            $balance->handle($member->id, Currency::from(config()->string('wallet.currency'))),
        );
    }
}
