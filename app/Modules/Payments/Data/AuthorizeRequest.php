<?php

declare(strict_types=1);

namespace App\Modules\Payments\Data;

use App\Shared\Money\Money;

final readonly class AuthorizeRequest
{
    /**
     * @param  array<string, string|int>  $metadata  must include 'payment_id'
     */
    public function __construct(
        public Money $amount,
        public string $paymentMethodToken,
        public string $idempotencyKey,
        public array $metadata,
    ) {}
}
