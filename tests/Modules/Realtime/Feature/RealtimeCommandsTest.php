<?php

declare(strict_types=1);

use App\Modules\Realtime\Actions\PublishOutboxBatch;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

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
