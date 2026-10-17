<?php

declare(strict_types=1);

use App\Modules\Wallet\WalletServiceProvider;
use Symfony\Component\Process\Process;

function bootProvider(int $member, int $platform): void
{
    config(['wallet.late_cancel.member_bps' => $member, 'wallet.late_cancel.platform_bps' => $platform]);

    (new WalletServiceProvider(app()))->boot();
}

it('has the default split of 5000 and 2500 bps and USD', function (): void {
    expect(config('wallet.late_cancel.member_bps'))->toBe(5000)
        ->and(config('wallet.late_cancel.platform_bps'))->toBe(2500)
        ->and(config('wallet.currency'))->toBe('USD');
});

it('boots with shares totalling exactly 10000', function (): void {
    bootProvider(7000, 3000);

    expect(true)->toBeTrue();
});

it('fails boot when member_bps + platform_bps exceeds 10000', function (int $member, int $platform): void {
    bootProvider($member, $platform);
})->with([[7000, 3001], [10001, 0], [0, 10001]])->throws(InvalidArgumentException::class, 'must be non-negative and total at most 10000');

it('fails boot for a negative share', function (): void {
    bootProvider(-1, 2500);
})->throws(InvalidArgumentException::class);

it('fails a real application boot when the environment is misconfigured', function (): void {
    $process = new Process(['php', 'artisan', 'list'], base_path(), [
        'LATE_CANCEL_MEMBER_BPS' => '9000',
        'LATE_CANCEL_PLATFORM_BPS' => '2000',
    ]);
    $process->run();

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getOutput().$process->getErrorOutput())->toContain('Invalid wallet.late_cancel split');

    $ok = new Process(['php', 'artisan', 'list'], base_path(), [
        'LATE_CANCEL_MEMBER_BPS' => '6000',
        'LATE_CANCEL_PLATFORM_BPS' => '2000',
    ]);
    $ok->run();

    expect($ok->isSuccessful())->toBeTrue();
});
