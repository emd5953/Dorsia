<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use App\Modules\Inventory\Enums\HoldStatus;
use App\Shared\Exceptions\DomainError;

final class HoldNotActiveException extends DomainError
{
    public function __construct(public readonly HoldStatus $status)
    {
        parent::__construct('The hold is no longer active.');
    }

    public function errorCode(): string
    {
        return 'hold_not_active';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function details(): array
    {
        return ['status' => $this->status->value];
    }
}
