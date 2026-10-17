<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Data\HoldData;
use App\Modules\Inventory\Data\HoldSlotInput;
use App\Modules\Inventory\Enums\HoldStatus;
use App\Modules\Inventory\Exceptions\ActiveHoldExistsException;
use App\Modules\Inventory\Exceptions\DropNotOpenException;
use App\Modules\Inventory\Exceptions\PartySizeNotAllowedException;
use App\Modules\Inventory\Exceptions\SlotNotFoundException;
use App\Modules\Inventory\Exceptions\SlotUnavailableException;
use App\Modules\Inventory\Exceptions\SoldOutException;
use App\Modules\Inventory\Support\ExpireOverdueHolds;
use App\Modules\Inventory\Support\HoldCloser;
use App\Modules\Inventory\Support\HoldRows;
use App\Modules\Inventory\Support\SlotGate;
use App\Modules\Inventory\Support\SlotRows;
use App\Shared\Outbox\Outbox;
use App\Shared\Outbox\OutboxTopic;
use App\Shared\Support\Timestamps;
use App\Shared\Support\Transactions;
use Illuminate\Cache\Lock as CacheLock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LogicException;
use stdClass;
use Throwable;

class HoldSlot
{
    private const ONE_ACTIVE_INDEX = 'holds_one_active_per_member_slot';

    public function __construct(
        private readonly Outbox $outbox,
        private readonly SlotGate $gate,
        private readonly ExpireOverdueHolds $expireOverdue,
        private readonly HoldCloser $closer,
    ) {}

    /**
     * C§6.1. Own transaction; `$inTransaction` runs inside it after the hold row exists.
     *
     * @param  (callable(HoldData): void)|null  $inTransaction
     *
     * @throws SlotNotFoundException
     * @throws SlotUnavailableException
     * @throws DropNotOpenException
     * @throws PartySizeNotAllowedException
     * @throws SoldOutException
     * @throws ActiveHoldExistsException
     */
    public function handle(HoldSlotInput $input, ?callable $inTransaction = null): HoldData
    {
        Transactions::assertNone();

        // Calls sharing one idempotency key run one at a time, so the later ones replay instead of
        // taking gate units of their own (C§6.1 step 5).
        $lock = $this->keyLock($input->idempotencyKey);
        $locked = $this->acquire($lock);

        try {
            return $this->hold($input, $inTransaction);
        } finally {
            if ($locked) {
                $lock->release();
            }
        }
    }

    /**
     * @param  (callable(HoldData): void)|null  $inTransaction
     */
    private function hold(HoldSlotInput $input, ?callable $inTransaction): HoldData
    {
        $replay = $this->replay($input->idempotencyKey);

        if ($replay !== null) {
            return $replay;
        }

        $slot = SlotRows::find($input->slotId) ?? throw new SlotNotFoundException;

        $this->validate($slot, $input->partySize);

        // One query for the member's own active holds here: a live one makes this a duplicate (say so
        // before it takes a gate unit other members could then be refused for); an overdue, unswept one
        // would only trip the unique index, so expire it now, before any unit is taken.
        if ($this->settleOwnHolds($input)) {
            throw new ActiveHoldExistsException;
        }

        $unitTaken = $this->takeGateUnit($input->slotId);

        try {
            return $this->place($input, $slot, $inTransaction);
        } catch (SoldOutException $e) {
            // Postgres rejected: never return the unit; rebuild only if the 5 s refresh guard is free.
            $this->gate->refresh($input->slotId);

            throw $e;
        } catch (UniqueConstraintViolationException $e) {
            $this->releaseUnit($unitTaken, $input->slotId);

            return $this->replay($input->idempotencyKey) ?? throw new ActiveHoldExistsException;
        } catch (Throwable $e) {
            $this->releaseUnit($unitTaken, $input->slotId);

            throw $e;
        }
    }

    private function keyLock(string $idempotencyKey): CacheLock
    {
        $store = Cache::store('redis')->getStore();

        if (! $store instanceof LockProvider) {
            throw new LogicException('The redis cache store must support locks.');
        }

        $lock = $store->lock('hold_key:'.hash('sha256', $idempotencyKey), (int) config('inventory.hold_key_lock.seconds'));

        if (! $lock instanceof CacheLock) {
            throw new LogicException('The redis cache store must return blocking locks.');
        }

        return $lock->betweenBlockedAttemptsSleepFor(10);
    }

    /** Waits up to hold_key_lock.wait_seconds; on timeout proceeds unlocked (the unique key still decides). */
    private function acquire(CacheLock $lock): bool
    {
        try {
            return (bool) $lock->block((int) config('inventory.hold_key_lock.wait_seconds'));
        } catch (LockTimeoutException) {
            return false;
        }
    }

    private function validate(stdClass $slot, int $partySize): void
    {
        $now = now();

        if ($slot->venue_status !== 'active' || ! SlotRows::time($slot->starts_at)->greaterThan($now)) {
            throw new SlotUnavailableException;
        }

        if (SlotRows::time($slot->released_at)->greaterThan($now)) {
            throw $slot->drop_id !== null ? new DropNotOpenException : new SlotUnavailableException;
        }

        if ($partySize < (int) $slot->min_party_size || $partySize > (int) $slot->max_party_size) {
            throw new PartySizeNotAllowedException((int) $slot->min_party_size, (int) $slot->max_party_size);
        }
    }

