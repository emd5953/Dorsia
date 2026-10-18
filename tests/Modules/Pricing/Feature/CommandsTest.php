<?php

declare(strict_types=1);

use App\Modules\Inventory\Actions\GetSelloutStats;
use App\Modules\Pricing\Models\DemandSignal;
use App\Modules\Pricing\Models\SearchEvent;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;

/** @return list<ScheduledEvent> */
function scheduledFor(string $command): array
{
    return array_values(array_filter(
        app(Schedule::class)->events(),
        fn (ScheduledEvent $event): bool => str_contains((string) $event->command, $command),
    ));
}

it('schedules the signals job every 15 minutes without overlap on one server', function (): void {
    $events = scheduledFor('pricing:compute-demand-signals');

    expect($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe('*/15 * * * *')
        ->and($events[0]->withoutOverlapping)->toBeTrue()
        ->and($events[0]->onOneServer)->toBeTrue();
});

it('schedules pruning daily at 04:00 UTC without overlap on one server', function (): void {
    $events = scheduledFor('pricing:prune-search-events');

    expect($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe('0 4 * * *')
        ->and($events[0]->timezone)->toBe('UTC')
        ->and($events[0]->withoutOverlapping)->toBeTrue()
        ->and($events[0]->onOneServer)->toBeTrue();
});

it('runs the signals command', function (): void {
    $this->mock(GetSelloutStats::class, fn ($mock) => $mock->shouldReceive('handle')->andReturn([]));
    SearchEvent::factory()->create(['created_at' => now()->subDay()]);

    $this->artisan('pricing:compute-demand-signals')->assertSuccessful();

    expect(DemandSignal::query()->count())->toBe(3);
});

it('runs the prune command', function (): void {
    SearchEvent::factory()->create(['created_at' => now()->subDays(91)]);
    SearchEvent::factory()->create(['created_at' => now()->subDays(1)]);

    $this->artisan('pricing:prune-search-events')->assertSuccessful();

    expect(SearchEvent::query()->count())->toBe(1);
});
