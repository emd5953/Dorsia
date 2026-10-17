<?php

declare(strict_types=1);

namespace App\Modules\Payments\Exceptions;

use App\Shared\Exceptions\DomainError;

final class PaymentConflictException extends DomainError
{
    public function __construct(string $message = 'The payment conflicts with an earlier request.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'payment_conflict';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
