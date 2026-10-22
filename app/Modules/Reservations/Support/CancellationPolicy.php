<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Support;

use App\Modules\Reservations\Data\CancellationPreview;
use App\Modules\Reservations\Data\ReservationData;
use App\Modules\Reservations\Enums\ReservationStatus;
use App\Modules\Reservations\Exceptions\ReservationNotCancellableException;
use App\Modules\Reservations\Exceptions\ReservationStartedException;
use App\Modules\Wallet\Actions\PreviewCancellationRefund;
use Carbon\CarbonImmutable;

final class CancellationPolicy
{
    public function __construct(private readonly PreviewCancellationRefund $refund) {}

    public function preview(ReservationData $reservation, CarbonImmutable $at): CancellationPreview
    {
        if ($reservation->status !== ReservationStatus::Confirmed) {
            throw new ReservationNotCancellableException($reservation->status);
        }
        if ($at->greaterThanOrEqualTo($reservation->startsAt)) {
            throw new ReservationStartedException;
        }
        $hours = config()->integer('reservations.cancel_window_hours');
        $deadline = $reservation->startsAt->subHours($hours);
        // D15: the exact cutoff remains early, including microsecond precision.
        $late = $at->greaterThan($deadline);
        $refund = $this->refund->handle($reservation->minSpend, $late);

        return new CancellationPreview(
            $reservation->id, $late, $refund->memberCredit,
            $reservation->minSpend->subtract($refund->memberCredit),
            $at, $deadline, $reservation->startsAt, $reservation->venueTimezone, $hours,
        );
    }
}
