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

        [$unitTaken, $refreshOwner] = $this->takeGateUnit($input->slotId);
        $pendingCompleted = false;

        try {
            return $this->place($input, $slot, $inTransaction);
        } catch (SoldOutException $e) {
            if ($unitTaken) {
                $this->gate->completeAttempt($input->slotId);
                $pendingCompleted = true;
            }
            // Postgres rejected: rebuild under the same guard; never return a gate unit.
            $refreshOwner
                ? $this->gate->rebuild($input->slotId, force: true)
                : $this->gate->refresh($input->slotId);
            throw $e;
        } catch (UniqueConstraintViolationException $e) {
            $this->releaseUnit($unitTaken, $input->slotId);
            if ($unitTaken) {
                $this->gate->completeAttempt($input->slotId);
                $pendingCompleted = true;
            }
            if ($refreshOwner) {
                $this->gate->rebuild($input->slotId, force: true);
            }

            return $this->replay($input->idempotencyKey) ?? throw new ActiveHoldExistsException;
        } catch (Throwable $e) {
            $this->releaseUnit($unitTaken, $input->slotId);
            if ($unitTaken) {
                $this->gate->completeAttempt($input->slotId);
                $pendingCompleted = true;
            }
            if ($refreshOwner) {
                $this->gate->rebuild($input->slotId, force: true);
            }

            throw $e;
        } finally {
            if ($unitTaken && ! $pendingCompleted) {
                $this->gate->completeAttempt($input->slotId);
            }
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

    /** @return array{bool, bool} whether a unit was taken and whether this request owns the refresh window */
    private function takeGateUnit(int $slotId): array
    {
        $refreshOwner = false;
        $result = $this->gate->acquire($slotId);

        if ($result === SlotGate::MISSING) {
            $this->gate->rebuild($slotId);
            $result = $this->gate->acquire($slotId);
        }

        if ($result === SlotGate::SOLD_OUT) {
            $refreshOwner = $this->gate->refresh($slotId);
            if (! $refreshOwner || $this->gate->acquire($slotId) === SlotGate::SOLD_OUT) {
                throw new SoldOutException;
            }

            $result = $this->gate->value($slotId);
        }

        return [$result !== SlotGate::MISSING, $refreshOwner];
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
     */
    private function place(HoldSlotInput $input, stdClass $slot, ?callable $inTransaction): HoldData
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $hold = $this->attempt($input, $slot, $inTransaction);
            } catch (UniqueConstraintViolationException $e) {
                // An overdue, unswept hold of this member still counts as active in the index: expire it, retry once.
                if ($attempt === 0 && str_contains($e->getMessage(), self::ONE_ACTIVE_INDEX) && $this->hasOverdueHold($input)) {
                    $this->expireOverdue->handle($input->slotId);

                    continue;
                }

                throw $e;
            }

            if ($hold !== null) {
                return $hold;
            }

            if ($attempt === 0) {
                $this->expireOverdue->handle($input->slotId);
            }
        }

        throw new SoldOutException;
    }

    /**
     * @param  (callable(HoldData): void)|null  $inTransaction
     * @return HoldData|null null when the conditional update found no free table
     *
     * @throws UniqueConstraintViolationException
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
