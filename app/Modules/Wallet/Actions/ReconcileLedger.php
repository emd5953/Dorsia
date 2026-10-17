<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Actions;

use App\Modules\Wallet\Data\ReconciliationReport;
use App\Modules\Wallet\Enums\AccountKind;
use App\Modules\Wallet\Support\DbTime;
use Illuminate\Support\Facades\DB;

class ReconcileLedger
{
    /**
     * Re-sums entries per account and compares with the cached balance. With `$rebuild`, rewrites the cache
     * from the entries inside its own transaction; the report still lists the drift that was found.
     */
    public function handle(bool $rebuild = false): ReconciliationReport
    {
        if ($rebuild) {
            return DB::transaction(fn (): ReconciliationReport => $this->reconcile(true));
        }

        return $this->reconcile(false);
    }

    private function reconcile(bool $rebuild): ReconciliationReport
    {
        if ($rebuild) {
            // New postings need these locks, so the sums below cannot move under the rewrite.
            DB::table('ledger_accounts')->orderBy('id')->lockForUpdate()->pluck('id');
        }

        $rows = DB::select(<<<'SQL'
            SELECT a.id, a.kind, a.balance_cents, COALESCE(SUM(e.amount_cents), 0) AS entry_sum
            FROM ledger_accounts a
            LEFT JOIN ledger_entries e ON e.account_id = a.id
            GROUP BY a.id
            ORDER BY a.id
            SQL);

        $drift = [];

        foreach ($rows as $row) {
            $sum = (int) $row->entry_sum;
            $actual = $row->kind === AccountKind::Cash->value ? $sum : -$sum;

            if ((int) $row->balance_cents !== $actual) {
                $drift[] = ['account_id' => (int) $row->id, 'cached' => (int) $row->balance_cents, 'actual' => $actual];
            }
        }

        if ($rebuild) {
            foreach ($drift as $account) {
                DB::table('ledger_accounts')
                    ->where('id', $account['account_id'])
                    ->update(['balance_cents' => $account['actual'], 'updated_at' => DbTime::format(now())]);
            }
        }

        return new ReconciliationReport(
            count($rows),
            (int) DB::table('ledger_entries')->sum('amount_cents'),
            $this->unbalancedTransactions(),
            $drift,
        );
    }

    /** Transactions violating what the triggers enforce: non-zero sum, fewer than 2 entries, mixed currency. */
    private function unbalancedTransactions(): int
    {
        $rows = DB::select(<<<'SQL'
            SELECT COUNT(*) AS total FROM (
                SELECT t.id
                FROM ledger_transactions t
                LEFT JOIN ledger_entries e ON e.transaction_id = t.id
                LEFT JOIN ledger_accounts a ON a.id = e.account_id
                GROUP BY t.id, t.currency
                HAVING COALESCE(SUM(e.amount_cents), 0) <> 0
                    OR COUNT(e.id) < 2
                    OR COUNT(*) FILTER (WHERE a.currency <> t.currency) > 0
            ) AS bad
            SQL);

        return (int) $rows[0]->total;
    }
}
