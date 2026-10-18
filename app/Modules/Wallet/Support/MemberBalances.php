<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Support;

use App\Modules\Wallet\Enums\AccountKind;
use App\Modules\Wallet\Enums\AccountOwnerType;
use App\Modules\Wallet\Models\LedgerAccount;
use App\Shared\Money\Currency;

/**
 * Cached credit and coupon balances of one member; a missing account counts as zero.
 */
final class MemberBalances
{
    /**
     * @return array{credit_cents: int, coupon_cents: int}
     */
    public static function for(int $memberId, Currency $currency): array
    {
        /** @var array<string, int> $balances */
        $balances = LedgerAccount::query()
            ->where('owner_type', AccountOwnerType::Member->value)
            ->where('owner_id', $memberId)
            ->where('currency', $currency->value)
            ->whereIn('kind', [AccountKind::Credit->value, AccountKind::Coupon->value])
            ->pluck('balance_cents', 'kind')
            ->all();

        return [
            'credit_cents' => $balances[AccountKind::Credit->value] ?? 0,
            'coupon_cents' => $balances[AccountKind::Coupon->value] ?? 0,
        ];
    }
}
