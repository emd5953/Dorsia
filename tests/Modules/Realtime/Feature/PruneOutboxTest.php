<?php

declare(strict_types=1);

use App\Modules\Realtime\Actions\PruneOutbox;
use App\Shared\Outbox\OutboxEvent;
use Carbon\CarbonImmutable;

it('prunes published rows strictly older than retention and keeps unpublished rows', function (): void {
    $now = CarbonImmutable::parse('2026-10-17T08:00:00.123456-04:00');
    $boundary = $now->subDays(7);
    $old = OutboxEvent::query()->create(['topic' => 'wallet.updated', 'payload' => [], 'created_at' => $now->subDays(8), 'published_at' => $boundary->subMicrosecond()]);
    $atBoundary = OutboxEvent::query()->create(['topic' => 'wallet.updated', 'payload' => [], 'created_at' => $now->subDays(8), 'published_at' => $boundary]);
    $unpublished = OutboxEvent::query()->create(['topic' => 'wallet.updated', 'payload' => [], 'created_at' => $now->subDays(20)]);

    expect(app(PruneOutbox::class)->handle($now))->toBe(1)
        ->and(OutboxEvent::query()->find($old->id))->toBeNull()
        ->and(OutboxEvent::query()->find($atBoundary->id))->not->toBeNull()
        ->and(OutboxEvent::query()->find($unpublished->id))->not->toBeNull();
});
