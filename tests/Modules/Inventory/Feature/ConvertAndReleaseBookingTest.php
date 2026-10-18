<?php

declare(strict_types=1);

use App\Modules\Inventory\Actions\ConvertHoldToBooking;
use App\Modules\Inventory\Actions\ReleaseBooking;
use App\Modules\Inventory\Exceptions\HoldExpiredException;
use App\Modules\Inventory\Exceptions\HoldNotActiveException;
use App\Modules\Inventory\Exceptions\HoldNotFoundException;
use App\Modules\Inventory\Exceptions\SlotNotFoundException;
use App\Modules\Inventory\Models\Drop;
use App\Modules\Inventory\Models\Hold;
use App\Modules\Inventory\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Modules\Inventory\Support\Fixtures;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-12-01 12:00:00.500000'));
});

function convert(int $holdId, int $memberId)
{
    return DB::transaction(fn () => app(ConvertHoldToBooking::class)->handle($holdId, $memberId));
}

it('converts a hold: held -1, booked +1, slot.booked outbox row, gate untouched', function (): void {
    $slot = Fixtures::slot(['capacity' => 3]);
    $hold = Fixtures::hold($slot);
    $gate = Fixtures::gate($slot);

    $data = convert($hold->id, $hold->memberId);

    $row = Hold::query()->findOrFail($hold->id);
    expect($row->status)->toBe('converted')
        ->and($row->close_reason)->toBe('converted')
        ->and($row->closed_at?->equalTo(now()))->toBeTrue()
        ->and($data->heldCount)->toBe(0)
        ->and($data->bookedCount)->toBe(1)
        ->and($data->soldOutAt)->toBeNull()
        ->and($slot->fresh()->booked_count)->toBe(1)
        ->and(Fixtures::gate($slot))->toBe($gate);

    $booked = Fixtures::outbox('slot.booked');
    expect($booked)->toHaveCount(1)
        ->and($booked->first()->payload['available'])->toBe(2)
        ->and($booked->first()->payload['slot_id'])->toBe($slot->id)
        ->and(Fixtures::outbox('drop.sold_out'))->toHaveCount(0);
});

it('sets slots.sold_out_at when booked reaches capacity and never clears it', function (): void {
    $slot = Fixtures::slot(['capacity' => 1]);
    $hold = Fixtures::hold($slot);

    $data = convert($hold->id, $hold->memberId);

    expect($data->soldOutAt?->equalTo(now()))->toBeTrue()
        ->and($data->available())->toBe(0);

    DB::transaction(fn () => app(ReleaseBooking::class)->handle($slot->id));

    expect($slot->fresh()->sold_out_at?->equalTo(CarbonImmutable::parse('2026-12-01 12:00:00.500000')))->toBeTrue();
});

it('does not set sold_out_at while held tables remain even if booked + held = capacity', function (): void {
    $slot = Fixtures::slot(['capacity' => 2]);
    $first = Fixtures::hold($slot);
    Fixtures::hold($slot);

    convert($first->id, $first->memberId);

    expect($slot->fresh()->sold_out_at)->toBeNull();
});

it('keeps the original sold_out_at when a later conversion fills the slot again', function (): void {
    $slot = Fixtures::slot(['capacity' => 1]);
    $first = Fixtures::hold($slot);
    convert($first->id, $first->memberId);
    $stamp = $slot->fresh()->sold_out_at;

    $this->travel(5)->minutes();
    DB::transaction(fn () => app(ReleaseBooking::class)->handle($slot->id));
    $second = Fixtures::hold($slot);
    convert($second->id, $second->memberId);

    expect($slot->fresh()->sold_out_at?->equalTo($stamp))->toBeTrue();
});

it('marks the drop sold out and writes drop.sold_out exactly once on the last slot', function (): void {
    $venue = Venue::factory()->create();
    $drop = Fixtures::drop($venue, ['opens_at' => now()->subHour()]);
    $a = Fixtures::slot(['drop_id' => $drop->id, 'capacity' => 1, 'min_party_size' => 1, 'max_party_size' => 2], $venue);
    $b = Fixtures::slot(['drop_id' => $drop->id, 'capacity' => 1, 'min_party_size' => 3, 'max_party_size' => 4], $venue);

    $holdA = Fixtures::hold($a);
    $holdB = Fixtures::hold($b, null, 3);

    convert($holdA->id, $holdA->memberId);
    expect(Drop::query()->findOrFail($drop->id)->sold_out_at)->toBeNull()
        ->and(Fixtures::outbox('drop.sold_out'))->toHaveCount(0);

    convert($holdB->id, $holdB->memberId);

    $stored = Drop::query()->findOrFail($drop->id);
    $events = Fixtures::outbox('drop.sold_out');
    expect($stored->sold_out_at?->equalTo(now()))->toBeTrue()
        ->and($events)->toHaveCount(1)
        ->and($events->first()->payload)->toEqual([
            'drop_id' => $drop->id,
            'venue_id' => $venue->id,
            'sold_out_at' => '2026-12-01T12:00:00Z',
        ]);
});

