<?php

declare(strict_types=1);

use App\Modules\Inventory\Models\Drop;
use App\Modules\Inventory\Models\Hold;
use App\Modules\Inventory\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Redis;
use Tests\Modules\Inventory\Support\Fixtures;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-12-01 12:00:00.250000'));
});

it('expires overdue holds without any read, and writes slot.released once per hold', function (): void {
    $slot = Fixtures::slot(['capacity' => 3]);
    $a = Fixtures::hold($slot);
    $b = Fixtures::hold($slot);

    $this->travelTo($a->expiresAt);
    $this->artisan('inventory:expire-holds')->assertSuccessful();

    expect(Hold::query()->where('status', 'expired')->count())->toBe(2)
        ->and($slot->fresh()->held_count)->toBe(0)
        ->and(Fixtures::gate($slot))->toBe(3)
        ->and(Fixtures::outbox('slot.released'))->toHaveCount(2)
        ->and(Fixtures::outbox('slot.released')->pluck('payload.reason')->unique()->all())->toBe(['expired']);

    $this->artisan('inventory:expire-holds')->assertSuccessful();

    expect(Fixtures::outbox('slot.released'))->toHaveCount(2)
        ->and(Fixtures::gate($slot))->toBe(3)
        ->and($b->id)->not->toBe($a->id);
});

it('leaves a hold alone one microsecond before its expiry', function (): void {
    $slot = Fixtures::slot();
    $hold = Fixtures::hold($slot);

    $this->travelTo($hold->expiresAt->subMicrosecond());
    $this->artisan('inventory:expire-holds')->assertSuccessful();

    expect(Hold::query()->findOrFail($hold->id)->status)->toBe('active')
        ->and(Fixtures::outbox('slot.released'))->toHaveCount(0);
});

it('writes hold.expiring exactly once per hold within 60 seconds of expiry', function (): void {
    $slot = Fixtures::slot();
    $hold = Fixtures::hold($slot);

    $this->travelTo($hold->expiresAt->subSeconds(61));
    $this->artisan('inventory:expire-holds')->assertSuccessful();
    expect(Fixtures::outbox('hold.expiring'))->toHaveCount(0);

    $this->travelTo($hold->expiresAt->subSeconds(60));
    $this->artisan('inventory:expire-holds')->assertSuccessful();

    $events = Fixtures::outbox('hold.expiring');
    expect($events)->toHaveCount(1)
        ->and($events->first()->payload)->toEqual([
            'hold_id' => $hold->id,
            'member_id' => $hold->memberId,
            'slot_id' => $slot->id,
            'expires_at' => $hold->expiresAt->utc()->format('Y-m-d\TH:i:s\Z'),
        ]);

    $this->travelTo($hold->expiresAt->subSeconds(10));
    $this->artisan('inventory:expire-holds')->assertSuccessful();

    expect(Fixtures::outbox('hold.expiring'))->toHaveCount(1)
        ->and(Hold::query()->findOrFail($hold->id)->expiring_notified_at)->not->toBeNull();
});

it('does not announce an expiry for a hold that expired, and expires it instead', function (): void {
    $hold = Fixtures::hold(Fixtures::slot());

    $this->travelTo($hold->expiresAt);
    $this->artisan('inventory:expire-holds')->assertSuccessful();

    expect(Fixtures::outbox('hold.expiring'))->toHaveCount(0)
        ->and(Hold::query()->findOrFail($hold->id)->status)->toBe('expired');
});

it('does not announce holds that are no longer active', function (): void {
    $hold = Fixtures::hold(Fixtures::slot());
    Hold::query()->whereKey($hold->id)->update(['status' => 'released', 'closed_at' => now(), 'close_reason' => 'payment_failed']);

    $this->travelTo($hold->expiresAt->subSeconds(30));
    $this->artisan('inventory:expire-holds')->assertSuccessful();

    expect(Fixtures::outbox('hold.expiring'))->toHaveCount(0);
});

it('rebuilds gates of recently touched upcoming slots and ignores old or past ones', function (): void {
    $recent = Fixtures::slot(['capacity' => 4]);
    $stale = Fixtures::slot(['capacity' => 4, 'updated_at' => now()->subMinutes(3)]);
    $past = Fixtures::slot(['capacity' => 4, 'starts_at' => now()->subHour(), 'released_at' => now()->subDay()]);

    foreach ([$recent, $stale, $past] as $slot) {
        Redis::connection()->command('set', ["slot_gate:{$slot->id}", 99]);
    }

    $this->artisan('inventory:expire-holds')->assertSuccessful();

    expect(Fixtures::gate($recent))->toBe(4)
        ->and(Fixtures::gate($stale))->toBe(99)
        ->and(Fixtures::gate($past))->toBe(99);
});

it('rebuilds a gate touched exactly rebuild_recent_minutes ago and skips one a microsecond older', function (): void {
    $edge = Fixtures::slot(['capacity' => 4, 'updated_at' => now()->subMinutes(2)]);
    $older = Fixtures::slot(['capacity' => 4, 'updated_at' => now()->subMinutes(2)->subMicrosecond()]);

    foreach ([$edge, $older] as $slot) {
        Redis::connection()->command('set', ["slot_gate:{$slot->id}", 99]);
    }

    $this->artisan('inventory:expire-holds')->assertSuccessful();

    expect(Fixtures::gate($edge))->toBe(4)
        ->and(Fixtures::gate($older))->toBe(99);
});

it('opens due drops once: sets opened_at and writes one drop.opened with slot_ids', function (): void {
    $venue = Venue::factory()->create();
    $drop = Fixtures::drop($venue, ['opens_at' => now()->subSecond()]);
    $late = Fixtures::slot(['drop_id' => $drop->id, 'min_party_size' => 3, 'max_party_size' => 4], $venue);
    $early = Fixtures::slot(['drop_id' => $drop->id, 'min_party_size' => 1, 'max_party_size' => 2], $venue);
    $future = Fixtures::drop($venue, ['opens_at' => now()->addMinute()]);

    $this->artisan('inventory:open-drops')->assertSuccessful();

    $events = Fixtures::outbox('drop.opened');
    expect($events)->toHaveCount(1)
        ->and($events->first()->payload)->toEqual([
            'drop_id' => $drop->id,
            'venue_id' => $venue->id,
            'opens_at' => $drop->opens_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'slot_ids' => [$late->id, $early->id],
        ])
        ->and(Drop::query()->findOrFail($drop->id)->opened_at?->equalTo(now()))->toBeTrue()
        ->and(Drop::query()->findOrFail($future->id)->opened_at)->toBeNull();

    $this->artisan('inventory:open-drops')->assertSuccessful();

    expect(Fixtures::outbox('drop.opened'))->toHaveCount(1);
});

it('opens a drop at exactly opens_at and not a microsecond before', function (): void {
    $drop = Fixtures::drop(Venue::factory()->create(), ['opens_at' => now()->addHour()]);

    $this->travelTo($drop->opens_at->subMicrosecond());
    $this->artisan('inventory:open-drops')->assertSuccessful();
    expect(Fixtures::outbox('drop.opened'))->toHaveCount(0);

    $this->travelTo($drop->opens_at);
    $this->artisan('inventory:open-drops')->assertSuccessful();
    expect(Fixtures::outbox('drop.opened'))->toHaveCount(1);
});

it('schedules both commands every minute without overlap on one server', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'inventory:'));

    expect($events)->toHaveCount(2);

    foreach ($events as $event) {
        expect($event->expression)->toBe('* * * * *')
            ->and($event->withoutOverlapping)->toBeTrue()
            ->and($event->onOneServer)->toBeTrue();
    }
});
