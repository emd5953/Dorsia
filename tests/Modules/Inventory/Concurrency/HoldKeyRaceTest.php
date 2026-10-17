<?php

declare(strict_types=1);

use App\Modules\Inventory\Actions\HoldSlot;
use App\Modules\Inventory\Data\HoldSlotInput;
use App\Modules\Inventory\Models\Hold;
use App\Modules\Inventory\Models\Slot;
use App\Modules\Inventory\Support\SlotGate;
use App\Shared\Exceptions\DomainError;
use App\Shared\Members\Member;
use App\Shared\Outbox\OutboxEvent;
use App\Shared\Support\Timestamps;
use Carbon\CarbonInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Modules\Inventory\Support\Fixtures;
use Tests\Support\Concurrency;

/** A second connection that commits on its own, to play "another request" at an exact point. */
function sideConnection(): Connection
{
    config(['database.connections.side' => config('database.connections.'.config('database.default'))]);

    return DB::connection('side');
}

/**
 * Runs $sideEffect once, right after the first HoldSlot own-holds lookup (before any gate unit or write).
 */
function afterOwnHoldsLookup(Closure $sideEffect): void
{
    $fired = false;

    DB::listen(function (QueryExecuted $query) use (&$fired, $sideEffect): void {
        if (! $fired && $query->connectionName !== 'side' && str_contains($query->sql, 'from "holds" where "slot_id" = ? and "member_id" = ? and "status" = ?')) {
            $fired = true;
            $sideEffect();
        }
    });
}

/** Commits an active hold on the side connection, keeping held_count consistent. */
function sideHold(Slot $slot, Member $member, string $key, CarbonInterface $expiresAt): int
{
    $side = sideConnection();
    $now = now();

    return $side->transaction(function () use ($side, $slot, $member, $key, $expiresAt, $now): int {
        $side->update('UPDATE slots SET held_count = held_count + 1 WHERE id = ?', [$slot->id]);

        return (int) $side->table('holds')->insertGetId([
            'slot_id' => $slot->id,
            'member_id' => $member->id,
            'party_size' => 2,
            'status' => 'active',
            'idempotency_key' => $key,
            'expires_at' => Timestamps::bind($expiresAt),
            'created_at' => Timestamps::bind($now),
            'updated_at' => Timestamps::bind($now),
        ]);
    });
}

it('replays one hold for 12 parallel action-level calls sharing one key: no sold_out, no leaked unit', function (): void {
    $slot = Fixtures::slot(['capacity' => 4]);
    $member = Fixtures::member();
    $key = $member->id.':'.Str::uuid();

    $results = Concurrency::run(array_fill(0, 12, function () use ($slot, $member, $key): string {
        try {
            return 'hold:'.app(HoldSlot::class)->handle(new HoldSlotInput($member->id, $slot->id, 2, $key))->id;
        } catch (DomainError $e) {
            return $e->errorCode();
        }
    }));

    expect(array_unique($results))->toHaveCount(1)
        ->and($results[0])->toStartWith('hold:')
        ->and(Hold::query()->count())->toBe(1)
        ->and(Slot::query()->findOrFail($slot->id)->held_count)->toBe(1)
        ->and(OutboxEvent::query()->where('topic', 'slot.held')->count())->toBe(1)
        ->and(Fixtures::gate($slot))->toBe(3);
});

it('does not let a member with an overdue hold drain gate units with parallel requests', function (): void {
    $slot = Fixtures::slot(['capacity' => 2]);
    $member = Fixtures::member();
    Fixtures::hold($slot, $member);
    Hold::query()->update(['expires_at' => now()->subSecond()]);

    $results = Concurrency::run(array_fill(0, 20, function () use ($slot, $member): string {
        try {
            app(HoldSlot::class)->handle(new HoldSlotInput($member->id, $slot->id, 2, (string) Str::uuid()));

            return 'held';
        } catch (DomainError $e) {
            return $e->errorCode();
        }
    }));

    $counts = array_count_values($results);
    $row = Slot::query()->findOrFail($slot->id);

    // Racers that lose the gate before the winner commits are shed (sold_out); none keeps a unit.
    expect(array_diff(array_keys($counts), ['held', 'active_hold_exists', 'sold_out']))->toBe([])
        ->and($counts['held'] ?? 0)->toBe(1)
        ->and($row->held_count)->toBe(1)
        ->and(Hold::query()->where('status', 'expired')->count())->toBe(1)
        ->and(Fixtures::gate($slot))->toBeGreaterThanOrEqual($row->capacity - $row->held_count - $row->booked_count);

    // The second table is really free for everyone else, at once.
    expect(Fixtures::hold($slot)->status->value)->toBe('active');
});

it('retries when its own overdue hold trips the one-active index after the lookup', function (): void {
    $slot = Fixtures::slot(['capacity' => 2]);
    $member = Fixtures::member();
    $overdueId = 0;

    // A warm gate, so no rebuild (which would expire the overdue hold itself) runs before the insert.
    app(SlotGate::class)->rebuild($slot->id);

    // Between the lookup and the insert, an overdue (unswept) hold of this member appears.
    afterOwnHoldsLookup(function () use ($slot, $member, &$overdueId): void {
        $overdueId = sideHold($slot, $member, 'side:'.Str::uuid(), now()->subSecond());
    });

    $hold = Fixtures::hold($slot, $member);

    expect($hold->status->value)->toBe('active')
        ->and(DB::table('holds')->where('id', $overdueId)->value('status'))->toBe('expired')
        ->and(Slot::query()->findOrFail($slot->id)->held_count)->toBe(1);
});

it('replays the winner when another request commits the same key after the lookup, and returns its gate unit', function (): void {
    $slot = Fixtures::slot(['capacity' => 3]);
    $member = Fixtures::member();
    $key = $member->id.':'.Str::uuid();
    $winnerId = 0;

    afterOwnHoldsLookup(function () use ($slot, $key, &$winnerId): void {
        // The concurrent winner, as committed by "another process" (different member, same key).
        $winnerId = sideHold($slot, Fixtures::member(), $key, now()->addMinutes(5));
        app(SlotGate::class)->acquire($slot->id);
    });

    app(SlotGate::class)->rebuild($slot->id);
    expect(Fixtures::gate($slot))->toBe(3);

    $hold = app(HoldSlot::class)->handle(new HoldSlotInput($member->id, $slot->id, 2, $key));

    expect($hold->id)->toBe($winnerId)
        ->and(Hold::query()->count())->toBe(1)
        ->and(Slot::query()->findOrFail($slot->id)->held_count)->toBe(1)
        ->and(Fixtures::gate($slot))->toBe(2);
});
