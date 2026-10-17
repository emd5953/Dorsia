<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Console;

use App\Modules\Pricing\Actions\ComputeDemandSignals;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class ComputeDemandSignalsCommand extends Command
{
    protected $signature = 'pricing:compute-demand-signals';

    protected $description = 'Recompute demand_signals from recent searches and sell-out speed';

    public function handle(ComputeDemandSignals $action): int
    {
        $this->info('Wrote '.$action->handle(CarbonImmutable::now()).' demand signal rows.');

        return self::SUCCESS;
    }
}
