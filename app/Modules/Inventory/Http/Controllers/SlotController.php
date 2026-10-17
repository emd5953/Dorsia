<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Actions\GetSlot;
use App\Modules\Inventory\Http\Resources\SlotResource;

final class SlotController
{
    public function show(int $slot, GetSlot $getSlot): SlotResource
    {
        return new SlotResource($getSlot->handle($slot));
    }
}
