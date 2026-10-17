<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Actions;

use App\Modules\Inventory\Actions\ConvertHoldToBooking;
use App\Modules\Inventory\Actions\ExpireHold;
use App\Modules\Inventory\Actions\GetHold;
use App\Modules\Inventory\Actions\ReleaseHold;
use App\Modules\Inventory\Enums\HoldReleaseReason;
use App\Modules\Inventory\Enums\HoldStatus;
use App\Modules\Inventory\Exceptions\HoldExpiredException;
use App\Modules\Inventory\Exceptions\HoldNotActiveException;
use App\Modules\Inventory\Exceptions\HoldNotFoundException;
use App\Modules\Payments\Actions\AuthorizePayment;
use App\Modules\Payments\Actions\MarkPaymentForCapture;
use App\Modules\Payments\Actions\VoidPayment;
use App\Modules\Payments\Data\AuthorizePaymentInput;
use App\Modules\Payments\Data\PaymentData;
use App\Modules\Payments\Exceptions\PaymentConflictException;
use App\Modules\Payments\Exceptions\PaymentDeclinedException;
use App\Modules\Payments\Exceptions\PaymentInProgressException;
use App\Modules\Payments\Exceptions\PaymentTimeoutException;
use App\Modules\Pricing\Actions\GetQuoteForHold;
use App\Modules\Pricing\Data\QuoteData;
use App\Modules\Reservations\Data\ReservationData;
use App\Modules\Reservations\Enums\ReservationStatus;
use App\Modules\Reservations\Exceptions\CardRequiredException;
use App\Modules\Reservations\Models\Reservation;
use App\Modules\Wallet\Actions\PlanSpend;
use App\Modules\Wallet\Actions\PostBookingPayment;
use App\Modules\Wallet\Data\BookingPaymentInput;
use App\Modules\Wallet\Data\SpendPlan;
use App\Modules\Wallet\Exceptions\InsufficientWalletBalanceException;
use App\Shared\Idempotency\IdempotencyKey;
use App\Shared\Outbox\Outbox;
use App\Shared\Outbox\OutboxTopic;
use App\Shared\Support\Transactions;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

class ConfirmReservation
{
    public function __construct(
        private readonly GetHold $getHold,
        private readonly ExpireHold $expireHold,
        private readonly ReleaseHold $releaseHold,
        private readonly ConvertHoldToBooking $convertHold,
        private readonly GetQuoteForHold $getQuote,
        private readonly PlanSpend $planSpend,
        private readonly PostBookingPayment $postBookingPayment,
        private readonly AuthorizePayment $authorizePayment,
        private readonly MarkPaymentForCapture $markPaymentForCapture,
        private readonly VoidPayment $voidPayment,
        private readonly Outbox $outbox,
    ) {}

    /**
     * C§6.2. The card is authorized outside any transaction (D7); everything after that either commits
     * as one unit or voids the authorization.
     *
     * @throws HoldNotFoundException
     * @throws HoldExpiredException
     * @throws HoldNotActiveException
     * @throws CardRequiredException
     * @throws PaymentDeclinedException
     * @throws PaymentTimeoutException
     * @throws PaymentInProgressException
     * @throws PaymentConflictException
     * @throws InsufficientWalletBalanceException
     */
    public function handle(int $memberId, int $holdId, ?string $cardToken, IdempotencyKey $key): ReservationData
    {
        Transactions::assertNone();

        // 1. Replay.
        $replayed = $this->replay($key);

        if ($replayed !== null) {
            return $replayed;
        }

        // 2. Lazy expiry happens inside GetHold.
        $hold = $this->getHold->handle($holdId, $memberId);

        if ($hold->status === HoldStatus::Expired) {
            throw new HoldExpiredException;
        }

        if ($hold->status !== HoldStatus::Active) {
            throw new HoldNotActiveException($hold->status);
        }

        // 3. Price and spend plan.
        $quote = $this->getQuote->handle($holdId);
        $plan = $this->planSpend->handle($memberId, $quote->amount);

        // 4. Card portion, outside any transaction.
        $payment = $plan->card->isPositive() ? $this->authorize($memberId, $holdId, $plan, $cardToken) : null;

        // 5 and 6.
        try {
            return DB::transaction(fn (): ReservationData => $this->book($memberId, $holdId, $key, $quote, $plan, $payment));
        } catch (Throwable $failure) {
            return $this->recover($failure, $key, $holdId, $payment);
        }
    }

