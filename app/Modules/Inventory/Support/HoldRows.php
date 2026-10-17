<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Data\HoldData;
use App\Modules\Inventory\Data\SlotData;
use App\Modules\Inventory\Enums\HoldStatus;
use Illuminate\Support\Facades\DB;
use stdClass;

final class HoldRows
{
    public static function find(int $holdId): ?stdClass
    {
        $row = DB::table('holds')->where('id', $holdId)->first();

        return $row instanceof stdClass ? $row : null;
    }

    public static function toData(stdClass $hold, SlotData $slot): HoldData
    {
        return new HoldData(
            id: (int) $hold->id,
            memberId: (int) $hold->member_id,
            partySize: (int) $hold->party_size,
            status: HoldStatus::from((string) $hold->status),
            expiresAt: SlotRows::time($hold->expires_at),
            createdAt: SlotRows::time($hold->created_at),
            slot: $slot,
        );
    }
}
