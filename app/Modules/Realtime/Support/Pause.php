<?php

declare(strict_types=1);

namespace App\Modules\Realtime\Support;

/**
 * Wall-clock waits for the publisher loop, replaceable in tests.
 */
class Pause
{
    public function seconds(int $seconds): void
    {
        sleep($seconds);
    }

    public function milliseconds(int $milliseconds): void
    {
        usleep($milliseconds * 1000);
    }
}
