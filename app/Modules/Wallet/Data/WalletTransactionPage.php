<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Data;

final readonly class WalletTransactionPage
{
    /** @param list<WalletTransaction> $transactions */
    public function __construct(public array $transactions, public ?int $nextBeforeId) {}
}
