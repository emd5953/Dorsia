<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use App\Shared\Exceptions\DomainError;

final class SoldOutException extends DomainError
{
    public function __construct()
    {
        parent::__construct('This slot is sold out.');
    }

    public function errorCode(): string
    {
        return 'sold_out';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
