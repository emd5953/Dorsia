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
use App\Modules\Inventory\Support\HoldRows;
use App\Modules\Inventory\Support\SlotGate;
use App\Modules\Inventory\Support\SlotRows;
use App\Shared\Outbox\Outbox;
use App\Shared\Outbox\OutboxTopic;
use App\Shared\Support\Timestamps;
use App\Shared\Support\Transactions;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use stdClass;
use Throwable;

class HoldSlot
{
    private const ONE_ACTIVE_INDEX = 'holds_one_active_per_member_slot';

    public function __construct(
        private readonly Outbox $outbox,
        private readonly SlotGate $gate,
        private readonly ExpireOverdueHolds $expireOverdue,
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

        $replay = $this->replay($input->idempotencyKey);

        if ($replay !== null) {
            return $replay;
        }

        $slot = SlotRows::find($input->slotId) ?? throw new SlotNotFoundException;

        $this->validate($slot, $input->partySize);

        // A member's own live hold means this is a duplicate: say so before it takes a gate unit that
        // other members could then be refused for.
        if ($this->hasLiveHold($input)) {
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
        $retriedDeadlock = false;

        while (true) {
            try {
                $hold = $this->attempt($input, $slot, $inTransaction);
            } catch (UniqueConstraintViolationException $e) {
                // An overdue, unswept hold of this member still counts as active in the index: expire it, retry once.
                if (! $expiredOverdue && str_contains($e->getMessage(), self::ONE_ACTIVE_INDEX) && $this->hasOverdueHold($input)) {
                    $expiredOverdue = true;
                    $this->expireOverdue->handle($input->slotId);

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

    private function hasLiveHold(HoldSlotInput $input): bool
    {
        return DB::table('holds')
            ->where('slot_id', $input->slotId)
            ->where('member_id', $input->memberId)
            ->where('status', HoldStatus::Active->value)
            ->where('expires_at', '>', Timestamps::bind(now()))
            ->exists();
    }

    private function hasOverdueHold(HoldSlotInput $input): bool
    {
        return DB::table('holds')
            ->where('slot_id', $input->slotId)
            ->where('member_id', $input->memberId)
            ->where('status', HoldStatus::Active->value)
            ->where('expires_at', '<=', Timestamps::bind(now()))
            ->exists();
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
