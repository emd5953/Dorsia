<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Data;

use App\Modules\Wallet\Enums\AccountKind;
use App\Modules\Wallet\Enums\AccountOwnerType;

final readonly class LedgerEntryData
{
    public function __construct(
        public int $accountId,
        public AccountOwnerType $ownerType,
        public int $ownerId,
        public AccountKind $kind,
        public int $amountCents,
    ) {}
}
