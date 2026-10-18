<?php

declare(strict_types=1);

namespace App\Shared\Idempotency;

use App\Shared\Exceptions\DomainError;

final class IdempotencyKeyInvalidException extends DomainError
{
    public function __construct()
    {
        parent::__construct('The Idempotency-Key header must match [A-Za-z0-9._:-] and be 1 to 200 characters.');
    }

    public function errorCode(): string
    {
        return 'idempotency_key_invalid';
    }

    public function httpStatus(): int
    {
        return 400;
    }
}
