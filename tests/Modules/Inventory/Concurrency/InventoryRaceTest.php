<?php

declare(strict_types=1);

use App\Modules\Inventory\Actions\ConvertHoldToBooking;
use App\Modules\Inventory\Actions\ExpireHold;
use App\Modules\Inventory\Actions\HoldSlot;
use App\Modules\Inventory\Actions\ReleaseBooking;
use App\Modules\Inventory\Actions\ReleaseHold;
use App\Modules\Inventory\Data\HoldSlotInput;
use App\Modules\Inventory\Enums\HoldReleaseReason;
use App\Modules\Inventory\Exceptions\SoldOutException;
use App\Modules\Inventory\Models\Drop;
use App\Modules\Inventory\Models\Hold;
use App\Modules\Inventory\Models\Slot;
use App\Modules\Inventory\Models\Venue;
use App\Modules\Inventory\Support\SlotGate;
use App\Shared\Outbox\OutboxEvent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\Modules\Inventory\Support\Fixtures;
use Tests\Support\Concurrency;

it('writes drop.sold_out exactly once when 20 holds on the last slots of a drop convert in parallel', function (): void {
    $venue = Venue::factory()->create();
    $drop = Fixtures::drop($venue, ['opens_at' => now()->subHour()]);
    $holds = [];

    foreach (range(1, 20) as $shape) {
        $slot = Fixtures::slot(['drop_id' => $drop->id, 'capacity' => 1, 'min_party_size' => $shape, 'max_party_size' => $shape], $venue);
        $holds[] = Fixtures::hold($slot, null, $shape);
    }

    $closures = array_map(
        fn ($hold): Closure => fn (): int => DB::transaction(fn () => app(ConvertHoldToBooking::class)->handle($hold->id, $hold->memberId))->bookedCount,
        $holds,
    );

    $results = Concurrency::run($closures);

    expect($results)->toBe(array_fill(0, 20, 1))
        ->and(Slot::query()->where('booked_count', 1)->count())->toBe(20)
        ->and(OutboxEvent::query()->where('topic', 'drop.sold_out')->count())->toBe(1)
        ->and(OutboxEvent::query()->where('topic', 'slot.booked')->count())->toBe(20)
        ->and(Drop::query()->findOrFail($drop->id)->sold_out_at)->not->toBeNull();
});

it('does not mark a drop sold out when only some of its slots convert in parallel', function (): void {
    $venue = Venue::factory()->create();
    $drop = Fixtures::drop($venue, ['opens_at' => now()->subHour()]);
    $holds = [];

    foreach (range(1, 20) as $shape) {
        $slot = Fixtures::slot(['drop_id' => $drop->id, 'capacity' => 1, 'min_party_size' => $shape, 'max_party_size' => $shape], $venue);
        $holds[] = Fixtures::hold($slot, null, $shape);
    }

    $closures = array_map(
        fn ($hold): Closure => fn (): int => DB::transaction(fn () => app(ConvertHoldToBooking::class)->handle($hold->id, $hold->memberId))->bookedCount,
        array_slice($holds, 0, 19),
    );

    Concurrency::run($closures);

    expect(OutboxEvent::query()->where('topic', 'drop.sold_out')->count())->toBe(0)
        ->and(Drop::query()->findOrFail($drop->id)->sold_out_at)->toBeNull();
});

it('converts a hold exactly once when 20 processes race to confirm it', function (): void {
    $slot = Fixtures::slot(['capacity' => 3]);
    $hold = Fixtures::hold($slot);

    $closures = array_fill(0, 20, function () use ($hold): string {
        try {
            DB::transaction(fn () => app(ConvertHoldToBooking::class)->handle($hold->id, $hold->memberId));

            return 'converted';
        } catch (Throwable $e) {
            return class_basename($e);
        }
    });

    $results = array_count_values(Concurrency::run($closures));

    expect($results)->toEqual(['converted' => 1, 'HoldNotActiveException' => 19])
        ->and(Slot::query()->findOrFail($slot->id)->booked_count)->toBe(1)
        ->and(Slot::query()->findOrFail($slot->id)->held_count)->toBe(0)
        ->and(OutboxEvent::query()->where('topic', 'slot.booked')->count())->toBe(1);
});

it('releases a hold exactly once under 20 parallel ReleaseHold calls', function (): void {
    $slot = Fixtures::slot(['capacity' => 3]);
    $hold = Fixtures::hold($slot);

    $results = Concurrency::run(array_fill(
        0,
        20,
        fn (): bool => app(ReleaseHold::class)->handle($hold->id, HoldReleaseReason::PaymentFailed),
    ));

    expect(array_count_values(array_map('intval', $results)))->toEqual([1 => 1, 0 => 19])
        ->and(Slot::query()->findOrFail($slot->id)->held_count)->toBe(0)
        ->and(OutboxEvent::query()->where('topic', 'slot.released')->count())->toBe(1)
        ->and(Fixtures::gate($slot))->toBe(3);
});

