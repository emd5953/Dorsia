<?php

declare(strict_types=1);

use App\Modules\Realtime\Actions\PublishOutboxBatch;
use App\Modules\Realtime\Support\OutboxBroadcast;
use App\Shared\Outbox\OutboxEvent;
use App\Shared\Outbox\OutboxTopic;
use Illuminate\Support\Facades\Event;
use Tests\Support\Concurrency;

it('safely partitions 500 rows among twenty simultaneous publishers', function (): void {
    for ($i = 0; $i < 500; $i++) {
        OutboxEvent::query()->create([
            'topic' => OutboxTopic::SlotHeld->value,
            'payload' => ['venue_id' => 1, 'slot_id' => $i + 1],
            'created_at' => now(),
        ]);
    }

    $workers = array_fill(0, 20, static function (): int {
        Event::fake([OutboxBroadcast::class]);

        return app(PublishOutboxBatch::class)->handle(100);
    });

    $counts = Concurrency::run($workers);

    expect(array_sum($counts))->toBe(500)
        ->and(OutboxEvent::query()->whereNotNull('published_at')->count())->toBe(500)
        ->and(OutboxEvent::query()->where('attempts', '>', 0)->count())->toBe(0);
});