    private function replay(IdempotencyKey $key): ?ReservationData
    {
        return Reservation::query()->where('idempotency_key', $key->scoped())->first()?->toData();
    }

    /**
     * @throws CardRequiredException
     * @throws PaymentDeclinedException
     * @throws PaymentTimeoutException
     * @throws PaymentInProgressException
     * @throws PaymentConflictException
     */
    private function authorize(int $memberId, int $holdId, SpendPlan $plan, ?string $cardToken): PaymentData
    {
        if ($cardToken === null) {
            throw new CardRequiredException($plan->card->cents);
        }

        try {
            return $this->authorizePayment->handle(new AuthorizePaymentInput($memberId, $holdId, $plan->card, $cardToken));
        } catch (PaymentDeclinedException $declined) {
            $this->releaseHold->handle($holdId, HoldReleaseReason::PaymentFailed);

            throw $declined;
        } catch (PaymentTimeoutException $timeout) {
            $this->releaseHold->handle($holdId, HoldReleaseReason::PaymentTimeout);

            throw $timeout;
        }
    }

    private function book(int $memberId, int $holdId, IdempotencyKey $key, QuoteData $quote, SpendPlan $plan, ?PaymentData $payment): ReservationData
    {
        $slot = $this->convertHold->handle($holdId, $memberId);
        $now = now();

        $reservation = Reservation::query()->create([
            'member_id' => $memberId,
            'hold_id' => $holdId,
            'slot_id' => $slot->id,
            'venue_id' => $slot->venueId,
            'venue_timezone' => $slot->venueTimezone,
            'starts_at' => $slot->startsAt,
            'party_size' => $quote->partySize,
            'min_spend_cents' => $quote->amount->cents,
            'coupon_cents' => $plan->coupon->cents,
            'credit_cents' => $plan->credit->cents,
            'card_cents' => $plan->card->cents,
            'currency' => $quote->amount->currency->value,
            'payment_id' => $payment?->id,
            'status' => ReservationStatus::Confirmed->value,
            'idempotency_key' => $key->scoped(),
            'confirmed_at' => $now,
        ]);

        $this->postBookingPayment->handle(new BookingPaymentInput($memberId, $slot->venueId, $holdId, $reservation->id, $plan));

        if ($payment !== null) {
            $this->markPaymentForCapture->handle($payment->id, $reservation->id);
        }

        $this->outbox->record(OutboxTopic::ReservationConfirmed, [
            'reservation_id' => $reservation->id,
            'member_id' => $memberId,
            'slot_id' => $slot->id,
            'venue_id' => $slot->venueId,
            'starts_at' => $slot->startsAt->utc()->format('Y-m-d\TH:i:s\Z'),
            'party_size' => $quote->partySize,
            'min_spend_cents' => $quote->amount->cents,
            'currency' => $quote->amount->currency->value,
        ]);

        return $reservation->toData();
    }

    /**
     * C§6.2 step 6: undo what the failed transaction left outside it, then rethrow.
     *
     * @throws Throwable
     */
    private function recover(Throwable $failure, IdempotencyKey $key, int $holdId, ?PaymentData $payment): ReservationData
    {
        // The transaction committed and only an after-commit callback failed: the booking stands.
        $committed = $this->replay($key);

        if ($committed !== null) {
            return $committed;
        }

        if ($failure instanceof HoldExpiredException) {
            $this->voidIfAuthorized($payment);
            $this->attempt(fn () => $this->expireHold->handle($holdId));
        } elseif ($failure instanceof HoldNotActiveException) {
            // A concurrent confirm won; VoidPayment leaves the winner's attached payment alone.
            $this->voidIfAuthorized($payment);
        } elseif ($payment !== null) {
            // The per-hold payment key is spent, so the hold goes with it (D8).
            $this->voidIfAuthorized($payment);
            $this->attempt(fn () => $this->releaseHold->handle($holdId, HoldReleaseReason::ConfirmFailed));
        }

        // Wallet-only plan: nothing was spent, the hold stays and a retry re-plans.
        throw $failure;
    }

    private function voidIfAuthorized(?PaymentData $payment): void
    {
        if ($payment !== null) {
            $this->attempt(fn () => $this->voidPayment->handle($payment->id));
        }
    }

    /**
     * Cleanup must never hide the failure that caused it, nor stop the cleanup steps after it.
     *
     * @param  Closure(): mixed  $step
     */
    private function attempt(Closure $step): void
    {
        try {
            $step();
        } catch (Throwable $error) {
            report($error);
        }
    }
}
