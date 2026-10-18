<?php

declare(strict_types=1);

use App\Modules\Inventory\Actions\HoldSlot;
use App\Modules\Inventory\Actions\ReleaseBooking;
use App\Modules\Inventory\Data\HoldData;
use App\Modules\Inventory\Data\HoldSlotInput;
use App\Modules\Inventory\Exceptions\SoldOutException;
use App\Modules\Inventory\Models\Hold;
use App\Modules\Inventory\Models\Slot;
use App\Modules\Inventory\Support\SlotGate;
use Carbon\CarbonImmutable;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\Modules\Inventory\Support\Fixtures;

/**
 * Runs $attempts hold attempts by fresh members and counts what the gate refresh cost.
 *
 * @return array{outcomes: array<string, int>, rebuilds: int, overdueScans: int}
 */
function stormSlot(Slot $slot, int $attempts): array
{
    $members = array_map(fn () => Fixtures::member(), range(1, $attempts));
    $outcomes = [];

    DB::flushQueryLog();
    DB::enableQueryLog();

    foreach ($members as $member) {
        try {
            Fixtures::hold($slot, $member);
            $outcome = 'ok';
        } catch (SoldOutException) {
            $outcome = 'sold_out';
        }

        $outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + 1;
    }

    $queries = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    return [
        'outcomes' => $outcomes,
        // The rebuild reads the slot's counts; the expiry scan is the overdue-holds select.
        'rebuilds' => count(array_filter($queries, fn (string $q): bool => str_contains($q, '"capacity", "booked_count", "held_count", "starts_at"'))),
        'overdueScans' => count(array_filter($queries, fn (string $q): bool => str_contains($q, 'from "holds" where "status" = ? and "expires_at" <='))),
    ];
}

function guardTtl(Slot $slot): int
{
    return (int) Redis::connection()->command('ttl', ["slot_gate:{$slot->id}:refresh"]);
}

it('rebuilds once for a sold-out storm on a full slot with a drained gate, then sheds without queries inside the window', function (): void {
    $slot = Fixtures::slot(['capacity' => 3]);

    foreach (range(1, 3) as $ignored) {
        Fixtures::hold($slot);
    }

    expect(Fixtures::gate($slot))->toBe(0);

    $storm = stormSlot($slot, 12);

    expect($storm['outcomes'])->toBe(['sold_out' => 12])
        ->and($storm['rebuilds'])->toBe(1)
        ->and($storm['overdueScans'])->toBe(1)
        ->and(Fixtures::gate($slot))->toBe(0);
});

it('sheds a request inside the window with no write and no rebuild or overdue query', function (): void {
    $slot = Fixtures::slot(['capacity' => 1]);
    Fixtures::hold($slot);
    $first = stormSlot($slot, 1);
    $inside = stormSlot($slot, 1);

    expect($first['rebuilds'])->toBe(1)
        ->and($inside['rebuilds'])->toBe(0)
        ->and($inside['overdueScans'])->toBe(0);
});

it('sets the refresh guard with a 5 second TTL, not forever and not 60 seconds', function (): void {
    $slot = Fixtures::slot(['capacity' => 1]);
    Fixtures::hold($slot);
    stormSlot($slot, 1);

    expect(guardTtl($slot))->toBeBetween(1, 5);
});

it('does not take a guard that is already held: no rebuild and no overdue scan while it lives (NX)', function (): void {
    $slot = Fixtures::slot(['capacity' => 2]);
    Fixtures::hold($slot);
    Fixtures::hold($slot);
    Redis::connection()->command('set', ["slot_gate:{$slot->id}:refresh", '1', ['EX' => 3]]);

    $storm = stormSlot($slot, 5);

    expect($storm['rebuilds'])->toBe(0)
        ->and($storm['overdueScans'])->toBe(0)
        ->and(guardTtl($slot))->toBeBetween(1, 3);
});

it('lets the guard expire: the next sold-out request after the window rebuilds again', function (): void {
    config(['inventory.gate.refresh_seconds' => 1]);
    $slot = Fixtures::slot(['capacity' => 1]);
    Fixtures::hold($slot);

    $first = stormSlot($slot, 3);
    expect($first['rebuilds'])->toBe(1);

    usleep(1_150_000);
    expect(guardTtl($slot))->toBe(-2);

    $second = stormSlot($slot, 3);

    expect($second['rebuilds'])->toBe(1)
        ->and($second['overdueScans'])->toBe(1);
});

