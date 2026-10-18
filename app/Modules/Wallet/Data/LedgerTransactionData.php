<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Data;

use App\Modules\Wallet\Enums\LedgerTransactionType;
use App\Shared\Money\Currency;
use Carbon\CarbonImmutable;

final readonly class LedgerTransactionData
{
    /**
     * @param  list<LedgerEntryData>  $entries
     */
    public function __construct(
        public int $id,
        public LedgerTransactionType $type,
        public string $idempotencyKey,
        public Currency $currency,
        public array $entries,
        public CarbonImmutable $createdAt,
    ) {}
}
