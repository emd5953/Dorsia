<?php

declare(strict_types=1);

namespace App\Modules\Payments\Console;

use App\Modules\Payments\Actions\VoidPayment;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Shared\Support\Timestamps;
use Illuminate\Console\Command;

final class ReapPayments extends Command
{
    protected $signature = 'payments:reap';

    protected $description = 'Expire abandoned pending payments and void orphaned authorizations.';

    public function handle(VoidPayment $voidPayment): int
    {
        $pendingBefore = now()->subSeconds((int) config('payments.reap.pending_after_seconds', 120))->utc()->format(Timestamps::FORMAT);
        Payment::query()
            ->where('status', PaymentStatus::Pending->value)
            ->where('created_at', '<', $pendingBefore)
            ->update(['status' => PaymentStatus::TimedOut->value]);

        $orphanBefore = now()->subSeconds(
            (int) config('inventory.hold_ttl_seconds', 300) + (int) config('payments.reap.orphan_grace_seconds', 120),
        )->utc()->format(Timestamps::FORMAT);

        Payment::query()
            ->where('status', PaymentStatus::Authorized->value)
            ->whereNull('reservation_id')
            ->where('created_at', '<', $orphanBefore)
            ->orderBy('id')
            ->pluck('id')
            ->each(fn (int $id) => $voidPayment->handle($id));

        return self::SUCCESS;
    }
}
