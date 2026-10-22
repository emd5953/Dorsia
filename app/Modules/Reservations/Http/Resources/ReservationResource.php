<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Http\Resources;

use App\Modules\Reservations\Data\ReservationData;
use App\Modules\Reservations\Enums\ReservationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property ReservationData $resource
 */
final class ReservationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $reservation = $this->resource;

        return [
            'id' => $reservation->id,
            'hold_id' => $reservation->holdId,
            'status' => $reservation->status->value,
            'slot_id' => $reservation->slotId,
            'venue_id' => $reservation->venueId,
            'party_size' => $reservation->partySize,
            'starts_at' => self::iso($reservation->startsAt),
            'local_starts_at' => $reservation->startsAt->setTimezone($reservation->venueTimezone)->format('Y-m-d\TH:i:sP'),
            'min_spend_cents' => $reservation->minSpend->cents,
            'currency' => $reservation->minSpend->currency->value,
            'payment' => [
                'coupon_cents' => $reservation->payment->coupon->cents,
                'credit_cents' => $reservation->payment->credit->cents,
                'card_cents' => $reservation->payment->card->cents,
            ],
            'refund' => $reservation->status === ReservationStatus::Confirmed || $reservation->cancellationCredit === null ? null : [
                'credit_cents' => $reservation->cancellationCredit->cents,
                'forfeited_cents' => $reservation->minSpend->subtract($reservation->cancellationCredit)->cents,
                'currency' => $reservation->cancellationCredit->currency->value,
                'late' => $reservation->status === ReservationStatus::CancelledLate,
            ],
            'confirmed_at' => self::iso($reservation->confirmedAt),
            'cancelled_at' => $reservation->cancelledAt === null ? null : self::iso($reservation->cancelledAt),
        ];
    }

    private static function iso(CarbonImmutable $time): string
    {
        return $time->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
