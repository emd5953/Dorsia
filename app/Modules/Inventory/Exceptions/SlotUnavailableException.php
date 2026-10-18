<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use App\Shared\Exceptions\DomainError;

final class SlotUnavailableException extends DomainError
{
    public function __construct()
    {
        parent::__construct('This slot is not available.');
    }

    public function errorCode(): string
    {
        return 'slot_unavailable';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
