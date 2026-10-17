<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Data;

use App\Shared\Money\Money;
use Carbon\CarbonImmutable;

final readonly class SlotData
{
    public function __construct(
        public int $id,
        public int $venueId,
        public ?int $dropId,
        public string $venueTimezone,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $releasedAt,
        public int $capacity,
        public int $heldCount,
        public int $bookedCount,
        public int $minPartySize,
        public int $maxPartySize,
        public Money $basePerPerson,
        public ?CarbonImmutable $soldOutAt,
    ) {}

    public function available(): int
    {
        return $this->capacity - $this->heldCount - $this->bookedCount;
    }
}
