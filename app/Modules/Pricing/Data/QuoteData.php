<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Data;

use App\Shared\Money\Money;
use Carbon\CarbonImmutable;

final readonly class QuoteData
{
    /**
     * @param  array<string, mixed>  $inputs
     */
    public function __construct(
        public int $id,
        public int $holdId,
        public int $slotId,
        public int $partySize,
        public Money $perPerson,
        public Money $amount,
        public array $inputs,
        public CarbonImmutable $expiresAt,
    ) {}
}
