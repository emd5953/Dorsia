<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Actions;

use App\Modules\Wallet\Data\WalletTransaction;
use App\Modules\Wallet\Data\WalletTransactionPage;
use App\Modules\Wallet\Enums\AccountKind;
use App\Modules\Wallet\Enums\AccountOwnerType;
use App\Modules\Wallet\Enums\LedgerTransactionType;
use App\Modules\Wallet\Models\LedgerTransaction;
use App\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ListWalletTransactions
{
    public function handle(int $memberId, ?int $beforeId = null): WalletTransactionPage
    {
        $query = LedgerTransaction::query()->where(function ($query) use ($memberId): void {
            $query->where('metadata->member_id', $memberId)->orWhere(function ($query) use ($memberId): void {
                $query->where('type', LedgerTransactionType::GoodwillCredit->value)
                    ->where('reference_type', 'member')->where('reference_id', $memberId);
            });
        })->orderByDesc('id');
        if ($beforeId !== null) {
            $query->where('id', '<', $beforeId);
        }
        $rows = $query->limit(21)->get();
        $hasMore = $rows->count() > 20;
        $rows = $rows->take(20);
        if ($rows->isEmpty()) {
            return new WalletTransactionPage([], null);
        }
        $entries = DB::table('ledger_entries as e')
            ->join('ledger_accounts as a', 'a.id', '=', 'e.account_id')
            ->whereIn('e.transaction_id', $rows->modelKeys())
            ->get(['e.transaction_id', 'e.amount_cents', 'a.owner_type', 'a.owner_id', 'a.kind'])
            ->groupBy('transaction_id');

        $transactions = [];
        foreach ($rows as $row) {
            $credit = 0;
            $coupon = 0;
            $card = 0;
            foreach ($entries->get($row->id, collect()) as $entry) {
                $amount = (int) $entry->amount_cents;
                if ($entry->owner_type === AccountOwnerType::Member->value && (int) $entry->owner_id === $memberId) {
                    if ($entry->kind === AccountKind::Credit->value) {
                        $credit += $amount;
                    } elseif ($entry->kind === AccountKind::Coupon->value) {
                        $coupon += $amount;
                    }
                } elseif ($entry->owner_type === AccountOwnerType::System->value && $entry->kind === AccountKind::Cash->value) {
                    $card += $amount;
                }
            }
            $booking = $row->type === LedgerTransactionType::Booking;
            $refund = in_array($row->type, [LedgerTransactionType::CancelEarly, LedgerTransactionType::CancelLate], true);
            $amount = $booking ? $credit + $coupon + $card : -($credit + $coupon);
            $metadata = $row->metadata;
            $reservationId = $booking ? ($metadata['reservation_id'] ?? null) : ($refund ? $row->reference_id : null);
            $transactions[] = new WalletTransaction(
                $row->id,
                $row->type,
                is_numeric($reservationId) ? (int) $reservationId : null,
                isset($metadata['venue_id']) ? (int) $metadata['venue_id'] : null,
                Money::of($amount, $row->currency),
                -($credit + $coupon),
                Money::of(abs($credit), $row->currency),
                Money::of(abs($coupon), $row->currency),
                Money::of($card, $row->currency),
                $refund ? Money::of((int) $metadata['amount_cents'] - $amount, $row->currency) : null,
                CarbonImmutable::parse($row->getRawOriginal('created_at'), 'UTC'),
            );
        }

        return new WalletTransactionPage($transactions, $hasMore ? $rows->last()?->id : null);
    }
}
