<?php

declare(strict_types=1);

namespace App\Modules\Payments\Data;

use App\Modules\Payments\Enums\PaymentStatus;
use App\Shared\Money\Money;

final readonly class PaymentData
{
    public function __construct(
        public int $id,
        public int $memberId,
        public int $holdId,
        public ?int $reservationId,
        public PaymentStatus $status,
        public Money $amount,
        public ?string $providerRef,
        public ?string $declineCode,
    ) {}
}
