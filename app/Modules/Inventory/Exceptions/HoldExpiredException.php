<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use App\Shared\Exceptions\DomainError;

final class HoldExpiredException extends DomainError
{
    public function __construct()
    {
        parent::__construct('The hold has expired.');
    }

    public function errorCode(): string
    {
        return 'hold_expired';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
