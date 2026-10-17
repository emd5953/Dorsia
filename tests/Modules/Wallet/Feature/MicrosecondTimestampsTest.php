<?php

declare(strict_types=1);

use App\Modules\Wallet\Actions\IssueGoodwillCredit;
use App\Modules\Wallet\Models\LedgerAccount;
use App\Modules\Wallet\Models\LedgerEntry;
use App\Modules\Wallet\Models\LedgerTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../WalletHelpers.php';

it('stores every ledger timestamp column with microsecond precision', function (): void {
    $columns = DB::table('information_schema.columns')
        ->whereIn('table_name', ['ledger_accounts', 'ledger_transactions', 'ledger_entries'])
        ->whereIn('column_name', ['created_at', 'updated_at'])
        ->get(['table_name', 'column_name', 'datetime_precision']);

    expect($columns)->toHaveCount(4);

    foreach ($columns as $column) {
        expect($column->datetime_precision)->toBe(6, "{$column->table_name}.{$column->column_name}");
    }
});

it('keeps the exact microseconds of the posting time on the transaction, its entries and the accounts', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-01 10:00:00.123456', 'UTC'));
    $member = newMemberId();

    $tx = app(IssueGoodwillCredit::class)->handle($member, usd(5000), 'micro:1');

    expect($tx->createdAt->format('Y-m-d H:i:s.u'))->toBe('2026-11-01 10:00:00.123456');

    $stored = LedgerTransaction::query()->findOrFail($tx->id);
    expect($stored->created_at->format('Y-m-d H:i:s.u'))->toBe('2026-11-01 10:00:00.123456');

    foreach (LedgerEntry::query()->where('transaction_id', $tx->id)->get() as $entry) {
        expect($entry->created_at->format('Y-m-d H:i:s.u'))->toBe('2026-11-01 10:00:00.123456');
    }

    foreach (LedgerAccount::query()->get() as $account) {
        expect($account->created_at->format('Y-m-d H:i:s.u'))->toBe('2026-11-01 10:00:00.123456')
            ->and($account->updated_at->format('Y-m-d H:i:s.u'))->toBe('2026-11-01 10:00:00.123456');
    }
});

it('orders two postings made inside the same second by their timestamps', function (): void {
    $member = newMemberId();
    $issue = app(IssueGoodwillCredit::class);

    $this->travelTo(CarbonImmutable::parse('2026-11-01 10:00:00.100000', 'UTC'));
    $first = $issue->handle($member, usd(100), 'micro:a');
    $this->travelTo(CarbonImmutable::parse('2026-11-01 10:00:00.900000', 'UTC'));
    $second = $issue->handle($member, usd(200), 'micro:b');

    $ordered = LedgerTransaction::query()->orderBy('created_at')->pluck('id')->all();

    // Truncation to whole seconds would make both timestamps equal and the order undefined.
    expect(LedgerTransaction::query()->distinct()->count('created_at'))->toBe(2)
        ->and($ordered)->toBe([$first->id, $second->id]);
});