it('heals a leaked gate unit past the window without a pre-set guard: the first request after the guard frees succeeds', function (): void {
    config(['inventory.gate.refresh_seconds' => 1]);
    $slot = Fixtures::slot(['capacity' => 2]);
    Fixtures::hold($slot);
    Fixtures::hold($slot);

    // A request is shed on the truly full slot and takes the guard.
    expect(stormSlot($slot, 1)['outcomes'])->toBe(['sold_out' => 1]);

    // A table frees without its gate unit ever coming back (a crash between commit and INCR).
    $victim = Hold::query()->firstOrFail();
    DB::table('holds')->where('id', $victim->id)->update(['status' => 'released', 'closed_at' => now(), 'close_reason' => 'payment_failed']);
    DB::table('slots')->where('id', $slot->id)->decrement('held_count');

    expect(Fixtures::gate($slot))->toBe(0)
        ->and(stormSlot($slot, 1)['outcomes'])->toBe(['sold_out' => 1]);

    usleep(1_150_000);

    $healed = stormSlot($slot, 1);

    expect($healed['outcomes'])->toBe(['ok' => 1])
        ->and($healed['rebuilds'])->toBe(1)
        ->and($slot->fresh()->held_count)->toBe(2);
});

it('heals an unswept overdue hold on the first shed request when the guard is free (drop-in)', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-12-01 12:00:00.5'));
    $slot = Fixtures::slot(['capacity' => 2]);
    $held = Fixtures::hold($slot);
    $stillActive = Fixtures::hold($slot);
    DB::table('holds')->where('id', $stillActive->id)->update(['expires_at' => $held->expiresAt->addMinutes(5)]);
    $this->travelTo($held->expiresAt);

    $storm = stormSlot($slot, 3);

    expect($storm['outcomes'])->toBe(['ok' => 1, 'sold_out' => 2])
        ->and($storm['rebuilds'])->toBe(1)
        ->and(Hold::query()->where('status', 'expired')->count())->toBe(1);
});

it('does not return the gate unit when Postgres rejects while the guard is held, and rebuilds only when it is free', function (): void {
    $slot = Fixtures::slot(['capacity' => 1, 'held_count' => 1]);
    Redis::connection()->command('set', ["slot_gate:{$slot->id}", 99]);
    Redis::connection()->command('set', ["slot_gate:{$slot->id}:refresh", '1', ['EX' => 3]]);

    $storm = stormSlot($slot, 1);

    // The unit stays taken (no INCR) and nothing is rebuilt inside the window.
    expect($storm['outcomes'])->toBe(['sold_out' => 1])
        ->and($storm['rebuilds'])->toBe(0)
        ->and(Fixtures::gate($slot))->toBe(98);

    Redis::connection()->command('del', ["slot_gate:{$slot->id}:refresh"]);
    $storm = stormSlot($slot, 1);

    expect($storm['rebuilds'])->toBe(1)
        ->and(Fixtures::gate($slot))->toBe(0);
});

it('rebuilds once per window when Postgres keeps rejecting a too-high gate', function (): void {
    $slot = Fixtures::slot(['capacity' => 2, 'held_count' => 2]);
    Redis::connection()->command('set', ["slot_gate:{$slot->id}", 50]);

    $storm = stormSlot($slot, 6);

    expect($storm['outcomes'])->toBe(['sold_out' => 6])
        ->and($storm['rebuilds'])->toBe(1)
        ->and(Fixtures::gate($slot))->toBe(0);
});

it('creates only the counter, the rebuild lock and the refresh guard: no pending or generation keys', function (): void {
    $slot = Fixtures::slot(['capacity' => 2]);
    Redis::connection()->flushdb();

    Fixtures::hold($slot);
    Fixtures::hold($slot);
    stormSlot($slot, 3);
    $hold = Hold::query()->firstOrFail();
    DB::transaction(fn () => app(ReleaseBooking::class)->handle($slot->id));
    app(SlotGate::class)->rebuild($slot->id, force: true);

    $keys = collect(Redis::connection()->command('keys', ['*']))
        ->map(fn (string $key): string => preg_replace('/^.*?(slot_gate:)/', '$1', $key) ?? $key)
        ->sort()->values()->all();

    expect($keys)->toBe(["slot_gate:{$slot->id}", "slot_gate:{$slot->id}:refresh"])
        ->and($hold->id)->toBeInt();
});

