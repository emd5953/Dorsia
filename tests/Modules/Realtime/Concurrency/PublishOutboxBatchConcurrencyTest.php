<?php

declare(strict_types=1);

use App\Modules\Realtime\Actions\PublishOutboxBatch;
use App\Modules\Realtime\Support\OutboxBroadcast;
use App\Shared\Outbox\OutboxEvent;
use App\Shared\Outbox\OutboxTopic;
use Illuminate\Support\Facades\Event;
use Tests\Modules\Realtime\Support\ContractPayloads;
use Tests\Support\Concurrency;

it('safely partitions 500 rows among twenty simultaneous publishers', function (): void {
    for ($i = 0; $i < 500; $i++) {
        OutboxEvent::query()->create([
            'topic' => OutboxTopic::SlotHeld->value,
            'payload' => [...ContractPayloads::for(OutboxTopic::SlotHeld), 'slot_id' => $i + 1],
            'created_at' => now(),
        ]);
    }

    $workers = array_fill(0, 20, static function (): array {
        Event::fake([OutboxBroadcast::class]);

        app(PublishOutboxBatch::class)->handle(100);

        return Event::dispatched(OutboxBroadcast::class)
            ->map(static fn (array $dispatch): int => $dispatch[0]->broadcastWith()['outbox_id'])
            ->values()
            ->all();
    });

    $dispatchedIds = array_merge(...Concurrency::run($workers));
    $dispatchesPerId = array_count_values($dispatchedIds);
    $rowIds = OutboxEvent::query()->orderBy('id')->pluck('id')->all();

    expect($rowIds)->toHaveCount(500)
        ->and(array_keys($dispatchesPerId))->toEqualCanonicalizing($rowIds)
        ->and(array_unique(array_values($dispatchesPerId)))->toBe([1])
        ->and(OutboxEvent::query()->whereNotNull('published_at')->count())->toBe(500)
        ->and(OutboxEvent::query()->where('attempts', '>', 0)->count())->toBe(0);
});