it('expires a hold exactly once under 20 parallel ExpireHold calls', function (): void {
    $slot = Fixtures::slot(['capacity' => 3]);
    $hold = Fixtures::hold($slot);
    Hold::query()->whereKey($hold->id)->update(['expires_at' => now()->subSecond()]);

    $results = Concurrency::run(array_fill(0, 20, fn (): bool => app(ExpireHold::class)->handle($hold->id)));

    expect(array_count_values(array_map('intval', $results)))->toEqual([1 => 1, 0 => 19])
        ->and(OutboxEvent::query()->where('topic', 'slot.released')->count())->toBe(1)
        ->and(Slot::query()->findOrFail($slot->id)->held_count)->toBe(0)
        ->and(Fixtures::gate($slot))->toBe(3);
});

it('lets lazy expiry, ReleaseHold and 20 parallel sweepers settle one hold exactly once', function (): void {
    $slot = Fixtures::slot(['capacity' => 3]);
    $hold = Fixtures::hold($slot);
    Hold::query()->whereKey($hold->id)->update(['expires_at' => now()->subSecond()]);

    $closures = [fn (): int => (int) app(ReleaseHold::class)->handle($hold->id, HoldReleaseReason::PaymentTimeout)];

    foreach (range(1, 20) as $ignored) {
        $closures[] = fn (): int => Artisan::call('inventory:expire-holds');
    }

    Concurrency::run($closures);

    expect(OutboxEvent::query()->where('topic', 'slot.released')->count())->toBe(1)
        ->and(Slot::query()->findOrFail($slot->id)->held_count)->toBe(0)
        ->and(Fixtures::gate($slot))->toBe(3)
        ->and(Hold::query()->findOrFail($hold->id)->status)->toBeIn(['expired', 'released']);
});

it('writes hold.expiring exactly once per hold when 20 sweepers run at the same time', function (): void {
    $slot = Fixtures::slot(['capacity' => 6]);

    foreach (range(1, 3) as $ignored) {
        Fixtures::hold($slot);
    }

    Hold::query()->update(['expires_at' => now()->addSeconds(30)]);

    Concurrency::run(array_fill(0, 20, fn (): int => Artisan::call('inventory:expire-holds')));

    expect(OutboxEvent::query()->where('topic', 'hold.expiring')->count())->toBe(3)
        ->and(Hold::query()->whereNull('expiring_notified_at')->count())->toBe(0);
});

it('writes drop.opened exactly once when 20 schedulers run open-drops at the same time', function (): void {
    $venue = Venue::factory()->create();
    $drop = Fixtures::drop($venue, ['opens_at' => now()->subMinute()]);
    Fixtures::slot(['drop_id' => $drop->id], $venue);

    Concurrency::run(array_fill(0, 20, fn (): int => Artisan::call('inventory:open-drops')));

    expect(OutboxEvent::query()->where('topic', 'drop.opened')->count())->toBe(1)
        ->and(Drop::query()->findOrFail($drop->id)->opened_at)->not->toBeNull();
});

it('rebuilds a missing gate once and consistently when 20 processes ask at the same time', function (): void {
    $slot = Fixtures::slot(['capacity' => 7, 'held_count' => 2, 'booked_count' => 1]);
    Redis::connection()->flushdb();

    $results = Concurrency::run(array_fill(0, 20, fn (): bool => app(SlotGate::class)->rebuild($slot->id)));

    expect($results)->toBe(array_fill(0, 20, true))
        ->and(Fixtures::gate($slot))->toBe(4);
});

it('allows at most one refresh rebuild for 20 parallel sold-out requests in a refresh window', function (): void {
    $slot = Fixtures::slot(['capacity' => 1, 'booked_count' => 1]);
    $members = array_map(fn () => Fixtures::member()->id, range(1, 20));
    Redis::connection()->command('set', ["slot_gate:{$slot->id}", 0]);

    $results = Concurrency::run(array_map(function (int $memberId) use ($slot): Closure {
        return function () use ($slot, $memberId): string {
            try {
                app(HoldSlot::class)->handle(new HoldSlotInput($memberId, $slot->id, 2, (string) Str::uuid()));

                return 'held';
            } catch (SoldOutException) {
                return 'sold_out';
            }
        };
    }, $members));

    expect(array_count_values($results))->toBe(['sold_out' => 20])
        ->and(Fixtures::gate($slot))->toBe(0)
        ->and(Redis::connection()->command('exists', ["slot_gate:{$slot->id}:refresh"]))->toBe(1)
        ->and(Hold::query()->count())->toBe(0);
});

it('serialises ReleaseBooking: 20 parallel releases of a 5-booked slot take it to zero and no lower', function (): void {
    $slot = Fixtures::slot(['capacity' => 5]);

    foreach (range(1, 5) as $ignored) {
        $hold = Fixtures::hold($slot);
        DB::transaction(fn () => app(ConvertHoldToBooking::class)->handle($hold->id, $hold->memberId));
    }

    expect(Fixtures::gate($slot))->toBe(0);

    Concurrency::run(array_fill(0, 20, fn () => DB::transaction(fn () => app(ReleaseBooking::class)->handle($slot->id))->bookedCount));

    $row = Slot::query()->findOrFail($slot->id);
    expect($row->booked_count)->toBe(0)
        ->and(OutboxEvent::query()->where('topic', 'slot.released')->count())->toBe(5)
        ->and(Fixtures::gate($slot))->toBe(5);
});
