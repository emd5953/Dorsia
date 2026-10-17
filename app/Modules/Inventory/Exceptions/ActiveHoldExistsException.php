<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use App\Shared\Exceptions\DomainError;

final class ActiveHoldExistsException extends DomainError
{
    public function __construct()
    {
        parent::__construct('You already hold this slot.');
    }

    public function errorCode(): string
    {
        return 'active_hold_exists';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
