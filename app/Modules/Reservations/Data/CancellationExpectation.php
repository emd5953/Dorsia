<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Data;

final readonly class CancellationExpectation
{
    public function __construct(
        public int $creditCents,
        public int $forfeitedCents,
        public string $currency,
        public bool $late,
    ) {}
}
