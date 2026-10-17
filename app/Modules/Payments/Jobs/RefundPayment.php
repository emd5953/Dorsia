<?php

declare(strict_types=1);

namespace App\Modules\Payments\Jobs;

use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Enums\GatewayStatus;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\GatewayTimeoutException;
use App\Modules\Payments\Models\Payment;
use App\Shared\Support\Transactions;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Voids the provider-side authorization of a payment. Used when VoidPayment's own call failed
 * (status already refunded) and for an orphaned authorization found by webhook (status timed_out).
 */
final class RefundPayment implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly int $paymentId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [1, 5, 15, 60, 300];
    }

    /**
     * @throws GatewayTimeoutException
     * @throws RuntimeException when the gateway reports a failed refund, so the queue retries
     */
    public function handle(PaymentGateway $gateway): void
    {
        Transactions::assertNone();

        $payment = Payment::query()->find($this->paymentId);

        if ($payment === null
            || $payment->provider_ref === null
            || ! in_array($payment->status, [PaymentStatus::Refunded, PaymentStatus::TimedOut], true)) {
            return;
        }

        $result = $gateway->refund($payment->provider_ref, $payment->amount(), "payment:{$payment->id}:void");

        if ($result->status !== GatewayStatus::Refunded) {
            throw new RuntimeException("Refund of payment {$payment->id} failed: ".($result->declineCode ?? 'unknown'));
        }

        Payment::query()
            ->whereKey($payment->id)
            ->where('status', PaymentStatus::TimedOut->value)
            ->update(['status' => PaymentStatus::Refunded->value, 'updated_at' => now()]);
    }

    public function failed(Throwable $e): void
    {
        Log::critical('Payment refund failed after all retries.', [
            'payment_id' => $this->paymentId,
            'error' => $e->getMessage(),
        ]);
    }
}
