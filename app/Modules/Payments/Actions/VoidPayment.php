<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Enums\GatewayStatus;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\GatewayTimeoutException;
use App\Modules\Payments\Jobs\RefundPayment;
use App\Modules\Payments\Models\Payment;
use App\Shared\Support\Transactions;
use Illuminate\Support\Facades\DB;
use LogicException;

class VoidPayment
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /**
     * Voids an authorized payment that no reservation claimed. Safe to call repeatedly.
     *
     * @throws LogicException inside a transaction (the gateway call must follow the commit)
     */
    public function handle(int $paymentId): void
    {
        Transactions::assertNone();

        $claimed = DB::transaction(fn (): int => Payment::query()
            ->whereKey($paymentId)
            ->where('status', PaymentStatus::Authorized->value)
            ->whereNull('reservation_id')
            ->update(['status' => PaymentStatus::Refunded->value]));

        if ($claimed === 0) {
            return;
        }

        $payment = Payment::query()->findOrFail($paymentId);

        try {
            $result = $this->gateway->refund((string) $payment->provider_ref, $payment->amount(), "payment:{$paymentId}:void");
        } catch (GatewayTimeoutException) {
            RefundPayment::dispatch($paymentId);

            return;
        }

        if ($result->status !== GatewayStatus::Refunded) {
            RefundPayment::dispatch($paymentId);
        }
    }
}
