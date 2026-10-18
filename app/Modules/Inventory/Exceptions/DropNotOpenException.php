<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use App\Shared\Exceptions\DomainError;

final class DropNotOpenException extends DomainError
{
    public function __construct()
    {
        parent::__construct('This drop has not opened yet.');
    }

    public function errorCode(): string
    {
        return 'drop_not_open';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
