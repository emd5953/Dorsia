<?php

declare(strict_types=1);

use App\Modules\Realtime\Actions\PublishOutboxBatch;
use App\Modules\Realtime\Support\OutboxBroadcast;
use App\Shared\Outbox\Outbox;
use App\Shared\Outbox\OutboxEvent;
use App\Shared\Outbox\OutboxTopic;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

it('publishes in id order with payload plus outbox id, then is idempotent', function (): void {
    $cases = [
        [OutboxTopic::SlotHeld, ['slot_id' => 1, 'venue_id' => 2, 'drop_id' => null, 'starts_at' => '2026-10-17T18:00:00Z', 'capacity' => 10, 'available' => 9, 'occurred_at' => '2026-10-17T12:00:00Z'], 'venue.2.slots', 'slot.held'],
        [OutboxTopic::SlotReleased, ['slot_id' => 2, 'venue_id' => 2, 'drop_id' => null, 'starts_at' => '2026-10-17T18:00:00Z', 'capacity' => 10, 'available' => 9, 'occurred_at' => '2026-10-17T12:00:00Z', 'reason' => 'expired'], 'venue.2.slots', 'slot.released'],
        [OutboxTopic::SlotBooked, ['slot_id' => 3, 'venue_id' => 2, 'drop_id' => null, 'starts_at' => '2026-10-17T18:00:00Z', 'capacity' => 10, 'available' => 9, 'occurred_at' => '2026-10-17T12:00:00Z'], 'venue.2.slots', 'slot.booked'],
        [OutboxTopic::DropOpened, ['drop_id' => 3, 'venue_id' => 2, 'opens_at' => '2026-10-17T18:00:00Z', 'slot_ids' => [1, 2]], 'drop.3', 'drop.opened'],
        [OutboxTopic::DropSoldOut, ['drop_id' => 3, 'venue_id' => 2, 'sold_out_at' => '2026-10-17T18:00:00Z'], 'drop.3', 'drop.sold_out'],
        [OutboxTopic::HoldExpiring, ['hold_id' => 4, 'member_id' => 5, 'slot_id' => 1, 'expires_at' => '2026-10-17T18:00:00Z'], 'private-member.5', 'hold.expiring'],
        [OutboxTopic::ReservationConfirmed, ['reservation_id' => 6, 'member_id' => 5, 'slot_id' => 1, 'venue_id' => 2, 'starts_at' => '2026-10-17T18:00:00Z', 'party_size' => 2, 'min_spend_cents' => 5000, 'currency' => 'USD'], 'private-member.5', 'reservation.confirmed'],
        [OutboxTopic::WalletUpdated, ['member_id' => 5, 'currency' => 'USD', 'credit_cents' => 4, 'coupon_cents' => 0], 'private-member.5', 'wallet.updated'],
    ];
    $rows = [];
    foreach ($cases as [$topic, $payload]) {
        $rows[] = OutboxEvent::query()->create(['topic' => $topic->value, 'payload' => $payload, 'created_at' => now()]);
    }

    Event::fake([OutboxBroadcast::class]);
    expect(app(PublishOutboxBatch::class)->handle())->toBe(8)
        ->and(OutboxEvent::query()->whereNull('published_at')->count())->toBe(0)
        ->and(app(PublishOutboxBatch::class)->handle())->toBe(0);

    Event::assertDispatchedTimes(OutboxBroadcast::class, 8);
    $events = Event::dispatched(OutboxBroadcast::class)->values();
    foreach ($cases as $index => [$topic, $payload, $channel, $eventName]) {
        $broadcastPayload = $events[$index][0]->broadcastWith();
        $expectedPayload = [...$payload, 'outbox_id' => $rows[$index]->id];
        ksort($broadcastPayload);
        ksort($expectedPayload);

        expect($events[$index][0]->broadcastAs())->toBe($eventName)
            ->and($broadcastPayload)->toBe($expectedPayload)
            ->and($events[$index][0]->broadcastOn()[0]->name)->toBe($channel);
    }
});

it('does not publish an outbox row rolled back with its transaction', function (): void {
    Event::fake([OutboxBroadcast::class]);
    DB::beginTransaction();
    try {
        app(Outbox::class)->record(OutboxTopic::SlotHeld, ['venue_id' => 9, 'slot_id' => 1]);
    } finally {
        DB::rollBack();
    }

    expect(app(PublishOutboxBatch::class)->handle())->toBe(0);
    Event::assertNotDispatched(OutboxBroadcast::class);
});

it('stops at a failed row and increments attempts before retrying it first', function (): void {
    $first = OutboxEvent::query()->create(['topic' => OutboxTopic::SlotHeld->value, 'payload' => ['venue_id' => 1], 'created_at' => now()]);
    $second = OutboxEvent::query()->create(['topic' => OutboxTopic::SlotHeld->value, 'payload' => ['venue_id' => 2], 'created_at' => now()]);
    config(['broadcasting.default' => 'reverb']);
    $failedFactory = Mockery::mock(BroadcastFactory::class);
    $failedFactory->shouldReceive('queue')->once()->with(Mockery::type(OutboxBroadcast::class))->andThrow(new RuntimeException('temporary broadcaster failure'));
    app()->instance(BroadcastFactory::class, $failedFactory);

    expect(app(PublishOutboxBatch::class)->handle())->toBe(0)
        ->and($first->fresh()->attempts)->toBe(1)
        ->and($first->fresh()->last_error)->toBe('temporary broadcaster failure')
        ->and($second->fresh()->published_at)->toBeNull();

    $retried = [];
    $healthyFactory = Mockery::mock(BroadcastFactory::class);
    $healthyFactory->shouldReceive('queue')->twice()->with(Mockery::type(OutboxBroadcast::class))->andReturnUsing(
        function (OutboxBroadcast $event) use (&$retried): void {
            $retried[] = $event->broadcastWith()['venue_id'];
        },
    );
    app()->instance(BroadcastFactory::class, $healthyFactory);
    expect(app(PublishOutboxBatch::class)->handle())->toBe(2);
    expect($retried)->toBe([1, 2]);
});
