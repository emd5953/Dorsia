<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Support\AvailabilityResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property AvailabilityResult $resource
 */
final class AvailabilityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $result = $this->resource;

        return [
            'venue_id' => $result->venueId,
            'timezone' => $result->timezone,
            'date' => $result->date,
            'hour' => $result->hour,
            'party_size' => $result->partySize,
            'had_availability' => $result->hadAvailability,
            'slots' => SlotResource::collection($result->slots)->resolve($request),
        ];
    }
}
