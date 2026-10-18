<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Actions;

use App\Modules\Inventory\Actions\GetHold;
use App\Modules\Inventory\Exceptions\HoldNotFoundException;
use App\Modules\Pricing\Actions\GetQuoteForHold;
use App\Modules\Reservations\Data\HoldView;

class GetHoldView
{
    public function __construct(private readonly GetHold $getHold, private readonly GetQuoteForHold $getQuote) {}

    /**
     * Lazy expiry through GetHold, plus the quote locked to the hold.
     *
     * @throws HoldNotFoundException
     */
    public function handle(int $memberId, int $holdId): HoldView
    {
        $hold = $this->getHold->handle($holdId, $memberId);

        return new HoldView($hold, $this->getQuote->handle($hold->id));
    }
}
