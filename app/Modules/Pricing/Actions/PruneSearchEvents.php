<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class PruneSearchEvents
{
    /**
     * Deletes search events created strictly before now minus pricing.search_retention_days.
     *
     * @return int rows deleted
     */
    public function handle(CarbonImmutable $now): int
    {
        $retention = config('pricing.search_retention_days');
        $cutoff = $now->subDays(is_int($retention) ? $retention : 90);

        return DB::table('search_events')
            ->where('created_at', '<', $cutoff->utc()->format('Y-m-d H:i:s.uP'))
            ->delete();
    }
}
