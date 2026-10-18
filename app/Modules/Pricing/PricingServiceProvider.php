<?php

declare(strict_types=1);

namespace App\Modules\Pricing;

use App\Modules\Inventory\Events\AvailabilitySearched;
use App\Modules\Pricing\Console\ComputeDemandSignalsCommand;
use App\Modules\Pricing\Console\PruneSearchEventsCommand;
use App\Modules\Pricing\Listeners\RecordAvailabilitySearch;
use App\Shared\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

final class PricingServiceProvider extends ModuleServiceProvider
{
    protected function consoleCommands(): array
    {
        return [ComputeDemandSignalsCommand::class, PruneSearchEventsCommand::class];
    }

    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('pricing:compute-demand-signals')
            ->everyFifteenMinutes()
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command('pricing:prune-search-events')
            ->dailyAt('04:00')
            ->timezone('UTC')
            ->withoutOverlapping()
            ->onOneServer();
    }

    protected function listeners(): array
    {
        return [AvailabilitySearched::class => [RecordAvailabilitySearch::class]];
    }
}
