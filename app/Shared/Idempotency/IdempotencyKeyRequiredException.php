<?php

declare(strict_types=1);

namespace App\Shared\Idempotency;

use App\Shared\Exceptions\DomainError;

final class IdempotencyKeyRequiredException extends DomainError
{
    public function __construct()
    {
        parent::__construct('The Idempotency-Key header is required.');
    }

    public function errorCode(): string
    {
        return 'idempotency_key_required';
    }

    public function httpStatus(): int
    {
        return 400;
    }
}
