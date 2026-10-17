<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Support;

use App\Modules\Wallet\Data\LedgerEntryData;
use App\Modules\Wallet\Data\LedgerTransactionData;
use App\Modules\Wallet\Enums\AccountKind;
use App\Modules\Wallet\Enums\AccountOwnerType;
use App\Modules\Wallet\Enums\LedgerTransactionType;
use App\Modules\Wallet\Exceptions\InsufficientWalletBalanceException;
use App\Shared\Money\Currency;
use App\Shared\Support\Timestamps;
use App\Shared\Support\Transactions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;

/**
 * The only code that inserts ledger rows.
 */
final class PostTransaction
{
    public function __construct(private readonly AccountResolver $accounts) {}

    /**
     * @param  list<PostingLine>  $lines
     * @param  array<string, mixed>  $metadata
     *
     * @throws InvalidArgumentException if the lines are fewer than two, contain a zero, or do not sum to zero
     * @throws InsufficientWalletBalanceException if a member account would go negative
     */
    public function post(
        LedgerTransactionType $type,
        string $referenceType,
        int $referenceId,
        string $idempotencyKey,
        Currency $currency,
        array $lines,
        array $metadata = [],
    ): PostedTransaction {
        Transactions::assertActive();
        $this->assertPostable($lines);

        $accountIds = $this->accounts->resolve($lines, $currency);

        // Lock in ascending id order, whatever order the lines came in, so concurrent postings cannot deadlock.
        $locked = DB::table('ledger_accounts')
            ->whereIn('id', array_values($accountIds))
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'owner_type', 'balance_cents']);

        // Checked under the locks: a concurrent poster of the same key has committed or never started.
        $existing = $this->find($idempotencyKey);

        if ($existing !== null) {
            return new PostedTransaction($existing['transaction'], true, $existing['metadata'], []);
        }

        $deltas = $this->balanceDeltas($lines, $accountIds);
        $this->assertNoMemberOverdraft($locked, $deltas);

        $now = now();
        $transactionId = $this->insert($type, $referenceType, $referenceId, $idempotencyKey, $currency, $lines, $accountIds, $metadata, $now);

        if ($transactionId === null) {
            // Same key committed between the check and the insert (only possible with different accounts).
            $existing = $this->find($idempotencyKey) ?? throw new \LogicException('Idempotency key conflict without a stored transaction.');

            return new PostedTransaction($existing['transaction'], true, $existing['metadata'], []);
        }

        $this->applyDeltas($deltas, $now);

        $entries = array_map(
            fn (PostingLine $line): LedgerEntryData => new LedgerEntryData(
                $accountIds[$line->accountKey()],
                $line->ownerType,
                $line->ownerId,
                $line->kind,
                $line->amountCents,
            ),
            $lines,
        );

        return new PostedTransaction(
            new LedgerTransactionData($transactionId, $type, $idempotencyKey, $currency, $entries, $now),
            false,
            $metadata,
            $this->memberBalances($lines, $currency),
        );
    }

    /**
     * @param  list<PostingLine>  $lines
     */
    private function assertPostable(array $lines): void
    {
        if (count($lines) < 2) {
            throw new InvalidArgumentException('A ledger transaction needs at least two non-zero lines.');
        }

        $sum = 0;

        foreach ($lines as $line) {
            if ($line->amountCents === 0) {
                throw new InvalidArgumentException('Zero-amount ledger lines must be omitted.');
            }

            $sum += $line->amountCents;
        }

        if ($sum !== 0) {
            throw new InvalidArgumentException("Ledger lines must sum to zero, got {$sum}.");
        }
    }

    /**
     * Net balance change per account id, in the account's normal-side orientation (C§5.4).
     *
     * @param  list<PostingLine>  $lines
     * @param  array<string, int>  $accountIds
     * @return array<int, int>
     */
    private function balanceDeltas(array $lines, array $accountIds): array
    {
        $deltas = [];

        foreach ($lines as $line) {
            $id = $accountIds[$line->accountKey()];
            $deltas[$id] = ($deltas[$id] ?? 0) + ($line->kind->isDebitNormal() ? $line->amountCents : -$line->amountCents);
        }

        return $deltas;
    }

    /**
     * @param  Collection<int, stdClass>  $locked
     * @param  array<int, int>  $deltas
     */
    private function assertNoMemberOverdraft(Collection $locked, array $deltas): void
    {
        foreach ($locked as $account) {
            if ($account->owner_type !== AccountOwnerType::Member->value) {
                continue;
            }

            $id = (int) $account->id;

            if ((int) $account->balance_cents + $deltas[$id] < 0) {
                throw new InsufficientWalletBalanceException(
                    "Member wallet account {$id} holds {$account->balance_cents} cents, not enough for the posting."
                );
            }
        }
    }

    /**
     * Inserts the transaction row and all entries in one multi-row statement. The parent constraint is
     * deferred until its entries exist so the BEFORE INSERT ownership trigger can inspect parent xmin.
     *
     * @param  list<PostingLine>  $lines
     * @param  array<string, int>  $accountIds
     * @param  array<string, mixed>  $metadata
     */
    private function insert(
        LedgerTransactionType $type,
        string $referenceType,
        int $referenceId,
        string $idempotencyKey,
        Currency $currency,
        array $lines,
        array $accountIds,
        array $metadata,
        CarbonImmutable $now,
    ): ?int {
        DB::statement('SET CONSTRAINTS ledger_transactions_have_entries DEFERRED');

        $rows = DB::select(<<<'SQL'
            INSERT INTO ledger_transactions (type, reference_type, reference_id, idempotency_key, currency, metadata, created_at)
            VALUES (?, ?, ?, ?, ?, CAST(? AS jsonb), ?)
            ON CONFLICT (idempotency_key) DO NOTHING
            RETURNING id
            SQL, [
            $type->value,
            $referenceType,
            $referenceId,
            $idempotencyKey,
            $currency->value,
            json_encode($metadata === [] ? new stdClass : $metadata, JSON_THROW_ON_ERROR),
            Timestamps::bind($now),
        ]);

        if ($rows === []) {
            DB::statement('SET CONSTRAINTS ledger_transactions_have_entries IMMEDIATE');

            return null;
        }

        $transactionId = (int) $rows[0]->id;

        $values = implode(', ', array_fill(0, count($lines), '(CAST(? AS bigint), CAST(? AS bigint), CAST(? AS bigint), ?)'));
        $bindings = [];

        foreach ($lines as $line) {
            $bindings[] = $transactionId;
            $bindings[] = $accountIds[$line->accountKey()];
            $bindings[] = $line->amountCents;
            $bindings[] = Timestamps::bind($now);
        }

        DB::statement(<<<SQL
            INSERT INTO ledger_entries (transaction_id, account_id, amount_cents, created_at)
            VALUES {$values}
            SQL, $bindings);

        DB::statement('SET CONSTRAINTS ledger_transactions_have_entries IMMEDIATE');

        return (int) $transactionId;

    }

    /**
     * @param  array<int, int>  $deltas
     */
    private function applyDeltas(array $deltas, CarbonImmutable $now): void
    {
        $values = implode(', ', array_fill(0, count($deltas), '(CAST(? AS bigint), CAST(? AS bigint))'));
        $bindings = [Timestamps::bind($now)];

        foreach ($deltas as $id => $delta) {
            $bindings[] = $id;
            $bindings[] = $delta;
        }

        DB::update(<<<SQL
            UPDATE ledger_accounts AS a
            SET balance_cents = a.balance_cents + v.delta, updated_at = ?
            FROM (VALUES {$values}) AS v(id, delta)
            WHERE a.id = v.id
            SQL, $bindings);
    }

    /**
     * Post-transaction balances of every member the lines touched.
     *
     * @param  list<PostingLine>  $lines
     * @return list<array{member_id: int, credit_cents: int, coupon_cents: int}>
     */
    private function memberBalances(array $lines, Currency $currency): array
    {
        $memberIds = [];

        foreach ($lines as $line) {
            if ($line->ownerType === AccountOwnerType::Member) {
                $memberIds[$line->ownerId] = $line->ownerId;
            }
        }

        if ($memberIds === []) {
            return [];
        }

        ksort($memberIds);

        $balances = [];

        foreach ($memberIds as $memberId) {
            $balances[] = ['member_id' => $memberId] + MemberBalances::for($memberId, $currency);
        }

        return $balances;
    }

    /**
     * @return array{transaction: LedgerTransactionData, metadata: array<string, mixed>}|null
     */
    private function find(string $idempotencyKey): ?array
    {
        $row = DB::table('ledger_transactions')->where('idempotency_key', $idempotencyKey)->first();

        if ($row === null) {
            return null;
        }

        $entries = DB::table('ledger_entries')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.account_id')
            ->where('ledger_entries.transaction_id', $row->id)
            ->orderBy('ledger_entries.id')
            ->get(['ledger_entries.account_id', 'ledger_accounts.owner_type', 'ledger_accounts.owner_id', 'ledger_accounts.kind', 'ledger_entries.amount_cents']);

        /** @var array<string, mixed> $metadata */
        $metadata = json_decode((string) $row->metadata, true, 512, JSON_THROW_ON_ERROR);

        return [
            'transaction' => new LedgerTransactionData(
                (int) $row->id,
                LedgerTransactionType::from((string) $row->type),
                (string) $row->idempotency_key,
                Currency::from((string) $row->currency),
                array_values($entries->map(fn (stdClass $entry): LedgerEntryData => new LedgerEntryData(
                    (int) $entry->account_id,
                    AccountOwnerType::from((string) $entry->owner_type),
                    (int) $entry->owner_id,
                    AccountKind::from((string) $entry->kind),
                    (int) $entry->amount_cents,
                ))->all()),
                new CarbonImmutable((string) $row->created_at),
            ),
            'metadata' => $metadata,
        ];
    }
}
