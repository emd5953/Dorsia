<?php

declare(strict_types=1);

namespace App\Modules\Realtime\Console;

use App\Modules\Realtime\Actions\PublishOutboxBatch;
use Illuminate\Console\Command;

final class PublishOutboxCommand extends Command
{
    protected $signature = 'realtime:publish-outbox {--once} {--limit=100} {--sleep-ms=100}';

    protected $description = 'Publish committed outbox events to Reverb';

    private bool $stopping = false;

    public function handle(PublishOutboxBatch $publisher): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $sleepMs = max(0, (int) $this->option('sleep-ms'));

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn (): bool => $this->stopping = true);
            pcntl_signal(SIGINT, fn (): bool => $this->stopping = true);
        }

        while (true) {
            if ($this->stopping) {
                break;
            }

            $count = $publisher->handle($limit);

            if ($this->option('once')) {
                break;
            }

            if ($this->isStopping()) {
                break;
            }

            if ($count === 0 && $sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        return self::SUCCESS;
    }

    private function isStopping(): bool
    {
        return $this->stopping;
    }
}
