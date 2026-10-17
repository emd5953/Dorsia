<?php

declare(strict_types=1);

namespace App\Modules\Payments\Data;

use App\Shared\Money\Money;

final readonly class AuthorizePaymentInput
{
    public function __construct(
        public int $memberId,
        public int $holdId,
        public Money $amount,
        public string $cardToken,
    ) {}
}
