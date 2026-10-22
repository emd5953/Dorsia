<?php

declare(strict_types=1);

use App\Modules\Wallet\Actions\IssueGoodwillCredit;
use App\Shared\Members\Member;
use App\Shared\Money\Currency;
use App\Shared\Money\Money;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('local') || config('payments.gateway') !== 'fake' || config('database.connections.pgsql.database') !== 'dorsia') {
    throw new RuntimeException('Payment fixtures require the local dorsia database and fake gateway.');
}

// New accounts on each run preserve all existing members, wallet entries and bookings.
$run = Str::lower(Str::random(10));
$accounts = [];
foreach (['desktop' => 50000, 'mobile' => 0, 'integration' => 10000] as $scenario => $credit) {
    $member = Member::query()->create([
        'name' => 'Card Test '.ucfirst($scenario),
        'email' => "card-{$scenario}-{$run}@dorsia.test",
        'password' => 'password',
    ]);
    if ($credit > 0) {
        $app->make(IssueGoodwillCredit::class)->handle($member->id, Money::of($credit, Currency::USD), "frontend:{$run}:{$scenario}");
    }
    $accounts[$scenario] = ['id' => $member->id, 'email' => $member->email, 'credit_cents' => $credit];
}
echo json_encode(['run' => $run, 'accounts' => $accounts], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
