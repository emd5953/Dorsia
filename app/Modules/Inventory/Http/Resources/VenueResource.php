<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\Venue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Venue $resource
 */
final class VenueResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $venue = $this->resource;

        return [
            'id' => $venue->id,
            'city_id' => $venue->city_id,
            'name' => $venue->name,
            'slug' => $venue->slug,
            'timezone' => $venue->timezone,
            'status' => $venue->status,
            'base_per_person_cents' => $venue->base_per_person_cents,
            'currency' => $venue->currency,
        ];
    }
}
