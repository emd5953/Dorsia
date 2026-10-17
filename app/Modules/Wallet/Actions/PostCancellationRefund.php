<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Actions;

use App\Modules\Wallet\Data\CancellationRefund;
use App\Modules\Wallet\Data\CancellationRefundInput;
use App\Modules\Wallet\Data\LedgerTransactionData;
use App\Modules\Wallet\Enums\AccountKind;
use App\Modules\Wallet\Enums\LedgerTransactionType;
use App\Modules\Wallet\Support\LateCancelSplit;
use App\Modules\Wallet\Support\PostingLine;
use App\Modules\Wallet\Support\PostTransaction;
use App\Shared\Money\Money;
use App\Shared\Outbox\Outbox;
use App\Shared\Support\Transactions;

class PostCancellationRefund
{
    public function __construct(private readonly Outbox $outbox, private readonly PostTransaction $postTransaction) {}

    /**
     * Joins the caller's transaction. Replays on `reservation:{reservationId}:cancel` with the stored result.
     */
    public function handle(CancellationRefundInput $input): CancellationRefund
    {
        Transactions::assertActive();

        $amount = $input->amount;

        $split = $input->late
            ? LateCancelSplit::calculate(
                $amount,
                config()->integer('wallet.late_cancel.member_bps'),
                config()->integer('wallet.late_cancel.platform_bps'),
            )
            : new LateCancelSplit($amount, Money::zero($amount->currency), Money::zero($amount->currency));

        $lines = [PostingLine::venuePayable($input->venueId, $split->member->add($split->platform)->cents)];

        if ($split->member->isPositive()) {
            $lines[] = PostingLine::member($input->memberId, AccountKind::Credit, -$split->member->cents);
        }

        if ($split->platform->isPositive()) {
            $lines[] = PostingLine::platform(-$split->platform->cents);
        }

        $posted = $this->postTransaction->post(
            $input->late ? LedgerTransactionType::CancelLate : LedgerTransactionType::CancelEarly,
            'reservation',
            $input->reservationId,
            "reservation:{$input->reservationId}:cancel",
            $amount->currency,
            $lines,
            [
                'member_id' => $input->memberId,
                'venue_id' => $input->venueId,
                'amount_cents' => $amount->cents,
                'late' => $input->late,
            ],
        );

        $posted->announce($this->outbox);

        /** @var int $storedAmount */
        $storedAmount = $posted->metadata['amount_cents'];

        return $this->refundFrom($posted->transaction, Money::of($storedAmount, $amount->currency));
    }

    /**
     * Reads the result back from the stored entries so a replay returns exactly what was posted.
     */
    private function refundFrom(LedgerTransactionData $transaction, Money $amount): CancellationRefund
    {
        $currency = $transaction->currency;
        $memberCredit = 0;
        $platform = 0;

        foreach ($transaction->entries as $entry) {
            match ($entry->kind) {
                AccountKind::Credit => $memberCredit -= $entry->amountCents,
                AccountKind::Platform => $platform -= $entry->amountCents,
                default => null,
            };
        }

        return new CancellationRefund(
            $transaction->id,
            Money::of($memberCredit, $currency),
            Money::of($platform, $currency),
            $amount->subtract(Money::of($memberCredit, $currency))->subtract(Money::of($platform, $currency)),
        );
    }
}
