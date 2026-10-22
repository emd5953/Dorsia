<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Data;

use App\Modules\Reservations\Enums\ReservationStatus;
use App\Modules\Wallet\Data\SpendPlan;
use App\Shared\Money\Money;
use Carbon\CarbonImmutable;

final readonly class ReservationData
{
    public function __construct(
        public int $id,
        public int $memberId,
        public int $holdId,
        public int $slotId,
        public int $venueId,
        public string $venueTimezone,
        public CarbonImmutable $startsAt,
        public int $partySize,
        public Money $minSpend,
        public SpendPlan $payment,
        public ReservationStatus $status,
        public CarbonImmutable $confirmedAt,
        public ?CarbonImmutable $cancelledAt,
        public ?Money $cancellationCredit = null,
    ) {}
}
