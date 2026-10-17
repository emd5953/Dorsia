<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\PaymentConflictException;
use App\Modules\Payments\Jobs\CapturePayment;
use App\Modules\Payments\Models\Payment;
use App\Shared\Support\Transactions;
use LogicException;

class MarkPaymentForCapture
{
    /**
     * @throws LogicException outside a transaction
     * @throws PaymentConflictException already attached, voided or not authorized
     */
    public function handle(int $paymentId, int $reservationId): void
    {
        Transactions::assertActive();

        $attached = Payment::query()
            ->whereKey($paymentId)
            ->whereNull('reservation_id')
            ->where('status', PaymentStatus::Authorized->value)
            ->update(['reservation_id' => $reservationId]);

        if ($attached === 0) {
            throw new PaymentConflictException('The payment cannot be attached to a reservation.');
        }

        CapturePayment::dispatch($paymentId)->afterCommit();
    }
}
