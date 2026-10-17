<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Data;

use App\Shared\Money\Money;

final readonly class CancellationRefund
{
    public function __construct(
        public int $transactionId,
        public Money $memberCredit,
        public Money $platformShare,
        public Money $venueRetained,
    ) {}
}
