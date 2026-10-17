<?php

declare(strict_types=1);

use App\Modules\Realtime\Actions\PublishOutboxBatch;
use App\Modules\Realtime\Support\BatchOutcome;
use App\Modules\Realtime\Support\Pause;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('uses realtime config defaults, honors once and limit options, and registers prune scheduling', function (): void {
    config(['realtime.outbox.batch' => 7, 'realtime.outbox.sleep_ms' => 0]);
    $publisher = Mockery::mock(PublishOutboxBatch::class);
    $publisher->shouldReceive('handle')->once()->with(7)->andReturn(0);
    app()->instance(PublishOutboxBatch::class, $publisher);
    expect(Artisan::call('realtime:publish-outbox', ['--once' => true]))->toBe(0);

    $publisher = Mockery::mock(PublishOutboxBatch::class);
    $publisher->shouldReceive('handle')->once()->with(3)->andReturn(0);
    app()->instance(PublishOutboxBatch::class, $publisher);
    expect(Artisan::call('realtime:publish-outbox', ['--once' => true, '--limit' => 3]))->toBe(0)
        ->and(Artisan::all())->toHaveKey('realtime:prune-outbox');

    $scheduled = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains($event->command ?? '', 'realtime:prune-outbox'));
    expect($scheduled)->not->toBeNull()
        ->and($scheduled->expression)->toBe('0 5 * * *')
        ->and($scheduled->timezone)->toBe('UTC');
});

it('exits the publisher loop after SIGTERM is received during a batch', function (): void {
    if (! function_exists('pcntl_async_signals')) {
        test()->markTestSkipped('pcntl is unavailable');
    }
    $publisher = Mockery::mock(PublishOutboxBatch::class);
    $publisher->shouldReceive('handle')->once()->andReturnUsing(static function (): int {
        posix_kill(getmypid(), SIGTERM);

        return 0;
    });
    app()->instance(PublishOutboxBatch::class, $publisher);
    config(['realtime.outbox.sleep_ms' => 0]);

    expect(Artisan::call('realtime:publish-outbox'))->toBe(0);
});

it('reports a publisher failure and returns a failing status in once mode', function (): void {
    $publisher = Mockery::mock(PublishOutboxBatch::class);
    $publisher->shouldReceive('handle')->once()->andThrow(new RuntimeException('database unavailable'));
    app()->instance(PublishOutboxBatch::class, $publisher);

    expect(Artisan::call('realtime:publish-outbox', ['--once' => true]))->toBe(1);
});

/**
 * Runs the publisher loop against a scripted publisher. Script steps: 'throw' (DB error), 'fail'
 * (broadcast failure), 'ok', 'stop' (SIGTERM, then ok). Returns the recorded pauses and the DB
 * connection seen by each handle() call.
 *
 * @param  list<string>  $script
 * @return array{pauses: list<int>, connections: list<object>, calls: int}
 */
function runScriptedLoop(array $script): array
{
    $pause = new class extends Pause
    {
        /** @var list<int> */
        public array $pauses = [];

        public function seconds(int $seconds): void
        {
            $this->pauses[] = $seconds;
        }

        public function milliseconds(int $milliseconds): void {}
    };
    $publisher = new class($script) extends PublishOutboxBatch
    {
        /** @var list<object> */
        public array $connections = [];

        public int $calls = 0;

        /** @param list<string> $script */
        public function __construct(private array $script) {}

        public function handle(int $limit = 100): int
        {
            $this->connections[] = DB::connection();
            app(BatchOutcome::class)->broadcastFailed = false;
            $step = $this->script[$this->calls++] ?? 'stop';

            if ($step === 'throw') {
                throw new RuntimeException('database unavailable');
            }
            if ($step === 'fail') {
                app(BatchOutcome::class)->broadcastFailed = true;
            }
            if ($step === 'stop') {
                posix_kill(getmypid(), SIGTERM);
            }

            return 0;
        }
    };
    app()->instance(Pause::class, $pause);
    app()->instance(PublishOutboxBatch::class, $publisher);

    expect(Artisan::call('realtime:publish-outbox'))->toBe(0);

    return ['pauses' => $pause->pauses, 'connections' => $publisher->connections, 'calls' => $publisher->calls];
}

it('recovers the loop after DB errors with a growing, capped, resetting backoff and a fresh connection', function (): void {
    if (! function_exists('pcntl_async_signals')) {
        test()->markTestSkipped('pcntl is unavailable');
    }
    config(['realtime.outbox.sleep_ms' => 0]);

    $run = runScriptedLoop(['throw', 'throw', 'throw', 'throw', 'throw', 'throw', 'throw', 'ok', 'throw', 'stop']);

    expect($run['pauses'])->toBe([1, 2, 4, 8, 16, 30, 30, 1])
        ->and($run['calls'])->toBe(10);
    // Every call after an error runs on a purged-and-reconnected connection (new object).
    foreach ([1, 2, 3, 4, 5, 6, 7, 9] as $callIndex) {
        expect($run['connections'][$callIndex])->not->toBe($run['connections'][$callIndex - 1]);
    }
});

it('backs off on broadcast failures like DB errors instead of the idle sleep, without purging the connection', function (): void {
    if (! function_exists('pcntl_async_signals')) {
        test()->markTestSkipped('pcntl is unavailable');
    }
    config(['realtime.outbox.sleep_ms' => 100]);

    $run = runScriptedLoop(['fail', 'fail', 'fail', 'ok', 'fail', 'stop']);

    expect($run['pauses'])->toBe([1, 2, 4, 1])
        ->and($run['calls'])->toBe(6);
    foreach (range(1, 5) as $callIndex) {
        expect($run['connections'][$callIndex])->toBe($run['connections'][0]);
    }
});

it('returns a failing status in once mode when the batch stopped on a broadcast failure', function (): void {
    $publisher = Mockery::mock(PublishOutboxBatch::class);
    $publisher->shouldReceive('handle')->once()->andReturnUsing(static function (): int {
        app(BatchOutcome::class)->broadcastFailed = true;

        return 0;
    });
    app()->instance(PublishOutboxBatch::class, $publisher);

    expect(Artisan::call('realtime:publish-outbox', ['--once' => true]))->toBe(1);
});
