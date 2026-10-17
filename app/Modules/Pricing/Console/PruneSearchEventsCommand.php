<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Console;

use App\Modules\Pricing\Actions\PruneSearchEvents;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class PruneSearchEventsCommand extends Command
{
    protected $signature = 'pricing:prune-search-events';

    protected $description = 'Delete search events past the retention window';

    public function handle(PruneSearchEvents $action): int
    {
        $this->info('Deleted '.$action->handle(CarbonImmutable::now()).' search events.');

        return self::SUCCESS;
    }
}
