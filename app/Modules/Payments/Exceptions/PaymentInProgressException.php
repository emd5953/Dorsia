<?php

declare(strict_types=1);

namespace App\Modules\Payments\Exceptions;

use App\Shared\Exceptions\DomainError;

final class PaymentInProgressException extends DomainError
{
    public function __construct()
    {
        parent::__construct('A payment for this hold is already in progress.');
    }

    public function errorCode(): string
    {
        return 'payment_in_progress';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
