<?php

declare(strict_types=1);

use App\Modules\Wallet\Actions\GetWalletBalance;
use App\Modules\Wallet\Actions\IssueGoodwillCredit;
use App\Modules\Wallet\Actions\PlanSpend;
use App\Modules\Wallet\Actions\PostBookingPayment;
use App\Modules\Wallet\Actions\PostCancellationRefund;
use App\Modules\Wallet\Actions\ReconcileLedger;
use App\Modules\Wallet\Data\BookingPaymentInput;
use App\Modules\Wallet\Data\CancellationRefund;
use App\Modules\Wallet\Data\CancellationRefundInput;
use App\Modules\Wallet\Data\LedgerEntryData;
use App\Modules\Wallet\Data\LedgerTransactionData;
use App\Modules\Wallet\Data\ReconciliationReport;
use App\Modules\Wallet\Data\SpendPlan;
use App\Modules\Wallet\Data\WalletBalance;
use App\Modules\Wallet\Enums\AccountKind;
use App\Modules\Wallet\Enums\AccountOwnerType;
use App\Modules\Wallet\Enums\LedgerTransactionType;
use App\Modules\Wallet\Exceptions\InsufficientWalletBalanceException;
use App\Shared\Exceptions\DomainError;
use App\Shared\Outbox\Outbox;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

/**
 * @param  class-string  $class
 * @param  array<string, string>  $parameters  name => type, in order
 */
function expectMethod(string $class, string $method, array $parameters, string $return): void
{
    $reflection = new ReflectionMethod($class, $method);

    $actual = [];

    foreach ($reflection->getParameters() as $parameter) {
        $actual[$parameter->getName()] = (string) $parameter->getType();
    }

    expect($actual)->toBe($parameters, "{$class}::{$method} parameters")
        ->and((string) $reflection->getReturnType())->toBe($return, "{$class}::{$method} return type")
        ->and($reflection->isPublic())->toBeTrue();
}

/**
 * @param  class-string  $class
 * @param  array<string, string>  $properties  name => type
 */
function expectDto(string $class, array $properties): void
{
    $reflection = new ReflectionClass($class);

    expect($reflection->isFinal())->toBeTrue("{$class} final")
        ->and($reflection->isReadOnly())->toBeTrue("{$class} readonly");

    $actual = [];

    foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
        $actual[$parameter->getName()] = (string) $parameter->getType();
    }

    expect($actual)->toBe($properties, "{$class} constructor");

    foreach (array_keys($properties) as $name) {
        expect($reflection->getProperty($name)->isPublic())->toBeTrue("{$class}::\${$name} public");
    }
}

it('has the enums of C§5.3 with exact cases', function (): void {
    expect(array_column(AccountKind::cases(), 'value', 'name'))->toBe([
        'Cash' => 'cash', 'Credit' => 'credit', 'Coupon' => 'coupon', 'VenuePayable' => 'venue_payable', 'Platform' => 'platform',
    ])
        ->and(array_column(AccountOwnerType::cases(), 'value', 'name'))->toBe([
            'Member' => 'member', 'Venue' => 'venue', 'System' => 'system',
        ])
        ->and(array_column(LedgerTransactionType::cases(), 'value', 'name'))->toBe([
            'Booking' => 'booking', 'CancelEarly' => 'cancel_early', 'CancelLate' => 'cancel_late', 'GoodwillCredit' => 'goodwill_credit',
        ]);
});

it('orients balances per C§5.4: only cash is debit-normal', function (): void {
    foreach (AccountKind::cases() as $kind) {
        expect($kind->isDebitNormal())->toBe($kind === AccountKind::Cash, $kind->value);
    }
});

it('has the DTOs of C§5.3 with exact constructors', function (): void {
    expectDto(SpendPlan::class, ['coupon' => 'App\Shared\Money\Money', 'credit' => 'App\Shared\Money\Money', 'card' => 'App\Shared\Money\Money']);
    expectDto(BookingPaymentInput::class, ['memberId' => 'int', 'venueId' => 'int', 'holdId' => 'int', 'reservationId' => 'int', 'plan' => SpendPlan::class]);
    expectDto(CancellationRefundInput::class, ['memberId' => 'int', 'venueId' => 'int', 'reservationId' => 'int', 'amount' => 'App\Shared\Money\Money', 'late' => 'bool']);
    expectDto(CancellationRefund::class, ['transactionId' => '?int', 'memberCredit' => 'App\Shared\Money\Money', 'platformShare' => 'App\Shared\Money\Money', 'venueRetained' => 'App\Shared\Money\Money']);
    expectDto(LedgerEntryData::class, ['accountId' => 'int', 'ownerType' => AccountOwnerType::class, 'ownerId' => 'int', 'kind' => AccountKind::class, 'amountCents' => 'int']);
    expectDto(LedgerTransactionData::class, ['id' => 'int', 'type' => LedgerTransactionType::class, 'idempotencyKey' => 'string', 'currency' => 'App\Shared\Money\Currency', 'entries' => 'array', 'createdAt' => 'Carbon\CarbonImmutable']);
    expectDto(WalletBalance::class, ['memberId' => 'int', 'credit' => 'App\Shared\Money\Money', 'coupon' => 'App\Shared\Money\Money']);
    expectDto(ReconciliationReport::class, ['accountsChecked' => 'int', 'globalSumCents' => 'int', 'unbalancedTransactions' => 'int', 'drift' => 'array']);

    expectMethod(SpendPlan::class, 'total', [], 'App\Shared\Money\Money');
    expectMethod(ReconciliationReport::class, 'clean', [], 'bool');
});

