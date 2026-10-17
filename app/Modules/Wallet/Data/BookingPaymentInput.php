<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Data;

final readonly class BookingPaymentInput
{
    public function __construct(
        public int $memberId,
        public int $venueId,
        public int $holdId,
        public int $reservationId,
        public SpendPlan $plan,
    ) {}
}
