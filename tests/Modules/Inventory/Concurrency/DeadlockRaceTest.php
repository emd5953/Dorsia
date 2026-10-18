<?php

declare(strict_types=1);

use App\Modules\Inventory\Actions\ConvertHoldToBooking;
use App\Modules\Inventory\Actions\HoldSlot;
use App\Modules\Inventory\Data\HoldSlotInput;
use App\Modules\Inventory\Models\Hold;
use App\Modules\Inventory\Models\Slot;
use App\Shared\Exceptions\DomainError;
use App\Shared\Members\Member;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Modules\Inventory\Support\Fixtures;
use Tests\Support\Concurrency;

/**
 * Outcome label of one hold attempt: "ok", a domain error code, or the class of an unexpected throwable
 * (a deadlock would show up as QueryException, a 500 over HTTP).
 */
function raceHold(int $memberId, int $slotId): string
{
    try {
        app(HoldSlot::class)->handle(new HoldSlotInput($memberId, $slotId, 2, (string) Str::uuid()));

        return 'ok';
    } catch (DomainError $e) {
        return $e->errorCode();
    } catch (Throwable $e) {
        return get_class($e).': '.substr($e->getMessage(), 0, 120);
    }
}

function overdue(Hold|int $hold): void
{
    DB::table('holds')->where('id', $hold instanceof Hold ? $hold->id : $hold)->update(['expires_at' => now()->subSecond()]);
}

it('never deadlocks when one member with an overdue hold fires 20 requests with different keys (A9)', function (): void {
    $slot = Fixtures::slot(['capacity' => 3]);
    $member = Fixtures::member();
    overdue(Fixtures::hold($slot, $member)->id);

    $results = Concurrency::run(array_fill(0, 20, fn (): string => raceHold($member->id, $slot->id)));
    $counts = array_count_values($results);

    expect(array_diff(array_keys($counts), ['ok', 'active_hold_exists', 'sold_out']))->toBe([])
        ->and($counts['ok'] ?? 0)->toBe(1)
        ->and(Hold::query()->where('status', 'active')->count())->toBe(1)
        ->and(Hold::query()->where('status', 'expired')->count())->toBe(1)
        ->and(Slot::query()->findOrFail($slot->id)->held_count)->toBe(1);
});

it('never deadlocks other members while the overdue hold of one member is being replaced (A9b)', function (): void {
    $slot = Fixtures::slot(['capacity' => 3]);
    $owner = Fixtures::member();
    overdue(Fixtures::hold($slot, $owner)->id);
    $others = Member::factory()->count(16)->create();

    $closures = array_fill(0, 4, fn (): string => 'X:'.raceHold($owner->id, $slot->id));

    foreach ($others as $other) {
        $closures[] = fn (): string => 'Y:'.raceHold($other->id, $slot->id);
    }

    $results = Concurrency::run($closures);
    $unexpected = array_filter($results, fn (string $r): bool => ! in_array(substr($r, 2), ['ok', 'active_hold_exists', 'sold_out'], true));

    expect($unexpected)->toBe([]);

    $slotRow = Slot::query()->findOrFail($slot->id);
    expect($slotRow->held_count)->toBe(Hold::query()->where('status', 'active')->count())
        ->and($slotRow->held_count)->toBeLessThanOrEqual(3);
});

it('never deadlocks when ConvertHoldToBooking races a new hold attempt of the same member', function (): void {
    $slot = Fixtures::slot(['capacity' => 3]);
    $member = Fixtures::member();
    $hold = Fixtures::hold($slot, $member);

    $closures = [fn (): string => (string) DB::transaction(fn () => app(ConvertHoldToBooking::class)->handle($hold->id, $member->id))->bookedCount];

    foreach (range(1, 10) as $ignored) {
        $closures[] = fn (): string => raceHold($member->id, $slot->id);
    }

    $results = Concurrency::run($closures);
    $unexpected = array_filter(array_slice($results, 1), fn (string $r): bool => ! in_array($r, ['ok', 'active_hold_exists', 'sold_out'], true));

    expect($results[0])->toBe('1')->and($unexpected)->toBe([]);
});

it('never tells other members sold_out while a member fires parallel duplicates and tables are free (A9b shape)', function (): void {
    $slot = Fixtures::slot(['capacity' => 3]);
    $owner = Fixtures::member();
    Fixtures::hold($slot, $owner);
    $others = Member::factory()->count(2)->create();

    $closures = array_fill(0, 18, fn (): string => 'X:'.raceHold($owner->id, $slot->id));

    foreach ($others as $other) {
        $closures[] = fn (): string => 'Y:'.raceHold($other->id, $slot->id);
    }

    $results = Concurrency::run($closures);

    expect(array_values(array_unique(array_filter($results, fn (string $r): bool => str_starts_with($r, 'X:')))))->toBe(['X:active_hold_exists'])
        ->and(array_values(array_filter($results, fn (string $r): bool => str_starts_with($r, 'Y:') && $r !== 'Y:ok')))->toBe([])
        ->and(Slot::query()->findOrFail($slot->id)->held_count)->toBe(3)
        ->and(Fixtures::gate($slot))->toBe(0);
});
