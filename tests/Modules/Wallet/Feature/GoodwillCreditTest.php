<?php

declare(strict_types=1);

use App\Modules\Wallet\Actions\IssueGoodwillCredit;
use App\Modules\Wallet\Database\Seeders\WalletSeeder;
use App\Modules\Wallet\Enums\AccountKind;
use App\Modules\Wallet\Enums\LedgerTransactionType;
use App\Shared\Members\Member;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\MemberSeeder;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../WalletHelpers.php';

it('debits platform and credits the member credit account', function (): void {
    $member = newMemberId();

    $tx = app(IssueGoodwillCredit::class)->handle($member, usd(5000), 'goodwill:1');

    expect($tx->type)->toBe(LedgerTransactionType::GoodwillCredit)
        ->and($tx->idempotencyKey)->toBe('goodwill:1')
        ->and(walletEntries($tx->id))->toBe(['system:0:platform' => 5000, 'member:'.$member.':credit' => -5000])
        ->and(walletBalance('system', 0, AccountKind::Platform))->toBe(-5000)
        ->and(walletBalance('member', $member, AccountKind::Credit))->toBe(5000);
});

it('replays on its key without a second posting', function (): void {
    $member = newMemberId();
    $issue = app(IssueGoodwillCredit::class);

    $first = $issue->handle($member, usd(5000), 'goodwill:2');
    $second = $issue->handle($member, usd(5000), 'goodwill:2');

    expect($second->id)->toBe($first->id)
        ->and(DB::table('ledger_transactions')->count())->toBe(1)
        ->and(DB::table('ledger_entries')->count())->toBe(2)
        ->and(walletBalance('member', $member, AccountKind::Credit))->toBe(5000);
});

it('seeds the demo member with 50000 cents of credit and is re-runnable', function (): void {
    $this->seed(MemberSeeder::class);
    $demo = Member::query()->where('email', 'demo@dorsia.test')->firstOrFail();

    $this->seed(WalletSeeder::class);
    $this->seed(WalletSeeder::class);

    expect(walletBalance('member', $demo->id, AccountKind::Credit))->toBe(50000)
        ->and(DB::table('ledger_transactions')->where('idempotency_key', 'seed:demo:goodwill')->count())->toBe(1);
});

it('is called by DatabaseSeeder', function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    $demo = Member::query()->where('email', 'demo@dorsia.test')->firstOrFail();

    expect(walletBalance('member', $demo->id, AccountKind::Credit))->toBe(50000);
});
