<?php

declare(strict_types=1);

use App\Modules\Inventory\Actions\HoldSlot;
use App\Modules\Inventory\Data\HoldData;
use App\Modules\Inventory\Data\HoldSlotInput;
use App\Modules\Inventory\Exceptions\ActiveHoldExistsException;
use App\Modules\Inventory\Exceptions\DropNotOpenException;
use App\Modules\Inventory\Exceptions\PartySizeNotAllowedException;
use App\Modules\Inventory\Exceptions\SlotNotFoundException;
use App\Modules\Inventory\Exceptions\SlotUnavailableException;
use App\Modules\Inventory\Exceptions\SoldOutException;
use App\Modules\Inventory\Models\Hold;
use App\Modules\Inventory\Models\Slot;
use App\Modules\Inventory\Models\Venue;
use App\Modules\Inventory\Support\SlotGate;
use App\Shared\Outbox\OutboxEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\Modules\Inventory\Support\Fixtures;

it('creates a hold, bumps held_count and writes one slot.held outbox row', function (): void {
    $slot = Fixtures::slot(['capacity' => 4]);
    $member = Fixtures::member();

    $hold = Fixtures::hold($slot, $member, 2);

    expect($hold->status->value)->toBe('active')
        ->and($hold->memberId)->toBe($member->id)
        ->and($hold->partySize)->toBe(2)
        ->and($hold->slot->heldCount)->toBe(1)
        ->and($hold->expiresAt->equalTo($hold->createdAt->addSeconds(300)))->toBeTrue()
        ->and($slot->fresh()->held_count)->toBe(1)
        ->and(Hold::query()->count())->toBe(1);

    $events = Fixtures::outbox('slot.held');
    expect($events)->toHaveCount(1);

    $payload = $events->first()->payload;
    expect($payload['slot_id'])->toBe($slot->id)
        ->and($payload['venue_id'])->toBe($slot->venue_id)
        ->and($payload['drop_id'])->toBeNull()
        ->and($payload['starts_at'])->toBe($slot->starts_at->utc()->format('Y-m-d\TH:i:s\Z'))
        ->and($payload['capacity'])->toBe(4)
        ->and($payload['available'])->toBe(3)
        ->and($payload['occurred_at'])->toBe(now()->utc()->format('Y-m-d\TH:i:s\Z'))
        ->and(collect($payload)->keys()->sort()->values()->all())->toBe(['available', 'capacity', 'drop_id', 'occurred_at', 'slot_id', 'starts_at', 'venue_id']);
});

it('reports available from the same statement as the count change, hold after hold', function (): void {
    $slot = Fixtures::slot(['capacity' => 3, 'booked_count' => 1]);

    Fixtures::hold($slot);
    Fixtures::hold($slot);

    expect(Fixtures::outbox('slot.held')->pluck('payload.available')->all())->toBe([1, 0]);
});

it('creates the gate key capacity - held - booked on the first hold, with a TTL to starts_at + 1 day', function (): void {
    $slot = Fixtures::slot(['capacity' => 5, 'booked_count' => 1]);

    expect(Fixtures::gate($slot))->toBeNull();

    Fixtures::hold($slot);

    expect(Fixtures::gate($slot))->toBe(3);

    $ttl = (int) Redis::connection()->command('ttl', ["slot_gate:{$slot->id}"]);
    $expected = (int) now()->diffInSeconds($slot->starts_at->addDay());
    expect($ttl)->toBeBetween($expected - 5, $expected + 1);
});

it('rejects with sold_out and no database write when the gate is warm and capacity is reached', function (): void {
    $slot = Fixtures::slot(['capacity' => 1]);
    Fixtures::hold($slot);

    $holds = Hold::query()->count();
    $outbox = OutboxEvent::query()->count();
    $loser = Fixtures::member();

    DB::enableQueryLog();
    $thrown = null;

    try {
        Fixtures::hold($slot, $loser);
    } catch (SoldOutException $e) {
        $thrown = $e;
    }

    $queries = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    expect($thrown)->toBeInstanceOf(SoldOutException::class)
        ->and($queries)->not->toBeEmpty()
        ->and(array_filter($queries, fn (string $q): bool => ! str_starts_with(strtolower(ltrim($q)), 'select')))->toBe([])
        ->and(Hold::query()->count())->toBe($holds)
        ->and(OutboxEvent::query()->count())->toBe($outbox)
        ->and($slot->fresh()->held_count)->toBe(1)
        ->and(Fixtures::gate($slot))->toBe(0);
});

