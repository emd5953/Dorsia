<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Actions;

use App\Modules\Inventory\Actions\ReleaseBooking;
use App\Modules\Reservations\Data\CancellationData;
use App\Modules\Reservations\Enums\ReservationStatus;
use App\Modules\Reservations\Exceptions\ReservationNotCancellableException;
use App\Modules\Reservations\Exceptions\ReservationNotFoundException;
use App\Modules\Reservations\Exceptions\ReservationStartedException;
use App\Modules\Reservations\Models\Reservation;
use App\Modules\Wallet\Actions\PostCancellationRefund;
use App\Modules\Wallet\Data\CancellationRefundInput;
use App\Shared\Support\Transactions;
use Illuminate\Support\Facades\DB;

class CancelReservation
{
    public function __construct(
        private readonly ReleaseBooking $releaseBooking,
        private readonly PostCancellationRefund $postRefund,
    ) {}

    /**
     * C§6.3. One transaction; the refund always goes to member credit, however the booking was paid.
     *
     * @throws ReservationNotFoundException
     * @throws ReservationNotCancellableException
     * @throws ReservationStartedException
     */
    public function handle(int $memberId, int $reservationId): CancellationData
    {
        Transactions::assertNone();

        return DB::transaction(function () use ($memberId, $reservationId): CancellationData {
            $reservation = Reservation::query()
                ->whereKey($reservationId)
                ->where('member_id', $memberId)
                ->lockForUpdate()
                ->first();

            if ($reservation === null) {
                throw new ReservationNotFoundException;
            }

            if ($reservation->status !== ReservationStatus::Confirmed) {
                throw new ReservationNotCancellableException($reservation->status);
            }

            $now = now();

            if ($now->greaterThanOrEqualTo($reservation->starts_at)) {
                throw new ReservationStartedException;
            }

            // Exactly cancel_window_hours before starts_at is still early (D15).
            $late = $now->greaterThan($reservation->starts_at->subHours(config()->integer('reservations.cancel_window_hours')));

            $reservation->status = $late ? ReservationStatus::CancelledLate : ReservationStatus::CancelledEarly;
            $reservation->cancelled_at = $now;
            $reservation->save();

            $this->releaseBooking->handle($reservation->slot_id);

            $minSpend = $reservation->toData()->minSpend;

            $refund = $this->postRefund->handle(new CancellationRefundInput(
                $memberId,
                $reservation->venue_id,
                $reservation->id,
                $minSpend,
                $late,
            ));

            return new CancellationData(
                $reservation->toData(),
                $late,
                $refund->memberCredit,
                $minSpend->subtract($refund->memberCredit),
            );
        });
    }
}
