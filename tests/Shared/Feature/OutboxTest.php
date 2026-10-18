<?php

declare(strict_types=1);

use App\Shared\Outbox\Outbox;
use App\Shared\Outbox\OutboxEvent;
use App\Shared\Outbox\OutboxTopic;
use Illuminate\Support\Facades\DB;

it('refuses to record outside a transaction', function (): void {
    expect(fn () => app(Outbox::class)->record(OutboxTopic::SlotHeld, ['slot_id' => 1]))
        ->toThrow(LogicException::class);

    expect(OutboxEvent::query()->count())->toBe(0);
});

it('leaves no row when the surrounding transaction rolls back', function (): void {
    try {
        DB::transaction(function (): void {
            app(Outbox::class)->record(OutboxTopic::SlotHeld, ['slot_id' => 1]);

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(OutboxEvent::query()->count())->toBe(0);
});

it('leaves exactly one row with the topic and payload when the transaction commits', function (): void {
    $payload = ['slot_id' => 42, 'venue_id' => 3, 'available' => 2, 'nested' => ['a' => [1, 2]]];

    DB::transaction(function () use ($payload): void {
        app(Outbox::class)->record(OutboxTopic::SlotBooked, $payload);
    });

    $event = OutboxEvent::query()->sole();

    expect($event->topic)->toBe('slot.booked')
        ->and($event->payload)->toEqual($payload)
        ->and($event->attempts)->toBe(0)
        ->and($event->last_error)->toBeNull()
        ->and($event->getRawOriginal('published_at'))->toBeNull();
});

it('keeps insertion order in the id column', function (): void {
    DB::transaction(function (): void {
        $outbox = app(Outbox::class);
        $outbox->record(OutboxTopic::SlotHeld, ['n' => 1]);
        $outbox->record(OutboxTopic::SlotReleased, ['n' => 2]);
    });

    expect(OutboxEvent::query()->orderBy('id')->pluck('topic')->all())->toBe(['slot.held', 'slot.released']);
});

it('has the contract topics', function (): void {
    expect(collect(OutboxTopic::cases())->mapWithKeys(fn (OutboxTopic $t) => [$t->name => $t->value])->all())->toBe([
        'SlotHeld' => 'slot.held',
        'SlotReleased' => 'slot.released',
        'SlotBooked' => 'slot.booked',
        'DropOpened' => 'drop.opened',
        'DropSoldOut' => 'drop.sold_out',
        'HoldExpiring' => 'hold.expiring',
        'ReservationConfirmed' => 'reservation.confirmed',
        'WalletUpdated' => 'wallet.updated',
    ]);
});
