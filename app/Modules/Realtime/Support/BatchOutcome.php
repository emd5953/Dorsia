<?php

declare(strict_types=1);

namespace App\Modules\Realtime\Support;

use Illuminate\Container\Attributes\Singleton;

/**
 * Side channel from PublishOutboxBatch to the publisher loop: whether the last batch stopped on a
 * broadcast failure of a live row (so the loop backs off instead of hammering the broadcaster).
 */
#[Singleton]
final class BatchOutcome
{
    public bool $broadcastFailed = false;
}
