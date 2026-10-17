<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Data;

use App\Shared\Money\Money;

final readonly class CancellationRefundInput
{
    public function __construct(
        public int $memberId,
        public int $venueId,
        public int $reservationId,
        public Money $amount,
        public bool $late,
    ) {}
}
