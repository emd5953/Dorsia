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

/** Captures the authorization of a payment that a confirmed reservation claimed (D7). */
final class CapturePayment implements ShouldQueue
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
     * @throws RuntimeException when the gateway reports a failed capture, so the queue retries
     */
    public function handle(PaymentGateway $gateway): void
    {
        Transactions::assertNone();

        $payment = Payment::query()->find($this->paymentId);

        if ($payment === null || $payment->status !== PaymentStatus::Authorized || $payment->reservation_id === null) {
            return;
        }

        $result = $gateway->capture((string) $payment->provider_ref, $payment->amount(), "payment:{$payment->id}:capture");

        if ($result->status !== GatewayStatus::Captured) {
            throw new RuntimeException("Capture of payment {$payment->id} failed: ".($result->declineCode ?? 'unknown'));
        }

        Payment::query()
            ->whereKey($payment->id)
            ->where('status', PaymentStatus::Authorized->value)
            ->update(['status' => PaymentStatus::Captured->value]);
    }

    /** Final failure after every retry (D9: staff resolve it; the ledger already posted). */
    public function failed(Throwable $e): void
    {
        $marked = Payment::query()
            ->whereKey($this->paymentId)
            ->where('status', PaymentStatus::Authorized->value)
            ->update(['status' => PaymentStatus::CaptureFailed->value]);

        if ($marked > 0) {
            Log::critical('Payment capture failed after all retries.', [
                'payment_id' => $this->paymentId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
