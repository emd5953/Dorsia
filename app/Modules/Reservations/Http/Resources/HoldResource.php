<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Http\Resources;

use App\Modules\Inventory\Data\SlotData;
use App\Modules\Reservations\Data\HoldView;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property HoldView $resource
 */
final class HoldResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $hold = $this->resource->hold;

        return [
            'id' => $hold->id,
            'status' => $hold->status->value,
            'party_size' => $hold->partySize,
            'expires_at' => $this->iso($hold->expiresAt),
            'slot' => $this->slot($hold->slot),
            'quote' => null,
        ];
    }

    /**
     * C§8 Slot shape, built from SlotData (Inventory's SlotResource is internal).
     *
     * @return array<string, mixed>
     */
    private function slot(SlotData $slot): array
    {
        $now = now();

        return [
            'id' => $slot->id,
            'venue_id' => $slot->venueId,
            'drop_id' => $slot->dropId,
            'starts_at' => $this->iso($slot->startsAt),
            'local_starts_at' => $slot->startsAt->setTimezone($slot->venueTimezone)->format('Y-m-d\TH:i:sP'),
            'capacity' => $slot->capacity,
            'available' => $slot->available(),
            'min_party_size' => $slot->minPartySize,
            'max_party_size' => $slot->maxPartySize,
            'released_at' => $this->iso($slot->releasedAt),
            'bookable' => $slot->releasedAt->lessThanOrEqualTo($now) && $slot->startsAt->greaterThan($now) && $slot->available() > 0,
        ];
    }

    private function iso(CarbonImmutable $time): string
    {
        return $time->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
