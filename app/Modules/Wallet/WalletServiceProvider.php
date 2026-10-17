<?php

declare(strict_types=1);

namespace App\Modules\Wallet;

use App\Modules\Wallet\Console\ReconcileLedgerCommand;
use App\Shared\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use InvalidArgumentException;

final class WalletServiceProvider extends ModuleServiceProvider
{
    /**
     * @throws InvalidArgumentException if the late-cancel split is misconfigured
     */
    public function boot(): void
    {
        parent::boot();

        $member = config()->integer('wallet.late_cancel.member_bps');
        $platform = config()->integer('wallet.late_cancel.platform_bps');

        if ($member < 0 || $platform < 0 || $member + $platform > 10000) {
            throw new InvalidArgumentException(
                "Invalid wallet.late_cancel split: member_bps {$member} + platform_bps {$platform} must be non-negative and total at most 10000."
            );
        }
    }

    protected function consoleCommands(): array
    {
        return [ReconcileLedgerCommand::class];
    }

    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('wallet:reconcile')
            ->dailyAt('03:00')
            ->timezone('UTC')
            ->withoutOverlapping()
            ->onOneServer();
    }
}
