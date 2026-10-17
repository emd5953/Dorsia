<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Database\Factories;

use App\Modules\Wallet\Enums\AccountKind;
use App\Modules\Wallet\Enums\LedgerTransactionType;
use App\Modules\Wallet\Models\LedgerTransaction;
use App\Modules\Wallet\Support\PostingLine;
use App\Modules\Wallet\Support\PostTransaction;
use App\Shared\Members\Member;
use App\Shared\Money\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Funds a member account (platform debit, member credit) through PostTransaction; it never inserts rows itself.
 * Use `kind(AccountKind::Coupon)` to seed a coupon balance, which nothing else grants yet.
 *
 * @extends Factory<LedgerTransaction>
 */
final class LedgerTransactionFactory extends Factory
{
    protected $model = LedgerTransaction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'member_id' => Member::factory()->create()->id,
            'kind' => AccountKind::Credit,
            'amount_cents' => 10_000,
            'idempotency_key' => 'factory:'.Str::uuid(),
        ];
    }

    public function forMember(int $memberId): self
    {
        return $this->state(['member_id' => $memberId]);
    }

    public function kind(AccountKind $kind): self
    {
        return $this->state(['kind' => $kind]);
    }

    public function amount(int $cents): self
    {
        return $this->state(['amount_cents' => $cents]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create($attributes = [], ?Model $parent = null): LedgerTransaction
    {
        /** @var array{member_id: int, kind: AccountKind, amount_cents: int, idempotency_key: string} $data */
        $data = $this->state($attributes)->getExpandedAttributes($parent);

        $posted = DB::transaction(fn () => app(PostTransaction::class)->post(
            LedgerTransactionType::GoodwillCredit,
            'member',
            $data['member_id'],
            $data['idempotency_key'],
            Currency::USD,
            [
                PostingLine::platform($data['amount_cents']),
                PostingLine::member($data['member_id'], $data['kind'], -$data['amount_cents']),
            ],
        ));

        return LedgerTransaction::query()->findOrFail($posted->transaction->id);
    }
}
