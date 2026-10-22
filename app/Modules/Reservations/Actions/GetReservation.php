<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Actions;

use App\Modules\Reservations\Data\ReservationData;
use App\Modules\Reservations\Enums\ReservationStatus;
use App\Modules\Reservations\Exceptions\ReservationNotFoundException;
use App\Modules\Reservations\Models\Reservation;
use App\Modules\Wallet\Actions\GetCancellationCredits;
use App\Shared\Money\Money;

class GetReservation
{
    public function __construct(private readonly GetCancellationCredits $credits) {}

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

        if ($reservation->status === ReservationStatus::Confirmed) {
            return $reservation->toData();
        }
        $credits = $this->credits->handle($memberId, [$reservationId]);

        return $reservation->toData($credits[$reservationId] ?? Money::of(0, $reservation->currency));
    }
}
