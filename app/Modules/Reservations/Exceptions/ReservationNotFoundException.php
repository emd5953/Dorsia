<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Exceptions;

use App\Shared\Exceptions\DomainError;

final class ReservationNotFoundException extends DomainError
{
    public function __construct()
    {
        parent::__construct('Reservation not found.');
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
