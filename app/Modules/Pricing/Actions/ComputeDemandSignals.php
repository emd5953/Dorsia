<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Actions;

use App\Modules\Inventory\Actions\GetSelloutStats;
use App\Modules\Pricing\Support\PartyBands;
use App\Shared\Support\Timestamps;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ComputeDemandSignals
{
    private const CHUNK = 500;

    private const LOCK_NAME = 'pricing:compute-demand-signals';

    public function __construct(private readonly GetSelloutStats $sellout) {}

    /**
     * Rebuilds demand_signals from the last pricing.signals_window_days of searches and sell-outs.
     *
     * @return int rows written
     */
    public function handle(CarbonImmutable $now): int
    {
        return DB::transaction(function () use ($now): int {
            // Serialises overlapping runs (manual or scheduled). Everything below is read and written under
            // the lock, so the run that commits last also read last and cannot overwrite newer rows.
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [self::LOCK_NAME]);

            $rows = $this->rows($now);

            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                DB::table('demand_signals')->upsert(
                    $chunk,
                    ['venue_id', 'weekday', 'hour', 'party_band'],
                    ['sellout_seconds_p50', 'unmet_searches', 'bid_count', 'computed_at'],
                );
            }

            DB::table('demand_signals')->where('computed_at', '<', self::timestamp($now))->delete();

            return count($rows);
        });
    }

    /**
     * @return list<array<string, int|string|null>>
     */
    private function rows(CarbonImmutable $now): array
    {
        $since = $now->subDays(self::intConfig('pricing.signals_window_days'));

        // keyed "venue|weekday|hour" => ['unmet' => [band => count], 'p50' => ?int]
        $combos = [];

        $unmet = DB::table('search_events')
            ->where('had_availability', false)
            ->where('created_at', '>=', self::timestamp($since))
            ->groupBy('venue_id', 'local_weekday', 'requested_hour', 'party_band')
            ->selectRaw('venue_id, local_weekday, requested_hour, party_band, COUNT(*) AS total')
            ->get();

        foreach ($unmet as $row) {
            $key = self::key((int) $row->venue_id, (int) $row->local_weekday, (int) $row->requested_hour);
            $combos[$key]['unmet'][(string) $row->party_band] = (int) $row->total;
        }

        // Inventory is read only through this action.
        foreach ($this->sellout->handle($since) as $stat) {
            $combos[self::key($stat->venueId, $stat->weekday, $stat->hour)]['p50'] = $stat->p50Seconds;
        }

        ksort($combos);

        $rows = [];

        foreach ($combos as $key => $combo) {
            [$venueId, $weekday, $hour] = array_map('intval', explode('|', $key));

            foreach (PartyBands::keys() as $band) {
                $rows[] = [
                    'venue_id' => $venueId,
                    'weekday' => $weekday,
                    'hour' => $hour,
                    'party_band' => $band,
                    'sellout_seconds_p50' => $combo['p50'] ?? null,
                    'unmet_searches' => $combo['unmet'][$band] ?? 0,
                    'bid_count' => 0,
                    'computed_at' => self::timestamp($now),
                ];
            }
        }

        return $rows;
    }

    private static function timestamp(CarbonImmutable $at): string
    {
        return Timestamps::bind($at);
    }

    private static function key(int $venueId, int $weekday, int $hour): string
    {
        return "{$venueId}|{$weekday}|{$hour}";
    }

    private static function intConfig(string $key): int
    {
        $value = config($key);

        return is_int($value) ? $value : 0;
    }
}
