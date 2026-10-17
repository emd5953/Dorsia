<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Data\SelloutStat;
use App\Shared\Support\Timestamps;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

class GetSelloutStats
{
    /**
     * Median seconds from release to sell-out per venue, venue-local ISO weekday and local hour.
     *
     * @return list<SelloutStat>
     */
    public function handle(CarbonImmutable $since): array
    {
        $rows = DB::select(
            'SELECT s.venue_id,
                    EXTRACT(ISODOW FROM s.starts_at AT TIME ZONE v.timezone)::int AS weekday,
                    EXTRACT(HOUR FROM s.starts_at AT TIME ZONE v.timezone)::int AS hour,
                    ROUND((percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (s.sold_out_at - s.released_at))))::numeric)::int AS p50
             FROM slots s
             JOIN venues v ON v.id = s.venue_id
             WHERE s.sold_out_at >= ?
             GROUP BY s.venue_id, weekday, hour
             ORDER BY s.venue_id, weekday, hour',
            [Timestamps::bind($since)],
        );

        return array_values(array_map(
            fn (stdClass $row): SelloutStat => new SelloutStat((int) $row->venue_id, (int) $row->weekday, (int) $row->hour, (int) $row->p50),
            $rows,
        ));
    }
}
