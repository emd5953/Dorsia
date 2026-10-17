<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Shared\Outbox\Outbox;
use App\Shared\Outbox\OutboxTopic;
use App\Shared\Support\Timestamps;
use Illuminate\Support\Facades\DB;

/**
 * Writes `hold.expiring` once per active hold that is within the notice window of its expiry.
 */
final class NotifyExpiringHolds
{
    public function __construct(private readonly Outbox $outbox) {}

    /**
     * @return int notices written
     */
    public function handle(int $limit): int
    {
        $now = now();
        $horizon = $now->addSeconds((int) config('inventory.hold_expiring_notice_seconds'));

        $ids = DB::table('holds')
            ->where('status', 'active')
            ->whereNull('expiring_notified_at')
            ->where('expires_at', '>', Timestamps::bind($now))
            ->where('expires_at', '<=', Timestamps::bind($horizon))
            ->orderBy('expires_at')
            ->limit($limit)
            ->pluck('id');

        $written = 0;

        foreach ($ids as $id) {
            $written += DB::transaction(function () use ($id, $now): int {
                // The conditional update is the once-only guard, even with two sweepers running.
                $hold = DB::selectOne(
                    "UPDATE holds SET expiring_notified_at = ?, updated_at = ?
                     WHERE id = ? AND status = 'active' AND expiring_notified_at IS NULL AND expires_at > ?
                     RETURNING *",
                    [Timestamps::bind($now), Timestamps::bind($now), $id, Timestamps::bind($now)],
                );

                if ($hold === null) {
                    return 0;
                }

                $this->outbox->record(OutboxTopic::HoldExpiring, [
                    'hold_id' => (int) $hold->id,
                    'member_id' => (int) $hold->member_id,
                    'slot_id' => (int) $hold->slot_id,
                    'expires_at' => SlotRows::iso(SlotRows::time($hold->expires_at)),
                ]);

                return 1;
            });
        }

        return $written;
    }
}
