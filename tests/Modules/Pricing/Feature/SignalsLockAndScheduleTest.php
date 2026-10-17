<?php

declare(strict_types=1);

use App\Modules\Inventory\Actions\GetSelloutStats;
use App\Modules\Pricing\Actions\ComputeDemandSignals;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

it('reads and writes under the advisory lock that serialises overlapping runs', function (): void {
    $held = [];

    $this->mock(GetSelloutStats::class, function ($mock) use (&$held): void {
        $mock->shouldReceive('handle')->andReturnUsing(function () use (&$held): array {
            $held[] = (int) DB::selectOne(
                "SELECT COUNT(*) AS c FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid()",
            )->c;

            return [];
        });
    });

    app(ComputeDemandSignals::class)->handle(CarbonImmutable::now());

    expect($held)->toBe([1]);
});

it('pins the prune schedule to UTC regardless of the app schedule timezone', function (): void {
    config(['app.schedule_timezone' => 'America/New_York']);
    app()->forgetInstance(Schedule::class);

    $find = fn (string $command): array => array_values(array_filter(
        app(Schedule::class)->events(),
        fn (ScheduledEvent $event): bool => str_contains((string) $event->command, $command),
    ));

    $prune = $find('pricing:prune-search-events');
    $compute = $find('pricing:compute-demand-signals');

    expect($prune)->toHaveCount(1)
        ->and($prune[0]->timezone)->toBe('UTC')
        ->and($prune[0]->expression)->toBe('0 4 * * *')
        ->and($compute[0]->timezone)->toBe('America/New_York');
});
