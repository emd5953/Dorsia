<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Actions;

use App\Modules\Wallet\Data\BookingPaymentInput;
use App\Modules\Wallet\Data\LedgerTransactionData;
use App\Modules\Wallet\Enums\AccountKind;
use App\Modules\Wallet\Enums\LedgerTransactionType;
use App\Modules\Wallet\Exceptions\InsufficientWalletBalanceException;
use App\Modules\Wallet\Support\PostingLine;
use App\Modules\Wallet\Support\PostTransaction;
use App\Shared\Outbox\Outbox;
use App\Shared\Support\Transactions;

class PostBookingPayment
{
    public function __construct(private readonly Outbox $outbox, private readonly PostTransaction $postTransaction) {}

    /**
     * Joins the caller's transaction. Replays on `hold:{holdId}:booking`.
     *
     * @throws InsufficientWalletBalanceException
     */
    public function handle(BookingPaymentInput $input): LedgerTransactionData
    {
        Transactions::assertActive();

        $plan = $input->plan;
        $total = $plan->total();

        // Debits first, then the single credit to the venue. Zero lines are omitted.
        $lines = [];

        if ($plan->coupon->isPositive()) {
            $lines[] = PostingLine::member($input->memberId, AccountKind::Coupon, $plan->coupon->cents);
        }

        if ($plan->credit->isPositive()) {
            $lines[] = PostingLine::member($input->memberId, AccountKind::Credit, $plan->credit->cents);
        }

        if ($plan->card->isPositive()) {
            $lines[] = PostingLine::cardClearing($plan->card->cents);
        }

        $lines[] = PostingLine::venuePayable($input->venueId, -$total->cents);

        $posted = $this->postTransaction->post(
            LedgerTransactionType::Booking,
            'hold',
            $input->holdId,
            "hold:{$input->holdId}:booking",
            $total->currency,
            $lines,
            ['member_id' => $input->memberId, 'venue_id' => $input->venueId, 'reservation_id' => $input->reservationId],
        );

        $posted->announce($this->outbox);

        return $posted->transaction;
    }
}
