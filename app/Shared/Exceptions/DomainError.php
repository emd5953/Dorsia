<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

use RuntimeException;

abstract class DomainError extends RuntimeException
{
    /** C§9 code string. */
    abstract public function errorCode(): string;

    abstract public function httpStatus(): int;

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return [];
    }
}
