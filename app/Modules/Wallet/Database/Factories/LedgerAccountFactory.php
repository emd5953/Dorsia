<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Database\Factories;

use App\Modules\Wallet\Enums\AccountKind;
use App\Modules\Wallet\Enums\AccountOwnerType;
use App\Modules\Wallet\Models\LedgerAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Accounts only, always with a zero balance: balances change only through PostTransaction.
 *
 * @extends Factory<LedgerAccount>
 */
final class LedgerAccountFactory extends Factory
{
    protected $model = LedgerAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_type' => AccountOwnerType::Venue,
            'owner_id' => fake()->unique()->numberBetween(1, 1_000_000),
            'kind' => AccountKind::VenuePayable,
            'currency' => 'USD',
            'balance_cents' => 0,
        ];
    }

    public function forMember(int $memberId, AccountKind $kind = AccountKind::Credit): self
    {
        return $this->state(['owner_type' => AccountOwnerType::Member, 'owner_id' => $memberId, 'kind' => $kind]);
    }
}
