<?php

declare(strict_types=1);

use App\Modules\Realtime\Actions\PublishOutboxBatch;
use App\Modules\Realtime\Support\BatchOutcome;
use App\Modules\Realtime\Support\OutboxBroadcast;
use App\Shared\Outbox\Outbox;
use App\Shared\Outbox\OutboxEvent;
use App\Shared\Outbox\OutboxTopic;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\Modules\Realtime\Support\ContractPayloads;

it('publishes in id order with payload plus outbox id, then is idempotent', function (): void {
    $cases = [];
    foreach (OutboxTopic::cases() as $topic) {
        $cases[] = [$topic, ContractPayloads::for($topic)];
    }
    $expectedRouting = [
        ['venue.2.slots', 'slot.held'], ['venue.2.slots', 'slot.released'], ['venue.2.slots', 'slot.booked'],
        ['drop.3', 'drop.opened'], ['drop.3', 'drop.sold_out'],
        ['private-member.5', 'hold.expiring'], ['private-member.5', 'reservation.confirmed'], ['private-member.5', 'wallet.updated'],
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
    foreach ($cases as $index => [$topic, $payload]) {
        [$channel, $eventName] = $expectedRouting[$index];
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
        app(Outbox::class)->record(OutboxTopic::SlotHeld, [...ContractPayloads::for(OutboxTopic::SlotHeld), 'venue_id' => 9]);
    } finally {
        DB::rollBack();
    }

    expect(app(PublishOutboxBatch::class)->handle())->toBe(0);
    Event::assertNotDispatched(OutboxBroadcast::class);
});

it('selects pending rows with SKIP LOCKED', function (): void {
    $sql = null;
    DB::listen(static function ($query) use (&$sql): void {
        if (str_contains(strtolower($query->sql), 'outbox_events') && str_contains(strtolower($query->sql), 'for update')) {
            $sql = strtolower($query->sql);
        }
    });

    app(PublishOutboxBatch::class)->handle();

    expect($sql)->not->toBeNull()->toContain('skip locked');
});

it('stops at a failed row and increments attempts before retrying it first', function (): void {
    $first = OutboxEvent::query()->create(['topic' => OutboxTopic::SlotHeld->value, 'payload' => [...ContractPayloads::for(OutboxTopic::SlotHeld), 'venue_id' => 1], 'created_at' => now()]);
    $second = OutboxEvent::query()->create(['topic' => OutboxTopic::SlotHeld->value, 'payload' => [...ContractPayloads::for(OutboxTopic::SlotHeld), 'venue_id' => 2], 'created_at' => now()]);
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

it('dead-letters unmappable rows immediately and continues with later rows', function (): void {
    Log::spy();
    $bad = OutboxEvent::query()->create(['topic' => 'unknown.topic', 'payload' => [], 'created_at' => now()]);
    $badPayload = OutboxEvent::query()->create(['topic' => OutboxTopic::SlotHeld->value, 'payload' => [...ContractPayloads::for(OutboxTopic::SlotHeld), 'venue_id' => '5'], 'created_at' => now()]);
    $valid = OutboxEvent::query()->create(['topic' => OutboxTopic::SlotHeld->value, 'payload' => [...ContractPayloads::for(OutboxTopic::SlotHeld), 'private_note' => 'secret', 'internal_flag' => true], 'created_at' => now()]);
    Event::fake([OutboxBroadcast::class]);

    expect(app(PublishOutboxBatch::class)->handle())->toBe(1)
        ->and($bad->fresh()->attempts)->toBe(5)
        ->and($badPayload->fresh()->attempts)->toBe(5)
        ->and($valid->fresh()->published_at)->not->toBeNull();
    Event::assertDispatched(OutboxBroadcast::class, fn (OutboxBroadcast $event): bool => ! array_key_exists('private_note', $event->broadcastWith()));
    Log::shouldHaveReceived('critical')->twice();
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $context['outbox_id'] === $valid->id
        && $context['dropped_keys'] === ['private_note', 'internal_flag']);
});

it('dead-letters a validly mapped row after five broadcast failures and proceeds', function (): void {
    Log::spy();
    $failed = OutboxEvent::query()->create(['topic' => OutboxTopic::SlotHeld->value, 'payload' => [...ContractPayloads::for(OutboxTopic::SlotHeld), 'venue_id' => 1], 'created_at' => now(), 'attempts' => 4]);
    $later = OutboxEvent::query()->create(['topic' => OutboxTopic::SlotHeld->value, 'payload' => [...ContractPayloads::for(OutboxTopic::SlotHeld), 'venue_id' => 2], 'created_at' => now()]);
    config(['broadcasting.default' => 'reverb']);
    $factory = Mockery::mock(BroadcastFactory::class);
    $factory->shouldReceive('queue')->twice()->andReturnUsing(static function () {
        static $calls = 0;
        if (++$calls === 1) {
            throw new RuntimeException('temporary failure');
        }

        return null;
    });
    app()->instance(BroadcastFactory::class, $factory);

    expect(app(PublishOutboxBatch::class)->handle())->toBe(1)
        ->and($failed->fresh()->attempts)->toBe(5)
        ->and($later->fresh()->published_at)->not->toBeNull();
    Log::shouldHaveReceived('critical')->once();
});

it('dead-letters a row missing any required key on the first attempt and reports it once', function (): void {
    Log::spy();
    Event::fake([OutboxBroadcast::class]);
    $rows = [];

    foreach (OutboxTopic::cases() as $topic) {
        foreach (array_keys(ContractPayloads::for($topic)) as $missing) {
            $payload = ContractPayloads::for($topic);
            unset($payload[$missing]);
            $rows[] = [OutboxEvent::query()->create(['topic' => $topic->value, 'payload' => $payload, 'created_at' => now()]), $missing];
        }
    }

    expect(app(PublishOutboxBatch::class)->handle(1000))->toBe(0)
        ->and(app(PublishOutboxBatch::class)->handle(1000))->toBe(0);

    foreach ($rows as [$row, $missing]) {
        $fresh = $row->fresh();
        expect($fresh->attempts)->toBe(5)
            ->and($fresh->published_at)->toBeNull()
            ->and($fresh->last_error)->toContain("missing {$missing}");
    }
    Event::assertNotDispatched(OutboxBroadcast::class);
    Log::shouldHaveReceived('critical')->times(count($rows));
});

it('dead-letters a row with a wrongly typed required key on the first attempt and reports it once', function (): void {
    Log::spy();
    Event::fake([OutboxBroadcast::class]);
    $rows = [];

    foreach (OutboxTopic::cases() as $topic) {
        [$key, $wrong] = ContractPayloads::wrongType($topic);
        $rows[] = [OutboxEvent::query()->create(['topic' => $topic->value, 'payload' => [...ContractPayloads::for($topic), $key => $wrong], 'created_at' => now()]), $key];
    }

    expect(app(PublishOutboxBatch::class)->handle())->toBe(0)
        ->and(app(PublishOutboxBatch::class)->handle())->toBe(0);

    foreach ($rows as [$row, $key]) {
        expect($row->fresh()->attempts)->toBe(5)
            ->and($row->fresh()->last_error)->toContain("invalid {$key}");
    }
    Event::assertNotDispatched(OutboxBroadcast::class);
    Log::shouldHaveReceived('critical')->times(count($rows));
});

it('rejects wrong-typed id, array and timestamp variants of the shape', function (): void {
    Event::fake([OutboxBroadcast::class]);
    $variants = [
        ['drop_id', '3'],
        ['venue_id', 0],
        ['slot_ids', null],
        ['slot_ids', ['a' => 1]],
        ['opens_at', '2026-10-17T18:00:00+00:00'],
        ['opens_at', '2026-10-17T18:00:00.123Z'],
        ['opens_at', '2026-02-31T18:00:00Z'],
    ];
    $rows = [];
    foreach ($variants as [$key, $wrong]) {
        $rows[] = OutboxEvent::query()->create(['topic' => OutboxTopic::DropOpened->value, 'payload' => [...ContractPayloads::for(OutboxTopic::DropOpened), $key => $wrong], 'created_at' => now()]);
    }

    expect(app(PublishOutboxBatch::class)->handle())->toBe(0);
    foreach ($rows as $row) {
        expect($row->fresh()->attempts)->toBe(5);
    }
});

it('keeps slot drop_id nullable and accepts an integer drop id', function (): void {
    Event::fake([OutboxBroadcast::class]);
    OutboxEvent::query()->create(['topic' => OutboxTopic::SlotHeld->value, 'payload' => ContractPayloads::for(OutboxTopic::SlotHeld), 'created_at' => now()]);
    OutboxEvent::query()->create(['topic' => OutboxTopic::SlotHeld->value, 'payload' => [...ContractPayloads::for(OutboxTopic::SlotHeld), 'drop_id' => 7], 'created_at' => now()]);

    expect(app(PublishOutboxBatch::class)->handle())->toBe(2);
});

it('excludes dead-lettered rows from later batches and reports them once', function (): void {
    Log::spy();
    $max = (int) config('realtime.outbox.max_attempts');
    $invalid = OutboxEvent::query()->create(['topic' => OutboxTopic::SlotHeld->value, 'payload' => ['venue_id' => 2], 'created_at' => now()]);
    $atLimit = OutboxEvent::query()->create(['topic' => OutboxTopic::SlotHeld->value, 'payload' => ContractPayloads::for(OutboxTopic::SlotHeld), 'created_at' => now(), 'attempts' => $max]);
    $beyondLimit = OutboxEvent::query()->create(['topic' => OutboxTopic::SlotHeld->value, 'payload' => ContractPayloads::for(OutboxTopic::SlotHeld), 'created_at' => now(), 'attempts' => $max + 1]);
    $live = OutboxEvent::query()->create(['topic' => OutboxTopic::SlotHeld->value, 'payload' => ContractPayloads::for(OutboxTopic::SlotHeld), 'created_at' => now()]);
    Event::fake([OutboxBroadcast::class]);

    expect(app(PublishOutboxBatch::class)->handle())->toBe(1)
        ->and(app(PublishOutboxBatch::class)->handle())->toBe(0);

    Event::assertDispatchedTimes(OutboxBroadcast::class, 1);
    Event::assertDispatched(OutboxBroadcast::class, fn (OutboxBroadcast $event): bool => $event->broadcastWith()['outbox_id'] === $live->id);
    expect($invalid->fresh()->attempts)->toBe($max)
        ->and($atLimit->fresh()->published_at)->toBeNull()
        ->and($beyondLimit->fresh()->published_at)->toBeNull();
    Log::shouldHaveReceived('critical')->once();
});

it('flags a run that stopped on a broadcast failure and clears the flag on the next run', function (): void {
    OutboxEvent::query()->create(['topic' => OutboxTopic::SlotHeld->value, 'payload' => ContractPayloads::for(OutboxTopic::SlotHeld), 'created_at' => now()]);
    config(['broadcasting.default' => 'reverb']);
    $failing = Mockery::mock(BroadcastFactory::class);
    $failing->shouldReceive('queue')->once()->andThrow(new RuntimeException('reverb down'));
    app()->instance(BroadcastFactory::class, $failing);
    $publisher = app(PublishOutboxBatch::class);
    $outcome = app(BatchOutcome::class);

    expect($publisher->handle())->toBe(0)
        ->and($outcome->broadcastFailed)->toBeTrue();

    $healthy = Mockery::mock(BroadcastFactory::class);
    $healthy->shouldReceive('queue')->once();
    app()->instance(BroadcastFactory::class, $healthy);

    expect($publisher->handle())->toBe(1)
        ->and($outcome->broadcastFailed)->toBeFalse();
});

it('logs dead-letter criticals only after the transaction commits', function (): void {
    $row = OutboxEvent::query()->create(['topic' => OutboxTopic::SlotHeld->value, 'payload' => ['venue_id' => 2], 'created_at' => now()]);
    $insideTransaction = [];
    Log::spy();
    Log::shouldReceive('critical')->andReturnUsing(function () use (&$insideTransaction): void {
        $insideTransaction[] = DB::transactionLevel();
    });

    app(PublishOutboxBatch::class)->handle();

    expect($insideTransaction)->toBe([DB::transactionLevel()])
        ->and($row->fresh()->attempts)->toBe(5);
});
