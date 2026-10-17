<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Data\SlotData;
use App\Modules\Inventory\Enums\HoldStatus;
use App\Modules\Inventory\Exceptions\HoldExpiredException;
use App\Modules\Inventory\Exceptions\HoldNotActiveException;
use App\Modules\Inventory\Exceptions\HoldNotFoundException;
use App\Modules\Inventory\Support\SlotRows;
use App\Shared\Outbox\Outbox;
use App\Shared\Outbox\OutboxTopic;
use App\Shared\Support\Timestamps;
use App\Shared\Support\Transactions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

class ConvertHoldToBooking
{
    public function __construct(private readonly Outbox $outbox) {}

    /**
     * Joins the caller's transaction. Does not touch the gate: the unit was taken at hold time.
     *
     * @throws HoldNotFoundException
     * @throws HoldNotActiveException
     * @throws HoldExpiredException
     */
    public function handle(int $holdId, int $memberId): SlotData
    {
        Transactions::assertActive();

        // Lock order drop, slot, hold: HoldSlot and HoldCloser take slot before hold, so none can wait on another.
        $slotId = DB::table('holds')->where('id', $holdId)->where('member_id', $memberId)->value('slot_id');

        if ($slotId === null) {
            throw new HoldNotFoundException;
        }

        $slotId = (int) $slotId;
        $dropId = DB::table('slots')->where('id', $slotId)->value('drop_id');

        if ($dropId !== null) {
            // Serialises conversions within a drop so the "last slot sold" check cannot be missed by two racers.
            DB::table('drops')->where('id', $dropId)->lockForUpdate()->value('id');
        }

        DB::table('slots')->where('id', $slotId)->lockForUpdate()->value('id');

        $hold = DB::table('holds')->where('id', $holdId)->lockForUpdate()->first();

        if ($hold === null || (int) $hold->member_id !== $memberId) {
            throw new HoldNotFoundException;
        }

        if ($hold->status !== HoldStatus::Active->value) {
            throw new HoldNotActiveException(HoldStatus::from((string) $hold->status));
        }

        $now = now();

        if (! $now->lessThan(SlotRows::time($hold->expires_at))) {
            throw new HoldExpiredException;
        }

        DB::table('holds')->where('id', $holdId)->update([
            'status' => HoldStatus::Converted->value,
            'closed_at' => Timestamps::bind($now),
            'close_reason' => 'converted',
            'updated_at' => Timestamps::bind($now),
        ]);

        $updated = DB::selectOne(
            'UPDATE slots SET held_count = held_count - 1, booked_count = booked_count + 1, updated_at = ?,
                sold_out_at = CASE WHEN sold_out_at IS NULL AND booked_count + 1 >= capacity THEN ?::timestamptz ELSE sold_out_at END
             WHERE id = ? AND held_count > 0 RETURNING *',
            [Timestamps::bind($now), Timestamps::bind($now), $slotId],
        );

        if ($updated === null) {
            throw new LogicException("Slot {$slotId} has no held unit for active hold {$holdId}.");
        }

        $slot = SlotRows::find($slotId) ?? throw new LogicException("Slot {$slotId} vanished.");

        $this->outbox->record(OutboxTopic::SlotBooked, SlotRows::payload($slot, $now));

        if ($dropId !== null) {
            $this->markDropSoldOut((int) $dropId, (int) $slot->venue_id, $now);
        }

        return SlotRows::toData($slot);
    }

    private function markDropSoldOut(int $dropId, int $venueId, CarbonImmutable $now): void
    {
        $hasRoom = DB::table('slots')->where('drop_id', $dropId)->whereColumn('booked_count', '<', 'capacity')->exists();

        if ($hasRoom) {
            return;
        }

        $marked = DB::table('drops')->where('id', $dropId)->whereNull('sold_out_at')->update(['sold_out_at' => Timestamps::bind($now), 'updated_at' => Timestamps::bind($now)]);

        if ($marked === 1) {
            $this->outbox->record(OutboxTopic::DropSoldOut, [
                'drop_id' => $dropId,
                'venue_id' => $venueId,
                'sold_out_at' => SlotRows::iso($now),
            ]);
        }
    }
}
