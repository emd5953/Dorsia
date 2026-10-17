<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Data;

use App\Shared\Money\Money;
use Carbon\CarbonImmutable;

final readonly class QuoteHoldInput
{
    public function __construct(
        public int $holdId,
        public int $slotId,
        public int $venueId,
        public string $venueTimezone,
        public CarbonImmutable $startsAt,
        public int $partySize,
        public Money $basePerPerson,
        public CarbonImmutable $expiresAt,
    ) {}
}
