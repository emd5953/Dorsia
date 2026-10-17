<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Data;

use App\Modules\Inventory\Data\HoldData;

final readonly class HoldView
{
    /**
     * The quote is always null until ticket 06 wires Pricing in and widens the type to `?QuoteData`.
     */
    public function __construct(public HoldData $hold, public null $quote = null) {}
}
