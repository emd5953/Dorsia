<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Support;

/** The demand_signals rows for one venue/weekday/hour, reduced to what C§10 step 4 needs. */
final readonly class DemandSnapshot
{
    public function __construct(
        public string $computedAt,
        public int $bandUnmet,
        public int $venueHourUnmet,
        public ?int $selloutSecondsP50,
    ) {}
}
