<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Data;

use App\Modules\Inventory\Data\HoldData;
use App\Modules\Pricing\Data\QuoteData;

final readonly class HoldView
{
    public function __construct(public HoldData $hold, public ?QuoteData $quote = null) {}
}
