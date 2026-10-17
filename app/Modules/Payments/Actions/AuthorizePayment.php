<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Data\AuthorizePaymentInput;
use App\Modules\Payments\Data\AuthorizeRequest;
use App\Modules\Payments\Data\PaymentData;
use App\Modules\Payments\Enums\GatewayStatus;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\GatewayTimeoutException;
use App\Modules\Payments\Exceptions\PaymentConflictException;
use App\Modules\Payments\Exceptions\PaymentDeclinedException;
use App\Modules\Payments\Exceptions\PaymentInProgressException;
use App\Modules\Payments\Exceptions\PaymentTimeoutException;
use App\Modules\Payments\Models\Payment;
use App\Shared\Support\Transactions;
use InvalidArgumentException;
use LogicException;

class AuthorizePayment
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /**
     * @throws LogicException inside a transaction (no network call may happen in one)
     * @throws InvalidArgumentException for a non-positive amount
     * @throws PaymentDeclinedException
     * @throws PaymentTimeoutException
     * @throws PaymentInProgressException
     * @throws PaymentConflictException
     */
    public function handle(AuthorizePaymentInput $input): PaymentData
    {
        Transactions::assertNone();

        if (! $input->amount->isPositive()) {
            throw new InvalidArgumentException('A payment amount must be positive.');
        }

        $key = "hold:{$input->holdId}:auth";
        $now = now()->utc()->format('Y-m-d H:i:s.uP');

        $inserted = Payment::query()->insertOrIgnore([
            'member_id' => $input->memberId,
            'hold_id' => $input->holdId,
            'provider' => config('payments.gateway'),
            'idempotency_key' => $key,
            'amount_cents' => $input->amount->cents,
            'currency' => $input->amount->currency->value,
            'status' => PaymentStatus::Pending->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $payment = Payment::query()->where('idempotency_key', $key)->firstOrFail();

        if ($inserted === 0) {
            return $this->replay($payment, $input);
        }

        return $this->callGateway($payment, $input);
    }

    private function replay(Payment $payment, AuthorizePaymentInput $input): PaymentData
    {
        if ($payment->member_id !== $input->memberId
            || ! $payment->amount()->equals($input->amount)) {
            throw new PaymentConflictException('A payment for this hold exists with a different amount.');
        }

        return match ($payment->status) {
            PaymentStatus::Authorized, PaymentStatus::Captured => $payment->toData(),
            PaymentStatus::Pending => throw new PaymentInProgressException,
            PaymentStatus::Failed => throw new PaymentDeclinedException($payment->decline_code ?? 'card_declined'),
            PaymentStatus::TimedOut => throw new PaymentTimeoutException,
            PaymentStatus::Refunded, PaymentStatus::CaptureFailed => throw new PaymentConflictException('The payment for this hold has already been settled.'),
        };
    }

    private function callGateway(Payment $payment, AuthorizePaymentInput $input): PaymentData
    {
        try {
            $result = $this->gateway->authorize(new AuthorizeRequest(
                $input->amount,
                $input->cardToken,
                $payment->idempotency_key,
                ['payment_id' => $payment->id],
            ));
        } catch (GatewayTimeoutException) {
            if ($this->moveFromPending($payment->id, ['status' => PaymentStatus::TimedOut->value]) === 0) {
                // A webhook already confirmed the charge; the caller is told "timeout", so release the money.
                (new VoidPayment($this->gateway))->handle($payment->id);
            }

            throw new PaymentTimeoutException;
        }

        if ($result->status !== GatewayStatus::Authorized || $result->providerRef === null) {
            $code = $result->declineCode ?? 'card_declined';
            $this->moveFromPending($payment->id, ['status' => PaymentStatus::Failed->value, 'decline_code' => $code]);

            throw new PaymentDeclinedException($code);
        }

        $this->moveFromPending($payment->id, [
            'status' => PaymentStatus::Authorized->value,
            'provider_ref' => $result->providerRef,
        ]);

        // A webhook may have advanced the row first; either way it must now hold a live authorization.
        $current = Payment::query()->findOrFail($payment->id);

        if (! in_array($current->status, [PaymentStatus::Authorized, PaymentStatus::Captured], true)) {
            throw new PaymentConflictException('The payment changed state while it was being authorized.');
        }

        return $current->toData();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function moveFromPending(int $paymentId, array $values): int
    {
        return Payment::query()
            ->whereKey($paymentId)
            ->where('status', PaymentStatus::Pending->value)
            ->update($values);
    }
}
