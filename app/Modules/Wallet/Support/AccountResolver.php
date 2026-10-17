<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Support;

use App\Shared\Money\Currency;
use App\Shared\Support\Timestamps;
use Illuminate\Support\Facades\DB;

/**
 * Finds accounts, creating them lazily (C§3): no account, system ones included, exists until first use.
 */
final class AccountResolver
{
    /**
     * Creation order is the sorted account key, the same for every caller, so two transactions that create
     * overlapping accounts at the same time wait on each other in one direction only and cannot deadlock.
     *
     * @param  list<PostingLine>  $lines
     * @return array<string, int> account key => account id
     */
    public function resolve(array $lines, Currency $currency): array
    {
        $unique = [];

        foreach ($lines as $line) {
            $unique[$line->accountKey()] = $line;
        }

        ksort($unique);

        $ids = [];

        foreach ($unique as $key => $line) {
            $ids[$key] = $this->find($line, $currency) ?? $this->create($line, $currency);
        }

        return $ids;
    }

    private function find(PostingLine $line, Currency $currency): ?int
    {
        $id = DB::table('ledger_accounts')
            ->where('owner_type', $line->ownerType->value)
            ->where('owner_id', $line->ownerId)
            ->where('kind', $line->kind->value)
            ->where('currency', $currency->value)
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    private function create(PostingLine $line, Currency $currency): int
    {
        $now = Timestamps::bind(now());

        DB::table('ledger_accounts')->insertOrIgnore([
            'owner_type' => $line->ownerType->value,
            'owner_id' => $line->ownerId,
            'kind' => $line->kind->value,
            'currency' => $currency->value,
            'balance_cents' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->find($line, $currency) ?? throw new \LogicException('Ledger account vanished after creation.');
    }
}
