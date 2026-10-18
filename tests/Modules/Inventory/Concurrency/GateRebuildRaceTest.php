<?php

declare(strict_types=1);

use App\Modules\Inventory\Actions\ReleaseHold;
use App\Modules\Inventory\Enums\HoldReleaseReason;
use App\Modules\Inventory\Models\Slot;
use App\Modules\Inventory\Support\SlotGate;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Modules\Inventory\Support\Fixtures;

/*
| A10: a rebuild racing a release must never leave the gate lower than Postgres truth (C§6.1).
| The release runs on a second connection from inside the rebuild, at the two moments where it could
| slip in: right after the rebuild read the slot, and right after the rebuild transaction committed.
| With the slot read FOR SHARE and the SET issued before the commit, the first attempt is blocked
| (lock timeout) and the second runs after the SET, so its INCR lands on top of it.
*/
it('never lets a rebuild overwrite the increment of a release that commits during it (A10)', function (): void {
    config(['database.connections.second' => config('database.connections.'.config('database.default'))]);
    DB::connection('second')->statement("SET lock_timeout = '300ms'");

    $slot = Fixtures::slot(['capacity' => 2]);
    $hold = Fixtures::hold($slot);
    expect(Fixtures::gate($slot))->toBe(1);

    $state = (object) ['released' => false, 'blocked' => 0, 'inside' => false];

    $release = function () use ($state, $hold): void {
        if ($state->released || $state->inside) {
            return;
        }

        $state->inside = true;
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection('second');

        try {
            app(ReleaseHold::class)->handle($hold->id, HoldReleaseReason::PaymentFailed);
            $state->released = true;
        } catch (QueryException) {
            $state->blocked++;
        } finally {
            DB::setDefaultConnection($default);
            $state->inside = false;
        }
    };

    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use ($release): void {
        if (str_contains($query->sql, '"capacity", "booked_count", "held_count"')) {
            $release();
        }
    });
    Event::listen(TransactionCommitted::class, fn () => $release());

    app(SlotGate::class)->rebuild($slot->id, force: true);

    expect($state->blocked)->toBeGreaterThanOrEqual(1)
        ->and($state->released)->toBeTrue()
        ->and(Slot::query()->findOrFail($slot->id)->held_count)->toBe(0)
        ->and(Fixtures::gate($slot))->toBe(2);
});
