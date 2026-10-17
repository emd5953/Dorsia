<?php

declare(strict_types=1);

namespace App\Shared\Idempotency;

use App\Shared\Support\Timestamps;
use Illuminate\Console\Command;

final class PruneIdempotencyKeys extends Command
{
    protected $signature = 'idempotency:prune';

    protected $description = 'Delete idempotency keys older than the retention window';

    public function handle(): int
    {
        $cutoff = now()->subHours((int) config('idempotency.retention_hours'));

        $deleted = IdempotencyRecord::query()->where('created_at', '<', Timestamps::bind($cutoff))->delete();

        $this->info("Pruned {$deleted} idempotency keys.");

        return self::SUCCESS;
    }
}
