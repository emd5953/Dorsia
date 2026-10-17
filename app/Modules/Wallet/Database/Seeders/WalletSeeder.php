<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Database\Seeders;

use App\Modules\Wallet\Actions\IssueGoodwillCredit;
use App\Shared\Members\Member;
use App\Shared\Money\Currency;
use App\Shared\Money\Money;
use Illuminate\Database\Seeder;

final class WalletSeeder extends Seeder
{
    /** Re-runnable: the fixed idempotency key makes a second run a replay. */
    public function run(IssueGoodwillCredit $issue): void
    {
        $member = Member::query()->where('email', 'demo@dorsia.test')->firstOrFail();

        $issue->handle($member->id, Money::of(50000, Currency::USD), 'seed:demo:goodwill');
    }
}
