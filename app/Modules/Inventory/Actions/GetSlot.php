<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Data\SlotData;
use App\Modules\Inventory\Exceptions\SlotNotFoundException;
use App\Modules\Inventory\Support\SlotRows;

class GetSlot
{
    /**
     * @throws SlotNotFoundException
     */
    public function handle(int $slotId): SlotData
    {
        $row = SlotRows::find($slotId) ?? throw new SlotNotFoundException;

        return SlotRows::toData($row);
    }
}
