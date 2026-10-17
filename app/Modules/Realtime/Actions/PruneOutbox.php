<?php

declare(strict_types=1);

namespace App\Modules\Realtime\Actions;

use App\Shared\Outbox\OutboxEvent;
use Carbon\CarbonImmutable;

class PruneOutbox
{
    public function handle(CarbonImmutable $now): int
    {
        $cutoff = $now->subDays((int) config('realtime.outbox.retention_days', 7));

        return OutboxEvent::query()
            ->whereNotNull('published_at')
            ->where('published_at', '<', $cutoff)
            ->delete();
    }
}
