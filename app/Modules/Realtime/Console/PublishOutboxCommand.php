<?php

declare(strict_types=1);

namespace App\Modules\Realtime\Console;

use App\Modules\Realtime\Actions\PublishOutboxBatch;
use App\Modules\Realtime\Support\BatchOutcome;
use App\Modules\Realtime\Support\Pause;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class PublishOutboxCommand extends Command
{
    protected $signature = 'realtime:publish-outbox {--once} {--limit=} {--sleep-ms=}';

    protected $description = 'Publish committed outbox events to Reverb';

    private bool $stopping = false;

    public function handle(PublishOutboxBatch $publisher, BatchOutcome $outcome, Pause $pause): int
    {
        $limit = max(1, (int) ($this->option('limit') ?? config('realtime.outbox.batch', 100)));
        $sleepMs = max(0, (int) ($this->option('sleep-ms') ?? config('realtime.outbox.sleep_ms', 100)));
        $backoffSeconds = 1;

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn (): bool => $this->stopping = true);
            pcntl_signal(SIGINT, fn (): bool => $this->stopping = true);
        }

        while (true) {
            if ($this->stopping) {
                break;
            }

            try {
                $count = $publisher->handle($limit);
            } catch (Throwable $exception) {
                report($exception);
                DB::purge();
                if ($this->option('once')) {
                    return self::FAILURE;
                }
                $pause->seconds($backoffSeconds);
                $backoffSeconds = min(30, $backoffSeconds * 2);

                continue;
            }

            if ($outcome->broadcastFailed) {
                // Broadcaster outage: back off like a DB error so live rows are not burned through.
                if ($this->option('once')) {
                    return self::FAILURE;
                }
                $pause->seconds($backoffSeconds);
                $backoffSeconds = min(30, $backoffSeconds * 2);

                continue;
            }

            $backoffSeconds = 1;

            if ($this->option('once')) {
                break;
            }

            if ($this->isStopping()) {
                break;
            }

            if ($count === 0 && $sleepMs > 0) {
                $pause->milliseconds($sleepMs);
            }
        }

        return self::SUCCESS;
    }

    private function isStopping(): bool
    {
        return $this->stopping;
    }
}
