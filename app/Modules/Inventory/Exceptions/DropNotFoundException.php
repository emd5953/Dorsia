<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use App\Shared\Exceptions\DomainError;

final class DropNotFoundException extends DomainError
{
    public function __construct()
    {
        parent::__construct('Drop not found.');
    }

    public function errorCode(): string
    {
        return 'not_found';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
