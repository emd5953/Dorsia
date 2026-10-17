<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Data\SlotData;

final readonly class AvailabilityResult
{
    /**
     * @param  list<SlotData>  $slots
     */
    public function __construct(
        public int $venueId,
        public string $timezone,
        public string $date,
        public int $hour,
        public int $partySize,
        public bool $hadAvailability,
        public array $slots,
    ) {}
}
