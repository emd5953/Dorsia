<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Data;

final readonly class SelloutStat
{
    public function __construct(public int $venueId, public int $weekday, public int $hour, public int $p50Seconds) {}
}