it('sells out when capacity equals booked and rebuilds a missing gate to 0 without creating a hold', function (): void {
    $slot = Fixtures::slot(['capacity' => 2, 'booked_count' => 2]);

    expect(fn () => Fixtures::hold($slot))->toThrow(SoldOutException::class);

    expect(Fixtures::gate($slot))->toBe(0)
        ->and(Hold::query()->count())->toBe(0)
        ->and($slot->fresh()->held_count)->toBe(0);
});

it('takes the last table at capacity - 1 and refuses the next one', function (): void {
    $slot = Fixtures::slot(['capacity' => 3, 'booked_count' => 1, 'held_count' => 1]);

    Fixtures::hold($slot);

    expect($slot->fresh()->held_count)->toBe(2)
        ->and(fn () => Fixtures::hold($slot))->toThrow(SoldOutException::class);
});

it('rebuilds a missing gate from Postgres and still holds', function (): void {
    $slot = Fixtures::slot(['capacity' => 5, 'held_count' => 1, 'booked_count' => 1]);
    Redis::connection()->flushdb();

    $hold = Fixtures::hold($slot);

    expect($hold->status->value)->toBe('active')
        ->and(Fixtures::gate($slot))->toBe(2);
});

it('answers sold_out when the gate is too high but Postgres is full, then rebuilds the gate to 0', function (): void {
    $slot = Fixtures::slot(['capacity' => 2, 'held_count' => 1, 'booked_count' => 1]);
    Redis::connection()->command('set', ["slot_gate:{$slot->id}", 99]);

    expect(fn () => Fixtures::hold($slot))->toThrow(SoldOutException::class);

    expect($slot->fresh()->held_count)->toBe(1)
        ->and(Hold::query()->count())->toBe(0)
        ->and(OutboxEvent::query()->count())->toBe(0)
        ->and(Fixtures::gate($slot))->toBe(0);
});

it('refreshes a too-low gate once and succeeds without waiting for the sweeper', function (): void {
    $slot = Fixtures::slot(['capacity' => 3]);
    Redis::connection()->command('set', ["slot_gate:{$slot->id}", 0]);

    expect(Fixtures::hold($slot)->status->value)->toBe('active')
        ->and(Fixtures::gate($slot))->toBe(2)
        ->and(Redis::connection()->command('exists', ["slot_gate:{$slot->id}:refresh"]))->toBe(1);
});

it('rejects a party size outside the range without consuming a gate unit', function (int $party): void {
    $slot = Fixtures::slot(['capacity' => 2, 'min_party_size' => 2, 'max_party_size' => 4]);
    Redis::connection()->command('set', ["slot_gate:{$slot->id}", 2]);

    try {
        Fixtures::hold($slot, null, $party);
        $this->fail('expected PartySizeNotAllowedException');
    } catch (PartySizeNotAllowedException $e) {
        expect($e->details())->toBe(['min_party_size' => 2, 'max_party_size' => 4])
            ->and($e->httpStatus())->toBe(422)
            ->and($e->errorCode())->toBe('party_size_not_allowed');
    }

    expect(Fixtures::gate($slot))->toBe(2)
        ->and(Hold::query()->count())->toBe(0);
})->with([1, 5]);

it('accepts a party size exactly at the minimum and at the maximum', function (int $party): void {
    $slot = Fixtures::slot(['capacity' => 2, 'min_party_size' => 2, 'max_party_size' => 4]);

    expect(Fixtures::hold($slot, null, $party)->partySize)->toBe($party);
})->with([2, 4]);

it('refuses a slot that already started and a slot in the past', function (): void {
    $slot = Fixtures::slot(['starts_at' => now()->subMinute(), 'released_at' => now()->subDay()]);

    expect(fn () => Fixtures::hold($slot))->toThrow(SlotUnavailableException::class);
});

it('refuses at exactly starts_at and accepts one microsecond earlier', function (): void {
    $slot = Fixtures::slot(['starts_at' => CarbonImmutable::parse('2026-12-01 20:00:00')]);

    $this->travelTo($slot->starts_at->subMicrosecond());
    expect(Fixtures::hold($slot)->status->value)->toBe('active');

    $this->travelTo($slot->starts_at);
    expect(fn () => Fixtures::hold($slot))->toThrow(SlotUnavailableException::class);
});

it('refuses an inactive venue', function (): void {
    $slot = Fixtures::slot([], Venue::factory()->inactive()->create());

    expect(fn () => Fixtures::hold($slot))->toThrow(SlotUnavailableException::class);
});

