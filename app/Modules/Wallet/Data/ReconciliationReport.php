<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Data;

final readonly class ReconciliationReport
{
    /**
     * @param  list<array{account_id: int, cached: int, actual: int}>  $drift
     */
    public function __construct(
        public int $accountsChecked,
        public int $globalSumCents,
        public int $unbalancedTransactions,
        public array $drift,
    ) {}

    public function clean(): bool
    {
        return $this->globalSumCents === 0 && $this->unbalancedTransactions === 0 && $this->drift === [];
    }
}
