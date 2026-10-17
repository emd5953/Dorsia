<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Console;

use App\Modules\Wallet\Actions\ReconcileLedger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class ReconcileLedgerCommand extends Command
{
    protected $signature = 'wallet:reconcile {--rebuild : Rewrite cached balances from the entries}';

    protected $description = 'Re-sum the ledger and compare it with the cached balances';

    public function handle(ReconcileLedger $reconcile): int
    {
        $report = $reconcile->handle((bool) $this->option('rebuild'));

        $this->table(['Check', 'Result'], [
            ['Accounts checked', $report->accountsChecked],
            ['Global entry sum (must be 0)', $report->globalSumCents],
            ['Unbalanced transactions', $report->unbalancedTransactions],
            ['Accounts with drift', count($report->drift)],
        ]);

        if ($report->drift !== []) {
            $this->table(['Account', 'Cached', 'Actual'], array_map(
                fn (array $row): array => [$row['account_id'], $row['cached'], $row['actual']],
                $report->drift,
            ));
        }

        if ($report->clean()) {
            $this->info('Ledger is clean.');

            return self::SUCCESS;
        }

        if ($this->option('rebuild') && $report->drift !== []) {
            $this->warn('Cached balances of the accounts above were rebuilt from entries.');
        }

        $accounts = implode(', ', array_map(fn (array $row): string => (string) $row['account_id'], $report->drift));

        Log::critical('Ledger reconciliation found problems.', [
            'global_sum_cents' => $report->globalSumCents,
            'unbalanced_transactions' => $report->unbalancedTransactions,
            'drifted_accounts' => $accounts,
            'drift' => $report->drift,
            'rebuilt' => (bool) $this->option('rebuild'),
        ]);

        $this->error('Ledger reconciliation failed.'.($accounts === '' ? '' : " Drifted accounts: {$accounts}."));

        return self::FAILURE;
    }
}
