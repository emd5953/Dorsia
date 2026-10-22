<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Http\Resources;

use App\Modules\Reservations\Data\CancellationPreview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property CancellationPreview $resource */
final class CancellationPreviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $preview = $this->resource;

        return [
            'reservation_id' => $preview->reservationId,
            'refund' => [
                'credit_cents' => $preview->creditRefunded->cents,
                'forfeited_cents' => $preview->forfeited->cents,
                'currency' => $preview->creditRefunded->currency->value,
                'late' => $preview->late,
            ],
            'evaluated_at' => $preview->evaluatedAt->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'early_cancellation_deadline' => $preview->earlyDeadline->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'starts_at' => $preview->startsAt->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'venue_timezone' => $preview->venueTimezone,
            'cancel_window_hours' => $preview->cancelWindowHours,
        ];
    }
}