it('refuses a slot of an unopened drop with drop_not_open and a plain unreleased slot with slot_unavailable', function (): void {
    $venue = Venue::factory()->create();
    $drop = Fixtures::drop($venue);
    $dropSlot = Fixtures::slot(['drop_id' => $drop->id, 'released_at' => now()->addHour()], $venue);
    $plain = Fixtures::slot(['released_at' => now()->addHour(), 'min_party_size' => 3, 'max_party_size' => 4], $venue);

    expect(fn () => Fixtures::hold($dropSlot))->toThrow(DropNotOpenException::class)
        ->and(fn () => Fixtures::hold($plain, null, 3))->toThrow(SlotUnavailableException::class)
        ->and(Hold::query()->count())->toBe(0);
});

it('accepts at exactly released_at and refuses one microsecond before', function (): void {
    $venue = Venue::factory()->create();
    $releasedAt = CarbonImmutable::parse('2026-12-01 12:00:00');
    $drop = Fixtures::drop($venue, ['opens_at' => $releasedAt]);
    $slot = Fixtures::slot(['drop_id' => $drop->id, 'released_at' => $releasedAt, 'starts_at' => $releasedAt->addDays(2)], $venue);

    $this->travelTo($releasedAt->subMicrosecond());
    expect(fn () => Fixtures::hold($slot))->toThrow(DropNotOpenException::class);

    $this->travelTo($releasedAt);
    expect(Fixtures::hold($slot)->status->value)->toBe('active');
});

it('throws SlotNotFoundException for an unknown slot', function (): void {
    expect(fn () => app(HoldSlot::class)->handle(new HoldSlotInput(Fixtures::member()->id, 999_999, 2, 'k')))
        ->toThrow(SlotNotFoundException::class);
});

it('validates before touching the gate: an unavailable slot leaves no gate key', function (): void {
    $slot = Fixtures::slot(['starts_at' => now()->subMinute()]);

    try {
        Fixtures::hold($slot);
    } catch (SlotUnavailableException) {
        // expected
    }

    expect(Fixtures::gate($slot))->toBeNull();
});

it('refuses a second active hold of the same member and returns the gate unit', function (): void {
    $slot = Fixtures::slot(['capacity' => 4]);
    $member = Fixtures::member();
    Fixtures::hold($slot, $member);

    expect(Fixtures::gate($slot))->toBe(3);
    expect(fn () => Fixtures::hold($slot, $member))->toThrow(ActiveHoldExistsException::class);

    expect(Fixtures::gate($slot))->toBe(3)
        ->and($slot->fresh()->held_count)->toBe(1)
        ->and(Hold::query()->count())->toBe(1)
        ->and(OutboxEvent::query()->count())->toBe(1);
});

it('replays a hold by idempotency key without a second hold, outbox row or gate unit, and skips the callback', function (): void {
    $slot = Fixtures::slot(['capacity' => 4]);
    $member = Fixtures::member();
    $first = Fixtures::hold($slot, $member, 2, 'key-1');
    $calls = 0;

    $second = app(HoldSlot::class)->handle(
        new HoldSlotInput($member->id, $slot->id, 2, 'key-1'),
        function () use (&$calls): void {
            $calls++;
        },
    );

    expect($second->id)->toBe($first->id)
        ->and($second->expiresAt->equalTo($first->expiresAt))->toBeTrue()
        ->and($calls)->toBe(0)
        ->and(Hold::query()->count())->toBe(1)
        ->and(OutboxEvent::query()->count())->toBe(1)
        ->and(Fixtures::gate($slot))->toBe(3)
        ->and($slot->fresh()->held_count)->toBe(1);
});

it('runs the in-transaction callback with the hold and rolls everything back, releasing the gate unit, when it throws', function (): void {
    $slot = Fixtures::slot(['capacity' => 4]);
    $seen = null;

    $attempt = function () use ($slot, &$seen): void {
        app(HoldSlot::class)->handle(
            new HoldSlotInput(Fixtures::member()->id, $slot->id, 2, 'cb-key'),
            function (HoldData $hold) use (&$seen): void {
                $seen = $hold;

                throw new RuntimeException('quote failed');
            },
        );
    };

    expect($attempt)->toThrow(RuntimeException::class, 'quote failed');

    expect($seen)->toBeInstanceOf(HoldData::class)
        ->and($seen->slot->heldCount)->toBe(1)
        ->and($slot->fresh()->held_count)->toBe(0)
        ->and(Hold::query()->count())->toBe(0)
        ->and(OutboxEvent::query()->count())->toBe(0)
        ->and(Fixtures::gate($slot))->toBe(4);
});

