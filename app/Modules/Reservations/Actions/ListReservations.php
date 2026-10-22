<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Actions;

use App\Modules\Reservations\Data\ReservationData;
use App\Modules\Reservations\Enums\ReservationStatus;
use App\Modules\Reservations\Models\Reservation;
use App\Modules\Wallet\Actions\GetCancellationCredits;
use App\Shared\Money\Money;

class ListReservations
{
    public function __construct(private readonly GetCancellationCredits $credits) {}

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

        $cancelledIds = array_values($reservations->filter(static fn (Reservation $reservation): bool => $reservation->status !== ReservationStatus::Confirmed)
            ->map(static fn (Reservation $reservation): int => $reservation->id)->all());
        $credits = $this->credits->handle($memberId, $cancelledIds);

        return array_values($reservations->map(static fn (Reservation $reservation): ReservationData => $reservation->toData(
            $reservation->status === ReservationStatus::Confirmed ? null : ($credits[$reservation->id] ?? Money::of(0, $reservation->currency)),
        ))->all());
    }
}
