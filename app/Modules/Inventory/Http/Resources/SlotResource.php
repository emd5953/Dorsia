<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Data\SlotData;
use App\Modules\Inventory\Support\SlotRows;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property SlotData $resource
 */
final class SlotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $slot = $this->resource;
        $now = now();

        return [
            'id' => $slot->id,
            'venue_id' => $slot->venueId,
            'drop_id' => $slot->dropId,
            'starts_at' => SlotRows::iso($slot->startsAt),
            'local_starts_at' => $slot->startsAt->setTimezone($slot->venueTimezone)->format('Y-m-d\TH:i:sP'),
            'capacity' => $slot->capacity,
            'available' => $slot->available(),
            'min_party_size' => $slot->minPartySize,
            'max_party_size' => $slot->maxPartySize,
            'released_at' => SlotRows::iso($slot->releasedAt),
            'bookable' => $slot->releasedAt->lessThanOrEqualTo($now) && $slot->startsAt->greaterThan($now) && $slot->available() > 0,
        ];
    }
}