it('does not write drop.sold_out again for later bookings in an already sold-out drop', function (): void {
    $venue = Venue::factory()->create();
    $drop = Fixtures::drop($venue, ['opens_at' => now()->subHour()]);
    $slot = Fixtures::slot(['drop_id' => $drop->id, 'capacity' => 1], $venue);
    $first = Fixtures::hold($slot);
    convert($first->id, $first->memberId);

    DB::transaction(fn () => app(ReleaseBooking::class)->handle($slot->id));
    $second = Fixtures::hold($slot);
    convert($second->id, $second->memberId);

    expect(Fixtures::outbox('drop.sold_out'))->toHaveCount(1);
});

it('rejects an expired hold at expires_at exactly and accepts it one microsecond earlier', function (): void {
    $slot = Fixtures::slot(['capacity' => 2]);
    $hold = Fixtures::hold($slot);

    $this->travelTo($hold->expiresAt);
    expect(fn () => convert($hold->id, $hold->memberId))->toThrow(HoldExpiredException::class);
    expect($slot->fresh()->booked_count)->toBe(0)
        ->and(Hold::query()->findOrFail($hold->id)->status)->toBe('active');

    $this->travelTo($hold->expiresAt->subMicrosecond());
    expect(convert($hold->id, $hold->memberId)->bookedCount)->toBe(1);
});

it('rejects holds that are not active, with the status in the error details', function (string $status): void {
    $slot = Fixtures::slot(['capacity' => 2]);
    $hold = Fixtures::hold($slot);
    Hold::query()->whereKey($hold->id)->update(['status' => $status, 'closed_at' => now(), 'close_reason' => $status]);

    try {
        convert($hold->id, $hold->memberId);
        $this->fail('expected HoldNotActiveException');
    } catch (HoldNotActiveException $e) {
        expect($e->details())->toBe(['status' => $status])
            ->and($e->errorCode())->toBe('hold_not_active');
    }

    expect($slot->fresh()->booked_count)->toBe(0);
})->with(['converted', 'released', 'expired']);

it('rejects another member\'s hold and unknown holds as not found', function (): void {
    $hold = Fixtures::hold(Fixtures::slot());

    expect(fn () => convert($hold->id, Fixtures::member()->id))->toThrow(HoldNotFoundException::class)
        ->and(fn () => convert(999_999, $hold->memberId))->toThrow(HoldNotFoundException::class);
});

it('throws a LogicException outside a transaction', function (): void {
    $hold = Fixtures::hold(Fixtures::slot());

    expect(fn () => app(ConvertHoldToBooking::class)->handle($hold->id, $hold->memberId))->toThrow(LogicException::class);
});

it('ReleaseBooking decrements booked, writes slot.released with reason cancelled and returns the gate unit after commit', function (): void {
    $slot = Fixtures::slot(['capacity' => 2]);
    $hold = Fixtures::hold($slot);
    convert($hold->id, $hold->memberId);
    expect(Fixtures::gate($slot))->toBe(1);

    $data = DB::transaction(fn () => app(ReleaseBooking::class)->handle($slot->id));

    expect($data->bookedCount)->toBe(0)
        ->and($slot->fresh()->booked_count)->toBe(0)
        ->and(Fixtures::gate($slot))->toBe(2);

    $released = Fixtures::outbox('slot.released');
    expect($released)->toHaveCount(1)
        ->and($released->first()->payload['reason'])->toBe('cancelled')
        ->and($released->first()->payload['available'])->toBe(2);
});

it('ReleaseBooking leaves the gate unchanged and writes nothing when the transaction rolls back', function (): void {
    $slot = Fixtures::slot(['capacity' => 2]);
    $hold = Fixtures::hold($slot);
    convert($hold->id, $hold->memberId);
    $gate = Fixtures::gate($slot);

    try {
        DB::transaction(function () use ($slot): void {
            app(ReleaseBooking::class)->handle($slot->id);

            throw new RuntimeException('refund failed');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(Fixtures::gate($slot))->toBe($gate)
        ->and($slot->fresh()->booked_count)->toBe(1)
        ->and(Fixtures::outbox('slot.released'))->toHaveCount(0);
});

it('ReleaseBooking does not touch the gate before the surrounding transaction commits', function (): void {
    $slot = Fixtures::slot(['capacity' => 2]);
    $hold = Fixtures::hold($slot);
    convert($hold->id, $hold->memberId);

    DB::transaction(function () use ($slot): void {
        app(ReleaseBooking::class)->handle($slot->id);

        expect(Fixtures::gate($slot))->toBe(1);
    });

    expect(Fixtures::gate($slot))->toBe(2);
});

it('ReleaseBooking refuses outside a transaction, on unknown slots, and is inert when nothing is booked', function (): void {
    $slot = Fixtures::slot(['capacity' => 2]);
    Fixtures::hold($slot);

    expect(fn () => app(ReleaseBooking::class)->handle($slot->id))->toThrow(LogicException::class)
        ->and(fn () => DB::transaction(fn () => app(ReleaseBooking::class)->handle(999_999)))->toThrow(SlotNotFoundException::class);

    $gate = Fixtures::gate($slot);
    $data = DB::transaction(fn () => app(ReleaseBooking::class)->handle($slot->id));

    expect($data->bookedCount)->toBe(0)
        ->and(Fixtures::gate($slot))->toBe($gate)
        ->and(Fixtures::outbox('slot.released'))->toHaveCount(0);
});
