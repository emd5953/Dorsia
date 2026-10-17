<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Actions;

use App\Modules\Inventory\Actions\GetHold;
use App\Modules\Inventory\Exceptions\HoldNotFoundException;
use App\Modules\Reservations\Data\HoldView;

class GetHoldView
{
    public function __construct(private readonly GetHold $getHold) {}

    /**
     * Quote-less version: lazy expiry through GetHold, quote always null until ticket 06.
     *
     * @throws HoldNotFoundException
     */
    public function handle(int $memberId, int $holdId): HoldView
    {
        return new HoldView($this->getHold->handle($holdId, $memberId), null);
    }
}
