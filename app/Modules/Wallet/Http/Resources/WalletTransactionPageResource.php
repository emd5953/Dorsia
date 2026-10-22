<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Http\Resources;

use App\Modules\Wallet\Data\WalletTransaction;
use App\Modules\Wallet\Data\WalletTransactionPage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property WalletTransactionPage $resource */
final class WalletTransactionPageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'transactions' => array_map(static fn (WalletTransaction $item): array => [
                'id' => $item->id,
                'type' => $item->type->value,
                'reservation_id' => $item->reservationId,
                'venue_id' => $item->venueId,
                'amount_cents' => $item->amount->cents,
                'currency' => $item->amount->currency->value,
                'wallet_change_cents' => $item->walletChangeCents,
                'credit_cents' => $item->credit->cents,
                'coupon_cents' => $item->coupon->cents,
                'card_cents' => $item->card->cents,
                'forfeited_cents' => $item->forfeited?->cents,
                'occurred_at' => $item->occurredAt->utc()->format('Y-m-d\TH:i:s.u\Z'),
            ], $this->resource->transactions),
            'next_before_id' => $this->resource->nextBeforeId,
        ];
    }
}
