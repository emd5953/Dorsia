<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use App\Shared\Exceptions\DomainError;

final class HoldNotFoundException extends DomainError
{
    public function __construct()
    {
        parent::__construct('Hold not found.');
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
