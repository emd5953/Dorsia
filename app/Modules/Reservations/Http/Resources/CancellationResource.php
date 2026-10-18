<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Http\Resources;

use App\Modules\Reservations\Data\CancellationData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property CancellationData $resource
 */
final class CancellationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'reservation' => (new ReservationResource($this->resource->reservation))->toArray($request),
            'refund' => [
                'credit_cents' => $this->resource->creditRefunded->cents,
                'forfeited_cents' => $this->resource->forfeited->cents,
                'currency' => $this->resource->creditRefunded->currency->value,
                'late' => $this->resource->late,
            ],
        ];
    }
}