it('has the actions of C§5.3: non-final, one public handle(), exact signatures', function (): void {
    foreach ([PlanSpend::class, PostBookingPayment::class, PostCancellationRefund::class, IssueGoodwillCredit::class, GetWalletBalance::class, ReconcileLedger::class] as $class) {
        $reflection = new ReflectionClass($class);
        $public = array_values(array_filter(
            array_map(fn (ReflectionMethod $m): string => $m->getName(), $reflection->getMethods(ReflectionMethod::IS_PUBLIC)),
            fn (string $name): bool => $name !== '__construct',
        ));

        expect($reflection->isFinal())->toBeFalse("{$class} must not be final")
            ->and($public)->toBe(['handle'], "{$class} public methods");
    }

    expectMethod(PlanSpend::class, 'handle', ['memberId' => 'int', 'amount' => 'App\Shared\Money\Money'], SpendPlan::class);
    expectMethod(PostBookingPayment::class, 'handle', ['input' => BookingPaymentInput::class], LedgerTransactionData::class);
    expectMethod(PostCancellationRefund::class, 'handle', ['input' => CancellationRefundInput::class], CancellationRefund::class);
    expectMethod(IssueGoodwillCredit::class, 'handle', ['memberId' => 'int', 'amount' => 'App\Shared\Money\Money', 'idempotencyKey' => 'string'], LedgerTransactionData::class);
    expectMethod(GetWalletBalance::class, 'handle', ['memberId' => 'int', 'currency' => 'App\Shared\Money\Currency'], WalletBalance::class);
    expectMethod(ReconcileLedger::class, 'handle', ['rebuild' => 'bool'], ReconciliationReport::class);

    expect((new ReflectionMethod(ReconcileLedger::class, 'handle'))->getParameters()[0]->getDefaultValue())->toBeFalse();
});

it('takes Outbox as the first constructor dependency of every posting action', function (): void {
    foreach ([PostBookingPayment::class, PostCancellationRefund::class, IssueGoodwillCredit::class] as $class) {
        $first = (new ReflectionClass($class))->getConstructor()?->getParameters()[0];

        expect($first?->getName())->toBe('outbox')
            ->and((string) $first?->getType())->toBe(Outbox::class);
    }
});

it('has InsufficientWalletBalanceException as a 409 wallet_balance_changed DomainError', function (): void {
    $exception = new InsufficientWalletBalanceException('x');

    expect($exception)->toBeInstanceOf(DomainError::class)
        ->and($exception->errorCode())->toBe('wallet_balance_changed')
        ->and($exception->httpStatus())->toBe(409);
});

it('renders the exception through the error envelope', function (): void {
    Route::get('/api/_test/insufficient', fn () => throw new InsufficientWalletBalanceException('Balance changed.'));

    $this->getJson('/api/_test/insufficient')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'wallet_balance_changed');
});

it('registers GET /api/wallet behind auth:sanctum, wallet:reconcile and the config keys', function (): void {
    $route = Route::getRoutes()->match(Request::create('/api/wallet', 'GET'));

    expect($route->gatherMiddleware())->toContain('auth:sanctum')
        ->and(array_key_exists('wallet:reconcile', Artisan::all()))->toBeTrue()
        ->and(Artisan::all()['wallet:reconcile']->getDefinition()->hasOption('rebuild'))->toBeTrue()
        ->and(config('wallet.late_cancel.member_bps'))->toBeInt()
        ->and(config('wallet.late_cancel.platform_bps'))->toBeInt()
        ->and(config('wallet.currency'))->toBe('USD');
});

it('lets only Support\PostTransaction write ledger tables', function (): void {
    $writers = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        // Raw SQL, query-builder and Eloquent writes against ledger_transactions / ledger_entries.
        $patterns = [
            '/INSERT\s+INTO\s+"?ledger_(entries|transactions)/i',
            '/table\(\s*[\'"]ledger_(entries|transactions)[\'"]\s*\)\s*->\s*(insert|insertGetId|insertOrIgnore|upsert)/i',
            '/LedgerEntry::(create|insert|forceCreate|query\(\)->create)/',
            '/LedgerTransaction::(create|insert|forceCreate|query\(\)->create)/',
            '/new\s+Ledger(Entry|Transaction)\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $source) === 1) {
                $writers[] = str_replace(app_path().'/', '', $file->getPathname());
            }
        }
    }

    expect(array_values(array_unique($writers)))->toBe(['Modules/Wallet/Support/PostTransaction.php']);
});

it('inserts all entries of a transaction with one multi-row INSERT and locks accounts in id order', function (): void {
    $source = (string) file_get_contents(app_path('Modules/Wallet/Support/PostTransaction.php'));

    expect(substr_count($source, 'INSERT INTO ledger_entries'))->toBe(1)
        ->and($source)->toContain('->orderBy(\'id\')')
        ->and($source)->toContain('->lockForUpdate()');
});

it('never calls SQL now() in Wallet application code (C§3)', function (): void {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Modules/Wallet'), FilesystemIterator::SKIP_DOTS));
    $offenders = [];

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php' || str_contains($file->getPathname(), '/Migrations/')) {
            continue;
        }

        if (preg_match('/(?<!>)\b(SELECT|INSERT|UPDATE|WHERE)\b[^;\n]*\bNOW\(\)|CURRENT_TIMESTAMP/i', (string) file_get_contents($file->getPathname())) === 1) {
            $offenders[] = $file->getPathname();
        }
    }

    expect($offenders)->toBe([]);
});
