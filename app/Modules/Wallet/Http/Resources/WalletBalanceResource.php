<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Http\Resources;

use App\Modules\Wallet\Data\WalletBalance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property WalletBalance $resource
 */
final class WalletBalanceResource extends JsonResource
{
    /**
     * @return array{currency: string, credit_cents: int, coupon_cents: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'currency' => $this->resource->credit->currency->value,
            'credit_cents' => $this->resource->credit->cents,
            'coupon_cents' => $this->resource->coupon->cents,
        ];
    }
}
