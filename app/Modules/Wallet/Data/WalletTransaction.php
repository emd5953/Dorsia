<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Data;

use App\Modules\Wallet\Enums\LedgerTransactionType;
use App\Shared\Money\Money;
use Carbon\CarbonImmutable;

final readonly class WalletTransaction
{
    public function __construct(
        public int $id,
        public LedgerTransactionType $type,
        public ?int $reservationId,
        public ?int $venueId,
        public Money $amount,
        public int $walletChangeCents,
        public Money $credit,
        public Money $coupon,
        public Money $card,
        public ?Money $forfeited,
        public CarbonImmutable $occurredAt,
    ) {}
}
