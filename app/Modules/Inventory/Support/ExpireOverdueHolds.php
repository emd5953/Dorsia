<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Actions\ExpireHold;
use App\Shared\Support\Timestamps;
use Illuminate\Support\Facades\DB;

/**
 * Expires active holds whose expires_at has passed, one transaction per hold.
 * Used by HoldSlot (one slot) and the sweeper (everything, batched).
 */
final class ExpireOverdueHolds
{
    public function __construct(private readonly ExpireHold $expireHold) {}

    /**
     * @return int holds this call expired
     */
    public function handle(?int $slotId = null, ?int $limit = null): int
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
            if ($this->expireHold->handle((int) $holdId)) {
                $expired++;
            }
        }

        return $expired;
    }
}
