<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Data\HoldData;
use App\Modules\Inventory\Enums\HoldStatus;
use App\Modules\Inventory\Exceptions\HoldNotFoundException;
use App\Modules\Inventory\Support\HoldRows;
use App\Modules\Inventory\Support\SlotRows;

class GetHold
{
    public function __construct(private readonly ExpireHold $expireHold) {}

    /**
     * Lazily expires the hold when `now() >= expires_at`, then returns the fresh state.
     *
     * @throws HoldNotFoundException missing or not owned by $memberId
     */
    public function handle(int $holdId, int $memberId): HoldData
    {
        $hold = HoldRows::find($holdId);

        if ($hold === null || (int) $hold->member_id !== $memberId) {
            throw new HoldNotFoundException;
        }

        if ($hold->status === HoldStatus::Active->value && ! now()->lessThan(SlotRows::time($hold->expires_at))) {
            $this->expireHold->handle($holdId);

            $hold = HoldRows::find($holdId) ?? throw new HoldNotFoundException;
        }

        $slot = SlotRows::find((int) $hold->slot_id) ?? throw new HoldNotFoundException;

        return HoldRows::toData($hold, SlotRows::toData($slot));
    }
}
