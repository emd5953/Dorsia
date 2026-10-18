<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Exceptions;

use App\Shared\Exceptions\DomainError;

final class ReservationStartedException extends DomainError
{
    public function __construct()
    {
        parent::__construct('The reservation has already started.');
    }

    public function errorCode(): string
    {
        return 'reservation_started';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
