<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Actions;

use App\Modules\Reservations\Data\ReservationData;
use App\Modules\Reservations\Models\Reservation;

class ListReservations
{
    /**
     * Newest `starts_at` first, at most 100.
     *
     * @return list<ReservationData>
     */
    public function handle(int $memberId): array
    {
        $reservations = Reservation::query()
            ->where('member_id', $memberId)
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return array_values($reservations->map(static fn (Reservation $reservation): ReservationData => $reservation->toData())->all());
    }
}
