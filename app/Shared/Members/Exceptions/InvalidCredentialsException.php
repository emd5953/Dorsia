<?php

declare(strict_types=1);

namespace App\Shared\Members\Exceptions;

use App\Shared\Exceptions\DomainError;

final class InvalidCredentialsException extends DomainError
{
    public function __construct()
    {
        parent::__construct('The provided credentials are incorrect.');
    }

    public function errorCode(): string
    {
        return 'invalid_credentials';
    }

    public function httpStatus(): int
    {
        return 401;
    }
}
