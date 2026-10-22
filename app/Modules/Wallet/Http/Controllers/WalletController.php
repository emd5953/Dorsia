<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Http\Controllers;

use App\Modules\Wallet\Actions\GetWalletBalance;
use App\Modules\Wallet\Actions\ListWalletTransactions;
use App\Modules\Wallet\Http\Requests\WalletTransactionsRequest;
use App\Modules\Wallet\Http\Resources\WalletBalanceResource;
use App\Modules\Wallet\Http\Resources\WalletTransactionPageResource;
use App\Shared\Members\Member;
use App\Shared\Money\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WalletController
{
    public function transactions(WalletTransactionsRequest $request, ListWalletTransactions $list): JsonResponse
    {
        /** @var Member $member */
        $member = $request->user();

        return (new WalletTransactionPageResource($list->handle($member->id, $request->has('before_id') ? $request->integer('before_id') : null)))
            ->response()->header('Cache-Control', 'private, no-store');
    }

    public function __invoke(Request $request, GetWalletBalance $balance): WalletBalanceResource
    {
        /** @var Member $member */
        $member = $request->user();

        return new WalletBalanceResource(
            $balance->handle($member->id, Currency::from(config()->string('wallet.currency'))),
        );
    }
}
