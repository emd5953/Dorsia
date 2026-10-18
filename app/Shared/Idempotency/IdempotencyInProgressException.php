<?php

declare(strict_types=1);

namespace App\Shared\Idempotency;

use App\Shared\Exceptions\DomainError;

final class IdempotencyInProgressException extends DomainError
{
    public function __construct()
    {
        parent::__construct('A request with this Idempotency-Key is still being processed.');
    }

    public function errorCode(): string
    {
        return 'idempotency_in_progress';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
