<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Modules\Inventory\Console\ExpireHoldsCommand;
use App\Modules\Inventory\Console\OpenDropsCommand;
use App\Shared\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

final class InventoryServiceProvider extends ModuleServiceProvider
{
    protected function consoleCommands(): array
    {
        return [ExpireHoldsCommand::class, OpenDropsCommand::class];
    }

    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('inventory:expire-holds')->everyMinute()->withoutOverlapping()->onOneServer();
        $schedule->command('inventory:open-drops')->everyMinute()->withoutOverlapping()->onOneServer();
    }
}
