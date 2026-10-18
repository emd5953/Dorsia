<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Actions;

use App\Modules\Reservations\Data\ReservationData;
use App\Modules\Reservations\Exceptions\ReservationNotFoundException;
use App\Modules\Reservations\Models\Reservation;

class GetReservation
{
    /**
     * @throws ReservationNotFoundException missing or not owned by $memberId
     */
    public function handle(int $memberId, int $reservationId): ReservationData
    {
        $reservation = Reservation::query()
            ->whereKey($reservationId)
            ->where('member_id', $memberId)
            ->first();

        if ($reservation === null) {
            throw new ReservationNotFoundException;
        }

        return $reservation->toData();
    }
}