it('lets an after-commit release always increment, even when a rebuild ran between the update and the commit', function (): void {
    $slot = Fixtures::slot(['capacity' => 3, 'booked_count' => 2]);
    app(SlotGate::class)->rebuild($slot->id, force: true);
    expect(Fixtures::gate($slot))->toBe(1);

    DB::transaction(function () use ($slot): void {
        app(ReleaseBooking::class)->handle($slot->id);
        // The rebuild already counts the freed table; the release must still add its unit after commit.
        app(SlotGate::class)->rebuild($slot->id, force: true);

        expect(Fixtures::gate($slot))->toBe(2);
    });

    expect(Fixtures::gate($slot))->toBe(3);
});

it('never lowers the gate below Postgres truth when a rebuild runs between DECR and commit', function (): void {
    $slot = Fixtures::slot(['capacity' => 2]);
    app(SlotGate::class)->rebuild($slot->id, force: true);

    $hold = app(HoldSlot::class)->handle(
        new HoldSlotInput(Fixtures::member()->id, $slot->id, 2, 'inflight'),
        function (HoldData $hold) use ($slot): void {
            // The hold row is already visible on this connection; a rebuild from another request sees the same.
            app(SlotGate::class)->rebuild($slot->id, force: true);
        },
    );

    expect($hold->slot->heldCount)->toBe(1)
        ->and(Fixtures::gate($slot))->toBeGreaterThanOrEqual(1);
});

it('bounds the wait of rebuild-lock losers to rebuild_wait_ms and then lets Postgres decide', function (): void {
    config(['inventory.gate.rebuild_wait_ms' => 120]);
    $slot = Fixtures::slot(['capacity' => 2]);
    $store = Cache::store('redis')->getStore();
    $lock = $store->lock("slot_gate:{$slot->id}:lock", 10);
    expect($lock->get())->toBeTrue();

    $started = microtime(true);
    $hold = Fixtures::hold($slot);
    $elapsed = microtime(true) - $started;
    $lock->release();

    expect($hold->status->value)->toBe('active')
        ->and($elapsed)->toBeGreaterThanOrEqual(0.12)
        ->and($elapsed)->toBeLessThan(0.6)
        ->and(Fixtures::gate($slot))->toBeNull();
});

it('does not wait at all when the winner has already published the counter', function (): void {
    $slot = Fixtures::slot(['capacity' => 2]);
    Redis::connection()->command('set', ["slot_gate:{$slot->id}", 2]);
    $lock = Cache::store('redis')->getStore()->lock("slot_gate:{$slot->id}:lock", 10);
    $lock->get();

    $started = microtime(true);
    Fixtures::hold($slot);

    expect(microtime(true) - $started)->toBeLessThan(0.1);
    $lock->release();
});

it('reports no unit when the counter vanishes between the refresh and the second acquire', function (): void {
    $slot = Fixtures::slot(['capacity' => 1]);
    Fixtures::hold($slot);
    $other = Fixtures::member();
    Hold::query()->update(['expires_at' => now()->subSecond()]);

    // Gate says full, Postgres frees the table through the refresh; Redis is flushed right after the
    // rebuild wrote the counter, so the second acquire finds the key missing and Postgres decides.
    $flushed = 0;
    Fixtures::listenRedis(function (CommandExecuted $command) use (&$flushed, $slot): void {
        if ($flushed === 0 && strtolower($command->command) === 'set' && ($command->parameters[0] ?? null) === "slot_gate:{$slot->id}") {
            $flushed++;
            Redis::connection()->flushdb();
        }
    });

    $hold = Fixtures::hold($slot, $other);

    expect($flushed)->toBe(1)
        ->and($hold->status->value)->toBe('active')
        ->and($slot->fresh()->held_count)->toBe(1)
        ->and(Fixtures::gate($slot))->toBeNull();
});
