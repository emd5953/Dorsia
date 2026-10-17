<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Enums\HoldStatus;
use App\Shared\Outbox\Outbox;
use App\Shared\Outbox\OutboxTopic;
use App\Shared\Support\Timestamps;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Closes an active hold and gives its table back: status, held_count, slot.released, then the gate
 * unit after commit. Shared by ExpireHold and ReleaseHold.
 */
final class HoldCloser
{
    public function __construct(private readonly Outbox $outbox, private readonly SlotGate $gate) {}

    /**
     * Lock order is slot, then hold, the same as HoldSlot (slot update, then hold insert), so the two
     * can never wait on each other.
     *
     * @param  bool  $onlyIfOverdue  expire semantics: do nothing before expires_at
     * @param  bool  $releaseGate  false when the caller rebuilds the gate from Postgres right after
     * @return bool true only if this call closed the hold
     */
    public function close(int $holdId, HoldStatus $status, string $reason, bool $onlyIfOverdue, bool $releaseGate = true): bool
    {
        $slotId = DB::table('holds')->where('id', $holdId)->value('slot_id');

        if ($slotId === null) {
            return false;
        }

        return DB::transaction(function () use ($holdId, $slotId, $status, $reason, $onlyIfOverdue, $releaseGate): bool {
            DB::table('slots')->where('id', $slotId)->lockForUpdate()->value('id');

            $hold = DB::table('holds')->where('id', $holdId)->lockForUpdate()->first();

            if ($hold === null || $hold->status !== HoldStatus::Active->value) {
                return false;
            }

            $now = now();

            if ($onlyIfOverdue && $now->lessThan(SlotRows::time($hold->expires_at))) {
                return false;
            }

            DB::table('holds')->where('id', $holdId)->update([
                'status' => $status->value,
                'closed_at' => Timestamps::bind($now),
                'close_reason' => $reason,
                'updated_at' => Timestamps::bind($now),
            ]);

            $slot = DB::selectOne(
                'UPDATE slots SET held_count = held_count - 1, updated_at = ? WHERE id = ? AND held_count > 0 RETURNING *',
                [Timestamps::bind($now), $hold->slot_id],
            );

            if ($slot === null) {
                throw new LogicException("Slot {$hold->slot_id} has no held unit for active hold {$holdId}.");
            }

            $this->outbox->record(OutboxTopic::SlotReleased, SlotRows::payload($slot, $now, $reason));

            if ($releaseGate) {
                $slotId = (int) $hold->slot_id;
                DB::afterCommit(fn () => $this->gate->release($slotId));
            }

            return true;
        });
    }
}
