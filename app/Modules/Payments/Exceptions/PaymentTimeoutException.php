<?php

declare(strict_types=1);

namespace App\Modules\Payments\Exceptions;

use App\Shared\Exceptions\DomainError;

final class PaymentTimeoutException extends DomainError
{
    public function __construct()
    {
        parent::__construct('The payment provider did not answer in time.');
    }

    public function errorCode(): string
    {
        return 'payment_timeout';
    }

    public function httpStatus(): int
    {
        return 504;
    }
}
