<?php

declare(strict_types=1);

namespace App\Shared\Idempotency;

use App\Shared\Exceptions\DomainError;

final class IdempotencyMismatchException extends DomainError
{
    public function __construct()
    {
        parent::__construct('This Idempotency-Key was already used with a different request.');
    }

    public function errorCode(): string
    {
        return 'idempotency_mismatch';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
