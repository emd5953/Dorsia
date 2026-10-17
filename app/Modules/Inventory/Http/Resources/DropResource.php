<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\Drop;
use App\Modules\Inventory\Models\Slot;
use App\Modules\Inventory\Support\SlotRows;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Drop $resource
 */
final class DropResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $drop = $this->resource;

        return [
            'id' => $drop->id,
            'venue_id' => $drop->venue_id,
            'opens_at' => SlotRows::iso($drop->opens_at),
            'opened' => $drop->opened_at !== null,
            'sold_out' => $drop->sold_out_at !== null,
            'slot_ids' => array_map(intval(...), Slot::query()->where('drop_id', $drop->id)->orderBy('id')->pluck('id')->all()),
        ];
    }
}
