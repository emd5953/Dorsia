<?php

declare(strict_types=1);

namespace App\Modules\Realtime\Console;

use App\Modules\Realtime\Actions\PruneOutbox;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class PruneOutboxCommand extends Command
{
    protected $signature = 'realtime:prune-outbox';

    protected $description = 'Delete published outbox events beyond their retention period';

    public function handle(PruneOutbox $prune): int
    {
        $deleted = $prune->handle(CarbonImmutable::now('UTC'));
        $this->info("Pruned {$deleted} outbox events.");

        return self::SUCCESS;
    }
}
