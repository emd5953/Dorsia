<?php

declare(strict_types=1);

namespace App\Modules\Payments\Exceptions;

use App\Shared\Exceptions\DomainError;

final class InvalidWebhookSignatureException extends DomainError
{
    public function __construct()
    {
        parent::__construct('Invalid webhook signature.');
    }

    public function errorCode(): string
    {
        return 'invalid_signature';
    }

    public function httpStatus(): int
    {
        return 401;
    }
}
