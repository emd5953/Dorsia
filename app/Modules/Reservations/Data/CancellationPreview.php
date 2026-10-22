<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Data;

use App\Shared\Money\Money;
use Carbon\CarbonImmutable;

final readonly class CancellationPreview
{
    public function __construct(
        public int $reservationId,
        public bool $late,
        public Money $creditRefunded,
        public Money $forfeited,
        public CarbonImmutable $evaluatedAt,
        public CarbonImmutable $earlyDeadline,
        public CarbonImmutable $startsAt,
        public string $venueTimezone,
        public int $cancelWindowHours,
    ) {}
}
