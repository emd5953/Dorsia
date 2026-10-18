<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Enums\HoldStatus;
use App\Shared\Support\Timestamps;
use Illuminate\Support\Facades\DB;

/**
 * Expires active holds whose expires_at has passed, one transaction per hold.
 * Used by HoldSlot (one slot) and the sweeper (everything, batched).
 */
final class ExpireOverdueHolds
{
    public function __construct(private readonly HoldCloser $closer) {}

    /**
     * @param  bool  $releaseGate  false when the caller rebuilds the gate from Postgres right after
     * @return int holds this call expired
     */
    public function handle(?int $slotId = null, ?int $limit = null, bool $releaseGate = true): int
    {
        $query = DB::table('holds')
            ->where('status', 'active')
            ->where('expires_at', '<=', Timestamps::bind(now()))
            ->orderBy('expires_at')
            ->orderBy('id');

        if ($slotId !== null) {
            $query->where('slot_id', $slotId);
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        $expired = 0;

        foreach ($query->pluck('id') as $holdId) {
            if ($this->closer->close((int) $holdId, HoldStatus::Expired, 'expired', onlyIfOverdue: true, releaseGate: $releaseGate)) {
                $expired++;
            }
        }

        return $expired;
    }
}
