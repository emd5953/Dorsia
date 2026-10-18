<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Actions;

use App\Modules\Wallet\Data\LedgerTransactionData;
use App\Modules\Wallet\Enums\AccountKind;
use App\Modules\Wallet\Enums\LedgerTransactionType;
use App\Modules\Wallet\Support\PostingLine;
use App\Modules\Wallet\Support\PostTransaction;
use App\Shared\Money\Money;
use App\Shared\Outbox\Outbox;
use Illuminate\Support\Facades\DB;

class IssueGoodwillCredit
{
    public function __construct(private readonly Outbox $outbox, private readonly PostTransaction $postTransaction) {}

    /**
     * Opens its own transaction. Replays on the caller's idempotency key.
     */
    public function handle(int $memberId, Money $amount, string $idempotencyKey): LedgerTransactionData
    {
        return DB::transaction(function () use ($memberId, $amount, $idempotencyKey): LedgerTransactionData {
            $posted = $this->postTransaction->post(
                LedgerTransactionType::GoodwillCredit,
                'member',
                $memberId,
                $idempotencyKey,
                $amount->currency,
                [
                    PostingLine::platform($amount->cents),
                    PostingLine::member($memberId, AccountKind::Credit, -$amount->cents),
                ],
                ['member_id' => $memberId, 'amount_cents' => $amount->cents],
            );

            $posted->announce($this->outbox);

            return $posted->transaction;
        });
    }
}
