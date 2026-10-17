<?php

declare(strict_types=1);

use App\Modules\Pricing\Actions\PruneSearchEvents;
use App\Modules\Pricing\Models\SearchEvent;
use Carbon\CarbonImmutable;

it('deletes only rows created before the 90-day cutoff', function (): void {
    $now = CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC');

    SearchEvent::factory()->create(['created_at' => $now->subDays(90)->subSecond()]);   // deleted
    SearchEvent::factory()->create(['created_at' => $now->subDays(91)]);                // deleted
    $onBoundary = SearchEvent::factory()->create(['created_at' => $now->subDays(90)]);  // kept: not older than 90 days
    $recent = SearchEvent::factory()->create(['created_at' => $now->subDays(89)]);
    $fresh = SearchEvent::factory()->create(['created_at' => $now]);

    $deleted = app(PruneSearchEvents::class)->handle($now);

    expect($deleted)->toBe(2)
        ->and(SearchEvent::query()->orderBy('id')->pluck('id')->all())->toBe([$onBoundary->id, $recent->id, $fresh->id]);
});

it('prunes by created_at, not by the requested date', function (): void {
    $now = CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC');

    SearchEvent::factory()->create([
        'requested_date' => '2025-01-01',
        'requested_at' => '2025-01-01 20:00:00+00',
        'created_at' => $now->subDay(),
    ]);

    expect(app(PruneSearchEvents::class)->handle($now))->toBe(0);
});

it('honours the configured retention', function (): void {
    config(['pricing.search_retention_days' => 7]);
    $now = CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC');

    SearchEvent::factory()->create(['created_at' => $now->subDays(8)]);
    SearchEvent::factory()->create(['created_at' => $now->subDays(6)]);

    expect(app(PruneSearchEvents::class)->handle($now))->toBe(1)->and(SearchEvent::query()->count())->toBe(1);
});

it('returns zero when nothing is old enough', function (): void {
    expect(app(PruneSearchEvents::class)->handle(CarbonImmutable::now()))->toBe(0);
});
