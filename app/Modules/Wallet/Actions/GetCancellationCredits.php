<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Actions;

use App\Modules\Wallet\Enums\AccountKind;
use App\Modules\Wallet\Enums\AccountOwnerType;
use App\Modules\Wallet\Enums\LedgerTransactionType;
use App\Shared\Money\Money;
use Illuminate\Support\Facades\DB;

class GetCancellationCredits
{
    /**
     * Posted member credits only; omitted IDs had no credit movement. Never uses current policy.
     *
     * @param  list<int>  $reservationIds
     * @return array<int, Money>
     */
    public function handle(int $memberId, array $reservationIds): array
    {
        if ($reservationIds === []) {
            return [];
        }

        $rows = DB::table('ledger_transactions as t')
            ->join('ledger_entries as e', 'e.transaction_id', '=', 't.id')
            ->join('ledger_accounts as a', 'a.id', '=', 'e.account_id')
            ->where('t.reference_type', 'reservation')
            ->whereIn('t.reference_id', $reservationIds)
            ->whereIn('t.type', [LedgerTransactionType::CancelEarly->value, LedgerTransactionType::CancelLate->value])
            ->where('t.metadata->member_id', $memberId)
            ->where('a.owner_type', AccountOwnerType::Member->value)
            ->where('a.owner_id', $memberId)
            ->where('a.kind', AccountKind::Credit->value)
            ->groupBy('t.reference_id', 't.currency')
            ->selectRaw('t.reference_id, t.currency, -SUM(e.amount_cents) as credit_cents')
            ->get();

        $credits = [];
        foreach ($rows as $row) {
            $credits[(int) $row->reference_id] = Money::of((int) $row->credit_cents, (string) $row->currency);
        }

        return $credits;
    }
}
