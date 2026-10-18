<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Data;

use App\Shared\Money\Money;

final readonly class CancellationData
{
    public function __construct(public ReservationData $reservation, public bool $late, public Money $creditRefunded, public Money $forfeited) {}
}