it('drop-ins from overdue holds succeed from a warm gate at zero inside the refresh window', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-12-01 12:00:00'));
    $slot = Fixtures::slot(['capacity' => 2]);
    Fixtures::hold($slot);
    Fixtures::hold($slot);
    $this->travel(300)->seconds();

    Redis::connection()->command('set', ["slot_gate:{$slot->id}", 0]);

    $hold = Fixtures::hold($slot);

    expect($hold->status->value)->toBe('active')
        ->and(Hold::query()->where('status', 'expired')->count())->toBe(2)
        ->and($slot->fresh()->held_count)->toBe(1)
        ->and(Fixtures::outbox('slot.released'))->toHaveCount(2)
        ->and(Fixtures::outbox('slot.released')->first()->payload['reason'])->toBe('expired')
        ->and(Fixtures::gate($slot))->toBe(1);
});

it('rebuild expires overdue holds before reading slot counters', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-12-01 12:00:00'));
    $slot = Fixtures::slot(['capacity' => 1]);
    Fixtures::hold($slot);
    $this->travel(301)->seconds();
    Redis::connection()->command('del', ["slot_gate:{$slot->id}"]);

    expect(app(SlotGate::class)->rebuild($slot->id, force: true))->toBeTrue()
        ->and(Hold::query()->where('status', 'expired')->count())->toBe(1)
        ->and($slot->fresh()->held_count)->toBe(0)
        ->and(Fixtures::gate($slot))->toBe(1);
});

it('heals a gate unit abandoned by a crashed request after the refresh window', function (): void {
    $slot = Fixtures::slot(['capacity' => 2]);
    config(['inventory.gate.refresh_seconds' => 1]);
    Redis::connection()->command('set', ["slot_gate:{$slot->id}", 1]);
    app(SlotGate::class)->acquire($slot->id); // DECR, no DB transaction
    Redis::connection()->command('set', ["slot_gate:{$slot->id}:refresh", '1', ['NX', 'EX' => 1]]);
    usleep(1_100_000);

    expect(Fixtures::hold($slot)->status->value)->toBe('active')
        ->and(Fixtures::gate($slot))->toBe(1);
});

it('does not expire a hold one microsecond before expires_at when making room', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-12-01 12:00:00.500000'));
    $slot = Fixtures::slot(['capacity' => 1]);
    $first = Fixtures::hold($slot);

    $this->travelTo($first->expiresAt->subMicrosecond());
    Redis::connection()->command('set', ["slot_gate:{$slot->id}", 1]);

    expect(fn () => Fixtures::hold($slot))->toThrow(SoldOutException::class)
        ->and(Hold::query()->where('status', 'active')->count())->toBe(1);

    $this->travelTo($first->expiresAt);
    Redis::connection()->command('set', ["slot_gate:{$slot->id}", 1]);

    expect(Fixtures::hold($slot)->status->value)->toBe('active')
        ->and(Hold::query()->where('status', 'expired')->count())->toBe(1);
});

it('lets a member hold again after their own hold passed expires_at but was not swept', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-12-01 12:00:00'));
    $slot = Fixtures::slot(['capacity' => 4]);
    $member = Fixtures::member();
    $old = Fixtures::hold($slot, $member);

    $this->travel(301)->seconds();

    $new = Fixtures::hold($slot, $member);

    expect($new->id)->not->toBe($old->id)
        ->and(Hold::query()->find($old->id)?->status)->toBe('expired')
        ->and($slot->fresh()->held_count)->toBe(1);
});

it('stores expires_at and created_at with microsecond precision', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-12-01 12:00:00.250000'));
    $slot = Fixtures::slot();

    $hold = Fixtures::hold($slot);
    $row = Hold::query()->findOrFail($hold->id);

    expect($row->created_at->format('Y-m-d H:i:s.u'))->toBe('2026-12-01 12:00:00.250000')
        ->and($row->expires_at->format('Y-m-d H:i:s.u'))->toBe('2026-12-01 12:05:00.250000')
        ->and($hold->expiresAt->format('Y-m-d H:i:s.u'))->toBe('2026-12-01 12:05:00.250000');
});

it('keeps held_count + booked_count within capacity however many members ask', function (): void {
    $slot = Fixtures::slot(['capacity' => 3]);
    $granted = 0;

    for ($i = 0; $i < 8; $i++) {
        try {
            Fixtures::hold($slot);
            $granted++;
        } catch (SoldOutException) {
            // expected for the losers
        }
    }

    expect($granted)->toBe(3)
        ->and(Slot::query()->findOrFail($slot->id)->held_count)->toBe(3)
        ->and(Fixtures::gate($slot))->toBe(0);
});
