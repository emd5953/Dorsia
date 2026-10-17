<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Support;

use App\Modules\Wallet\Data\LedgerTransactionData;
use App\Shared\Outbox\Outbox;
use App\Shared\Outbox\OutboxTopic;

/**
 * Result of PostTransaction::post(): the transaction, whether it was an idempotent replay,
 * its stored metadata, and the post-transaction balances of every member whose accounts it touched.
 */
final readonly class PostedTransaction
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  list<array{member_id: int, credit_cents: int, coupon_cents: int}>  $memberBalances
     */
    public function __construct(
        public LedgerTransactionData $transaction,
        public bool $replayed,
        public array $metadata,
        public array $memberBalances,
    ) {}

    /** Writes one `wallet.updated` row per touched member (C§7); a replay writes nothing. */
    public function announce(Outbox $outbox): void
    {
        if ($this->replayed) {
            return;
        }

        foreach ($this->memberBalances as $balance) {
            $outbox->record(OutboxTopic::WalletUpdated, [
                'member_id' => $balance['member_id'],
                'currency' => $this->transaction->currency->value,
                'credit_cents' => $balance['credit_cents'],
                'coupon_cents' => $balance['coupon_cents'],
            ]);
        }
    }
}