    /**
     * @return bool whether a gate unit was taken (false when the counter could not be built: Postgres decides)
     *
     * @throws SoldOutException
     */
    private function takeGateUnit(int $slotId): bool
    {
        $result = $this->gate->acquire($slotId);

        if ($result === SlotGate::MISSING) {
            $this->gate->rebuild($slotId);
            $result = $this->gate->acquire($slotId);
        }

        if ($result === SlotGate::SOLD_OUT) {
            // Heal a wrong-low counter at most once per refresh window; everyone else is shed without a query.
            if (! $this->gate->refresh($slotId)) {
                throw new SoldOutException;
            }

            $result = $this->gate->acquire($slotId);

            if ($result === SlotGate::SOLD_OUT) {
                throw new SoldOutException;
            }
        }

        return $result >= 0;
    }

    private function releaseUnit(bool $unitTaken, int $slotId): void
    {
        if ($unitTaken) {
            $this->gate->release($slotId);
        }
    }

    /**
     * @param  (callable(HoldData): void)|null  $inTransaction
     *
     * @throws SoldOutException
     * @throws UniqueConstraintViolationException
     * @throws QueryException
     */
    private function place(HoldSlotInput $input, stdClass $slot, ?callable $inTransaction): HoldData
    {
        $expiredOverdue = false;
        $retriedActive = false;
        $retriedDeadlock = false;

        while (true) {
            try {
                $hold = $this->attempt($input, $slot, $inTransaction);
            } catch (UniqueConstraintViolationException $e) {
                // The one-active-hold index fired: an overdue hold of this member may still count as active
                // (a sibling request may already have expired it). Clear it and retry once; a live hold
                // fails the retry the same way and becomes ActiveHoldExistsException in handle().
                if (! $retriedActive && str_contains($e->getMessage(), self::ONE_ACTIVE_INDEX)) {
                    $retriedActive = true;
                    $this->settleOwnHolds($input);

                    continue;
                }

                throw $e;
            } catch (QueryException $e) {
                // Postgres picked this transaction as a deadlock victim: nothing was committed, try once more.
                if (! $retriedDeadlock && self::isDeadlock($e)) {
                    $retriedDeadlock = true;

                    continue;
                }

                throw $e;
            }

            if ($hold !== null) {
                return $hold;
            }

            if ($expiredOverdue) {
                throw new SoldOutException;
            }

            $expiredOverdue = true;
            $this->expireOverdue->handle($input->slotId);
        }
    }

    private static function isDeadlock(QueryException $e): bool
    {
        return ($e->errorInfo[0] ?? $e->getCode()) === '40P01';
    }

    /**
     * @param  (callable(HoldData): void)|null  $inTransaction
     * @return HoldData|null null when the conditional update found no free table
     *
     * @throws UniqueConstraintViolationException
     * @throws QueryException
     */
    private function attempt(HoldSlotInput $input, stdClass $slot, ?callable $inTransaction): ?HoldData
    {
        return DB::transaction(function () use ($input, $slot, $inTransaction): ?HoldData {
            $now = now();

            $updated = DB::selectOne(
                'UPDATE slots SET held_count = held_count + 1, updated_at = ? WHERE id = ? AND held_count + booked_count < capacity RETURNING *',
                [Timestamps::bind($now), $input->slotId],
            );

            if ($updated === null) {
                return null;
            }

            $row = SlotRows::merge($slot, $updated);
            $expiresAt = $now->addSeconds((int) config('inventory.hold_ttl_seconds'));

            $holdId = DB::table('holds')->insertGetId([
                'slot_id' => $input->slotId,
                'member_id' => $input->memberId,
                'party_size' => $input->partySize,
                'status' => HoldStatus::Active->value,
                'idempotency_key' => $input->idempotencyKey,
                'expires_at' => Timestamps::bind($expiresAt),
                'created_at' => Timestamps::bind($now),
                'updated_at' => Timestamps::bind($now),
            ]);

            $this->outbox->record(OutboxTopic::SlotHeld, SlotRows::payload($row, $now));

            $hold = new HoldData($holdId, $input->memberId, $input->partySize, HoldStatus::Active, $expiresAt, $now, SlotRows::toData($row));

            if ($inTransaction !== null) {
                $inTransaction($hold);
            }

            return $hold;
        });
    }

    /**
     * Expires the member's own overdue active holds on this slot.
     *
     * @return bool whether the member still has a live (unexpired) active hold on it
     */
    private function settleOwnHolds(HoldSlotInput $input): bool
    {
        $holds = DB::table('holds')
            ->where('slot_id', $input->slotId)
            ->where('member_id', $input->memberId)
            ->where('status', HoldStatus::Active->value)
            ->get(['id', 'expires_at']);

        $now = now();
        $live = false;

        foreach ($holds as $hold) {
            if ($now->lessThan(SlotRows::time($hold->expires_at))) {
                $live = true;
            } else {
                $this->closer->close((int) $hold->id, HoldStatus::Expired, 'expired', onlyIfOverdue: true);
            }
        }

        return $live;
    }

    private function replay(string $idempotencyKey): ?HoldData
    {
        $hold = DB::table('holds')->where('idempotency_key', $idempotencyKey)->first();

        if ($hold === null) {
            return null;
        }

        $slot = SlotRows::find((int) $hold->slot_id) ?? throw new SlotNotFoundException;

        return HoldRows::toData($hold, SlotRows::toData($slot));
    }
}
