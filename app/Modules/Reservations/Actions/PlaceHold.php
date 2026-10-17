<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Actions;

use App\Modules\Inventory\Actions\HoldSlot;
use App\Modules\Inventory\Data\HoldSlotInput;
use App\Modules\Reservations\Data\HoldView;
use App\Shared\Idempotency\IdempotencyKey;

class PlaceHold
{
    public function __construct(private readonly HoldSlot $holdSlot) {}

    /**
     * Quote-less version: no `$inTransaction` callback until ticket 06 adds Pricing.
     */
    public function handle(int $memberId, int $slotId, int $partySize, IdempotencyKey $key): HoldView
    {
        $hold = $this->holdSlot->handle(new HoldSlotInput($memberId, $slotId, $partySize, $key->scoped()));

        return new HoldView($hold, null);
    }
}
