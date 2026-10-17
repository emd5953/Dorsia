<?php

declare(strict_types=1);

use App\Modules\Inventory\Actions\ConvertHoldToBooking;
use App\Modules\Inventory\Actions\ExpireHold;
use App\Modules\Inventory\Actions\HoldSlot;
use App\Modules\Inventory\Actions\ReleaseHold;
use App\Modules\Inventory\Data\HoldSlotInput;
use App\Modules\Inventory\Enums\HoldReleaseReason;
use App\Modules\Inventory\Models\Hold;
use App\Modules\Inventory\Models\Slot;
use App\Modules\Inventory\Models\Venue;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Modules\Inventory\Support\Fixtures;

/**
 * Makes the next $times hold inserts fail with $sqlState before they reach Postgres (the slot update of
 * the same transaction has already run and is rolled back). Callbacks cannot be removed from a
 * connection, so the hook is disarmed through the returned counter instead.
 *
 * @return object{armed: bool, thrown: int}
 */
function failHoldInsert(string $sqlState, int $times): object
{
    $state = (object) ['armed' => true, 'thrown' => 0];

    DB::connection()->beforeExecuting(function (string $query, array $bindings) use ($state, $sqlState, $times): void {
        if (! $state->armed || $state->thrown >= $times || ! str_starts_with($query, 'insert into "holds"')) {
            return;
        }

        $state->thrown++;

        $pdo = new PDOException('SQLSTATE['.$sqlState.']: simulated failure');
        $pdo->errorInfo = [$sqlState, 0, 'simulated failure'];

        throw new QueryException('pgsql', $query, $bindings, $pdo);
    });

    return $state;
}

/**
 * @param  Closure(): mixed  $run
 * @return list<string> lower-cased SQL of the row-locking selects, in execution order
 */
function lockQueries(Closure $run): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $run();
    } finally {
        DB::disableQueryLog();
    }

    return array_values(array_filter(
        array_map(fn (array $q): string => strtolower($q['query']), DB::getQueryLog()),
        fn (string $q): bool => str_contains($q, ' for update'),
    ));
}

function lockTargets(string ...$queries): string
{
    return implode(',', array_map(fn (string $q): string => preg_match('/from "(\w+)"/', $q, $m) === 1 ? $m[1] : '?', $queries));
}

function holdInput(int $memberId, int $slotId): HoldSlotInput
{
    return new HoldSlotInput($memberId, $slotId, 2, (string) Str::uuid());
}

it('retries once when Postgres picks the hold transaction as a deadlock victim (40P01)', function (): void {
    $slot = Fixtures::slot(['capacity' => 3]);
    $member = Fixtures::member();
    $failure = failHoldInsert('40P01', 1);

    $hold = app(HoldSlot::class)->handle(holdInput($member->id, $slot->id));
    $failure->armed = false;

    expect($failure->thrown)->toBe(1)
        ->and($hold->status->value)->toBe('active')
        ->and(Hold::query()->count())->toBe(1)
        ->and($slot->fresh()->held_count)->toBe(1)
        ->and(Fixtures::outbox('slot.held'))->toHaveCount(1)
        ->and(Fixtures::gate($slot))->toBe(2);
});

it('lets a second 40P01 propagate, leaves no hold behind and returns the gate unit', function (): void {
    $slot = Fixtures::slot(['capacity' => 3]);
    $member = Fixtures::member();
    $failure = failHoldInsert('40P01', 2);

    try {
        app(HoldSlot::class)->handle(holdInput($member->id, $slot->id));
        $thrown = null;
    } catch (QueryException $e) {
        $thrown = $e;
    }

    $failure->armed = false;

    expect($thrown)->toBeInstanceOf(QueryException::class)
        ->and($failure->thrown)->toBe(2)
        ->and(Hold::query()->count())->toBe(0)
        ->and($slot->fresh()->held_count)->toBe(0)
        ->and(Fixtures::outbox('slot.held'))->toHaveCount(0)
        ->and(Fixtures::gate($slot))->toBe(3);
});

it('does not retry other query errors, such as a serialization failure (40001)', function (): void {
    $slot = Fixtures::slot(['capacity' => 3]);
    $member = Fixtures::member();
    $failure = failHoldInsert('40001', 1);

    try {
        app(HoldSlot::class)->handle(holdInput($member->id, $slot->id));
        $thrown = null;
    } catch (QueryException $e) {
        $thrown = $e;
    }

    $failure->armed = false;

    expect($thrown)->toBeInstanceOf(QueryException::class)
        ->and($failure->thrown)->toBe(1)
        ->and(Hold::query()->count())->toBe(0)
        ->and(Fixtures::gate($slot))->toBe(3);
});

it('locks the slot row before the hold row when a hold is released', function (): void {
    $slot = Fixtures::slot(['capacity' => 3]);
    $hold = Fixtures::hold($slot);

    $locks = lockQueries(fn () => app(ReleaseHold::class)->handle($hold->id, HoldReleaseReason::PaymentFailed));

    expect(lockTargets(...$locks))->toBe('slots,holds');
});

it('locks the slot row before the hold row when a hold is expired', function (): void {
    $slot = Fixtures::slot(['capacity' => 3]);
    $hold = Fixtures::hold($slot);
    Hold::query()->update(['expires_at' => now()->subSecond()]);

    $locks = lockQueries(fn () => app(ExpireHold::class)->handle($hold->id));

    expect(lockTargets(...$locks))->toBe('slots,holds');
});

it('locks the slot row before the hold row when a hold is converted', function (): void {
    $slot = Fixtures::slot(['capacity' => 3]);
    $member = Fixtures::member();
    $hold = Fixtures::hold($slot, $member);

    $locks = lockQueries(fn () => DB::transaction(fn () => app(ConvertHoldToBooking::class)->handle($hold->id, $member->id)));

    expect(lockTargets(...$locks))->toBe('slots,holds');
});

it('locks drop, then slot, then hold when the converted hold belongs to a drop', function (): void {
    $venue = Venue::factory()->create();
    $drop = Fixtures::drop($venue, ['opens_at' => now()->subHour(), 'opened_at' => now()->subHour()]);
    $slot = Fixtures::slot(['capacity' => 3, 'drop_id' => $drop->id], $venue);
    $member = Fixtures::member();
    $hold = Fixtures::hold($slot, $member);

    $locks = lockQueries(fn () => DB::transaction(fn () => app(ConvertHoldToBooking::class)->handle($hold->id, $member->id)));

    expect(lockTargets(...$locks))->toBe('drops,slots,holds')
        ->and(Slot::query()->findOrFail($slot->id)->booked_count)->toBe(1);
});
