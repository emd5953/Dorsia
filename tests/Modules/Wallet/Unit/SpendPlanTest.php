<?php

declare(strict_types=1);

use App\Modules\Wallet\Data\SpendPlan;
use App\Shared\Money\Currency;
use App\Shared\Money\Money;

it('totals coupon, credit and card', function (): void {
    $plan = new SpendPlan(Money::of(100, Currency::USD), Money::of(250, Currency::USD), Money::of(5, Currency::USD));

    expect($plan->total()->cents)->toBe(355)
        ->and($plan->total()->currency)->toBe(Currency::USD);
});

it('totals an all-zero plan to zero', function (): void {
    $zero = Money::zero(Currency::USD);

    expect((new SpendPlan($zero, $zero, $zero))->total()->isZero())->toBeTrue();
});

/*
| Currency has a single case (USD, D24), so a mismatch cannot be built from the shipped enum.
| As in the Shared MoneyTest, the production sources are loaded into a throwaway namespace
| next to a two-case enum, which runs the exact production SpendPlan and Money code.
*/
it('rejects parts in different currencies at construction', function (): void {
    $ns = 'SpendPlanProbe'.bin2hex(random_bytes(4));

    $load = static function (string $path, string $namespace) use ($ns): string {
        $source = (string) preg_replace('/^<\?php\s*(declare\(strict_types=1\);)?\s*/', '', (string) file_get_contents($path));
        $source = (string) preg_replace('/^namespace [^;]+;/m', "namespace {$namespace};", $source);

        return str_replace(['App\Shared\Money\\', 'App\Modules\Wallet\Data\\'], ["{$ns}\\", "{$ns}\\"], $source);
    };

    eval("namespace {$ns}; enum Currency: string { case USD = 'USD'; case EUR = 'EUR'; }");
    eval($load(app_path('Shared/Money/CurrencyMismatchException.php'), $ns));
    eval($load(app_path('Shared/Money/Money.php'), $ns));
    eval($load(app_path('Modules/Wallet/Data/SpendPlan.php'), $ns));

    $money = "{$ns}\\Money";
    $currency = "{$ns}\\Currency";
    $plan = "{$ns}\\SpendPlan";

    $usd = new $money(100, $currency::USD);
    $eur = new $money(100, $currency::EUR);

    expect(fn () => new $plan($usd, $usd, $eur))->toThrow("{$ns}\\CurrencyMismatchException")
        ->and(fn () => new $plan($eur, $usd, $usd))->toThrow("{$ns}\\CurrencyMismatchException")
        ->and(new $plan($usd, $usd, $usd))->toBeObject();
});
