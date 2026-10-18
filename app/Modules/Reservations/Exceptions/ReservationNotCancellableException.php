<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Exceptions;

use App\Modules\Reservations\Enums\ReservationStatus;
use App\Shared\Exceptions\DomainError;

final class ReservationNotCancellableException extends DomainError
{
    public function __construct(public readonly ReservationStatus $status)
    {
        parent::__construct('The reservation can no longer be cancelled.');
    }

    public function errorCode(): string
    {
        return 'reservation_not_cancellable';
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
