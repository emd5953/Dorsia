<?php

declare(strict_types=1);

use App\Modules\Inventory\Models\Hold;
use App\Modules\Inventory\Support\SlotGate;
use Illuminate\Support\Facades\Redis;
use Tests\Modules\Inventory\Support\Fixtures;

it('expires the member\'s own overdue hold before taking a gate unit, so no refresh is needed', function (): void {
    $slot = Fixtures::slot(['capacity' => 1]);
    $member = Fixtures::member();
    Fixtures::hold($slot, $member);
    Hold::query()->update(['expires_at' => now()->subSecond()]);

    // Exact gate: the overdue hold still occupies the only table.
    expect(Fixtures::gate($slot))->toBe(0);

    $again = Fixtures::hold($slot, $member);

    // Had the unit been taken first, the gate (0) would have shed this request into the refresh path.
    expect(Redis::connection()->command('exists', ["slot_gate:{$slot->id}:refresh"]))->toBe(0)
        ->and($again->status->value)->toBe('active')
        ->and(Hold::query()->where('status', 'expired')->count())->toBe(1)
        ->and(Fixtures::outbox('slot.released'))->toHaveCount(1)
        ->and($slot->fresh()->held_count)->toBe(1)
        ->and(Fixtures::gate($slot))->toBe(0);
});

it('returns every expired overdue hold to the gate when Postgres rejects a too-high unit, so the gate never ends below truth', function (): void {
    $slot = Fixtures::slot(['capacity' => 2]);
    Fixtures::hold($slot);
    Fixtures::hold($slot);
    Hold::query()->update(['expires_at' => now()->subSecond()]);

    // Too high by one: the request gets a unit while both tables are still taken by unswept holds.
    Redis::connection()->command('set', ["slot_gate:{$slot->id}", 1]);

    Fixtures::hold($slot);

    $row = $slot->fresh();

    expect($row->held_count)->toBe(1)
        ->and(Hold::query()->where('status', 'expired')->count())->toBe(2)
        ->and(Fixtures::gate($slot))->toBeGreaterThanOrEqual($row->capacity - $row->held_count - $row->booked_count);
});

it('leaves a counter another process already built when a non-forced rebuild wins the lock (double-check)', function (): void {
    $slot = Fixtures::slot(['capacity' => 3]);
    Redis::connection()->command('set', ["slot_gate:{$slot->id}", 7]);

    expect(app(SlotGate::class)->rebuild($slot->id))->toBeTrue()
        ->and(Fixtures::gate($slot))->toBe(7);

    // A forced rebuild does overwrite it from Postgres.
    expect(app(SlotGate::class)->rebuild($slot->id, force: true))->toBeTrue()
        ->and(Fixtures::gate($slot))->toBe(3);
});
