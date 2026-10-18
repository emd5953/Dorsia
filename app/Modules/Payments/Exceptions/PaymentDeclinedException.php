<?php

declare(strict_types=1);

namespace App\Modules\Payments\Exceptions;

use App\Shared\Exceptions\DomainError;

final class PaymentDeclinedException extends DomainError
{
    public function __construct(public readonly string $declineCode)
    {
        parent::__construct('The payment was declined.');
    }

    public function errorCode(): string
    {
        return 'payment_failed';
    }

    public function httpStatus(): int
    {
        return 402;
    }

    public function details(): array
    {
        return ['decline_code' => $this->declineCode];
    }
}
