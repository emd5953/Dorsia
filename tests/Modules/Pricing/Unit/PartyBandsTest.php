<?php

declare(strict_types=1);

use App\Modules\Pricing\Support\PartyBands;

it('maps party sizes to bands at the boundaries', function (int $size, string $band): void {
    expect(PartyBands::forPartySize($size))->toBe($band);
})->with([
    'one' => [1, '1-2'],
    'two' => [2, '1-2'],
    'three' => [3, '3-4'],
    'four' => [4, '3-4'],
    'five' => [5, '5+'],
    'twenty' => [20, '5+'],
]);

it('lists band keys in configured order', function (): void {
    expect(PartyBands::keys())->toBe(['1-2', '3-4', '5+']);
});

it('is driven by config', function (): void {
    config(['pricing.bands' => [
        ['key' => 'small', 'min' => 1, 'max' => 3],
        ['key' => 'large', 'min' => 4, 'max' => null],
    ]]);

    expect(PartyBands::forPartySize(3))->toBe('small')
        ->and(PartyBands::forPartySize(4))->toBe('large')
        ->and(PartyBands::keys())->toBe(['small', 'large']);
});

it('rejects a party size no band covers', function (): void {
    PartyBands::forPartySize(0);
})->throws(InvalidArgumentException::class);
