<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Data\SlotData;
use App\Modules\Inventory\Exceptions\SlotNotFoundException;
use App\Modules\Inventory\Support\SlotGate;
use App\Modules\Inventory\Support\SlotRows;
use App\Shared\Outbox\Outbox;
use App\Shared\Outbox\OutboxTopic;
use App\Shared\Support\Timestamps;
use App\Shared\Support\Transactions;
use Illuminate\Support\Facades\DB;

class ReleaseBooking
{
    public function __construct(private readonly Outbox $outbox, private readonly SlotGate $gate) {}

    /**
     * Joins the caller's transaction. The gate unit returns only after that transaction commits.
     * A slot with no booked table is left unchanged (no event, no gate unit).
     *
     * @throws SlotNotFoundException
     */
    public function handle(int $slotId): SlotData
    {
        Transactions::assertActive();

        $now = now();

        $updated = DB::selectOne(
            'UPDATE slots SET booked_count = booked_count - 1, updated_at = ? WHERE id = ? AND booked_count > 0 RETURNING *',
            [Timestamps::bind($now), $slotId],
        );

        $joined = SlotRows::find($slotId) ?? throw new SlotNotFoundException;

        if ($updated === null) {
            return SlotRows::toData($joined);
        }

        $this->outbox->record(OutboxTopic::SlotReleased, SlotRows::payload($joined, $now, 'cancelled'));

        $generation = $this->gate->generation($slotId);
        DB::afterCommit(fn () => $this->gate->releaseIfGeneration($slotId, $generation));

        return SlotRows::toData($joined);
    }
}
