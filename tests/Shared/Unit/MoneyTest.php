<?php

declare(strict_types=1);

use App\Shared\Money\Currency;
use App\Shared\Money\Money;

it('constructs from cents and currency', function (): void {
    $money = new Money(12500, Currency::USD);

    expect($money->cents)->toBe(12500)
        ->and($money->currency)->toBe(Currency::USD);
});

it('builds with of() from an enum or a string and with zero()', function (): void {
    expect(Money::of(100, 'USD')->equals(Money::of(100, Currency::USD)))->toBeTrue()
        ->and(Money::zero(Currency::USD)->cents)->toBe(0)
        ->and(Money::zero(Currency::USD)->isZero())->toBeTrue();
});

it('rejects negative amounts', function (): void {
    new Money(-1, Currency::USD);
})->throws(InvalidArgumentException::class);

it('rejects an unknown currency string', function (): void {
    Money::of(1, 'EUR');
})->throws(ValueError::class);

it('adds and subtracts', function (): void {
    $a = Money::of(700, 'USD');
    $b = Money::of(250, 'USD');

    expect($a->add($b)->cents)->toBe(950)
        ->and($a->subtract($b)->cents)->toBe(450)
        ->and($a->subtract($a)->isZero())->toBeTrue();
});

it('refuses a subtraction that would go negative', function (): void {
    Money::of(100, 'USD')->subtract(Money::of(101, 'USD'));
})->throws(InvalidArgumentException::class);

it('multiplies by a non-negative factor', function (): void {
    expect(Money::of(250, 'USD')->multiply(3)->cents)->toBe(750)
        ->and(Money::of(250, 'USD')->multiply(0)->cents)->toBe(0);
});

it('refuses a negative factor', function (): void {
    Money::of(250, 'USD')->multiply(-1);
})->throws(InvalidArgumentException::class);

it('returns the smaller amount from min()', function (): void {
    $small = Money::of(100, 'USD');
    $large = Money::of(900, 'USD');

    expect($large->min($small)->cents)->toBe(100)
        ->and($small->min($large)->cents)->toBe(100)
        ->and($small->min($small)->cents)->toBe(100);
});

it('floors basis point math', function (): void {
    expect(Money::of(10001, 'USD')->bps(5000)->cents)->toBe(5000)
        ->and(Money::of(99, 'USD')->bps(2500)->cents)->toBe(24)
        ->and(Money::of(1, 'USD')->bps(9999)->cents)->toBe(0)
        ->and(Money::of(75000, 'USD')->bps(10000)->cents)->toBe(75000)
        ->and(Money::of(75000, 'USD')->bps(0)->isZero())->toBeTrue();
});

/*
| Currency has a single case (USD, D24), so a real mismatch cannot be built from the
| shipped enum. Money's source is loaded into a throwaway namespace next to a
| two-case enum, which exercises the exact production comparison logic.
*/
it('rejects mixed currencies in every binary operation', function (string $method): void {
    $ns = 'MoneyMismatchProbe'.bin2hex(random_bytes(4));

    $load = static function (string $path) use ($ns): string {
        $source = (string) preg_replace('/^<\?php\s*/', '', (string) file_get_contents($path));

        return str_replace('namespace App\Shared\Money;', "namespace {$ns};", $source);
    };

    eval("namespace {$ns}; enum Currency: string { case USD = 'USD'; case EUR = 'EUR'; }");
    eval($load(app_path('Shared/Money/CurrencyMismatchException.php')));
    eval($load(app_path('Shared/Money/Money.php')));

    $money = "{$ns}\\Money";
    $currency = "{$ns}\\Currency";
    $usd = new $money(100, $currency::USD);
    $eur = new $money(100, $currency::EUR);

    expect(fn () => $usd->{$method}($eur))->toThrow("{$ns}\\CurrencyMismatchException");
    expect($usd->equals($eur))->toBeFalse();
})->with(['add', 'subtract', 'min', 'greaterThan', 'greaterThanOrEqual']);

it('compares', function (): void {
    $a = Money::of(100, 'USD');
    $b = Money::of(200, 'USD');

    expect($a->equals(Money::of(100, 'USD')))->toBeTrue()
        ->and($a->equals($b))->toBeFalse()
        ->and($b->greaterThan($a))->toBeTrue()
        ->and($a->greaterThan($a))->toBeFalse()
        ->and($a->greaterThanOrEqual($a))->toBeTrue()
        ->and($a->greaterThanOrEqual($b))->toBeFalse()
        ->and($a->isPositive())->toBeTrue()
        ->and(Money::zero(Currency::USD)->isPositive())->toBeFalse();
});

it('serialises with toArray', function (): void {
    expect(Money::of(1999, 'USD')->toArray())->toBe(['cents' => 1999, 'currency' => 'USD']);
});
