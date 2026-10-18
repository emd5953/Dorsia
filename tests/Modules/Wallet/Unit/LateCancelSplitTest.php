<?php

declare(strict_types=1);

use App\Modules\Wallet\Support\LateCancelSplit;
use App\Shared\Money\Currency;
use App\Shared\Money\Money;

function split(int $cents, int $memberBps = 5000, int $platformBps = 2500): LateCancelSplit
{
    return LateCancelSplit::calculate(Money::of($cents, Currency::USD), $memberBps, $platformBps);
}

it('splits the build plan example: 1000 forfeits 500 to the member, 250 to platform, 250 to the venue', function (): void {
    $split = split(100000);

    expect([$split->member->cents, $split->platform->cents, $split->venueRetained->cents])->toBe([50000, 25000, 25000]);
});

it('floors both shares and leaves the odd cents with the venue (D14)', function (int $amount, int $member, int $platform, int $venue): void {
    $split = split($amount);

    expect([$split->member->cents, $split->platform->cents, $split->venueRetained->cents])->toBe([$member, $platform, $venue])
        ->and($split->member->cents + $split->platform->cents + $split->venueRetained->cents)->toBe($amount);
})->with([
    'odd 99999' => [99999, 49999, 24999, 25001],
    '1 cent' => [1, 0, 0, 1],
    '3 cents' => [3, 1, 0, 2],
    '4 cents' => [4, 2, 1, 1],
    '1001' => [1001, 500, 250, 251],
]);

it('follows the configured shares', function (): void {
    $split = split(100000, 6000, 2000);

    expect([$split->member->cents, $split->platform->cents, $split->venueRetained->cents])->toBe([60000, 20000, 20000]);
});

it('accepts shares totalling exactly 100% and retains nothing', function (): void {
    $split = split(1000, 7000, 3000);

    expect($split->venueRetained->cents)->toBe(0);
});

it('rejects shares above 100% or negative', function (int $member, int $platform): void {
    split(1000, $member, $platform);
})->with([[9000, 1001], [-1, 100], [100, -1]])->throws(InvalidArgumentException::class);
