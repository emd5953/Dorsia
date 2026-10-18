<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Enums;

enum ReservationStatus: string
{
    case Confirmed = 'confirmed';
    case CancelledEarly = 'cancelled_early';
    case CancelledLate = 'cancelled_late';
}
