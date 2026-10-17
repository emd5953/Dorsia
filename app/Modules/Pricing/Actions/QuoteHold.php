<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Actions;

use App\Modules\Pricing\Data\QuoteData;
use App\Modules\Pricing\Data\QuoteHoldInput;
use App\Modules\Pricing\Models\DemandSignal;
use App\Modules\Pricing\Models\PriceQuote;
use App\Modules\Pricing\Support\DemandSnapshot;
use App\Modules\Pricing\Support\PartyBands;
use App\Modules\Pricing\Support\PriceCalculator;
use App\Shared\Support\Transactions;
use Illuminate\Support\Facades\DB;
use LogicException;

class QuoteHold
{
    /**
     * Prices a hold from the latest stored signals and locks the result to it. Never waits on the signals job.
     *
     * @throws LogicException outside a database transaction
     */
    public function handle(QuoteHoldInput $input): QuoteData
    {
        Transactions::assertActive();

        $existing = PriceQuote::query()->where('hold_id', $input->holdId)->first();

        if ($existing !== null) {
            return $existing->toData();
        }

        $local = $input->startsAt->setTimezone($input->venueTimezone);
        $band = PartyBands::forPartySize($input->partySize);

        $breakdown = PriceCalculator::fromConfig()->calculate(
            $input->basePerPerson->cents,
            $input->startsAt,
            $input->venueTimezone,
            $band,
            $this->snapshot($input->venueId, $local->dayOfWeekIso, $local->hour, $band),
        );

        // ON CONFLICT DO NOTHING: a concurrent quote for the same hold wins; we read its row back below.
        DB::table('price_quotes')->insertOrIgnore([
            'hold_id' => $input->holdId,
            'slot_id' => $input->slotId,
            'venue_id' => $input->venueId,
            'party_size' => $input->partySize,
            'per_person_cents' => $breakdown->perPersonCents,
            'amount_cents' => $breakdown->perPersonCents * $input->partySize,
            'currency' => $input->basePerPerson->currency->value,
            'inputs' => json_encode($breakdown->inputs, JSON_THROW_ON_ERROR),
            'expires_at' => $input->expiresAt->utc()->format('Y-m-d H:i:s.uP'),
            'created_at' => now()->utc()->format('Y-m-d H:i:s.uP'),
        ]);

        return PriceQuote::query()->where('hold_id', $input->holdId)->firstOrFail()->toData();
    }

    private function snapshot(int $venueId, int $weekday, int $hour, string $band): ?DemandSnapshot
    {
        $rows = DemandSignal::query()
            ->where('venue_id', $venueId)
            ->where('weekday', $weekday)
            ->where('hour', $hour)
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $bandRow = $rows->firstWhere('party_band', $band);
        $latest = $rows->sortByDesc('computed_at')->firstOrFail();

        return new DemandSnapshot(
            computedAt: $latest->computed_at->utc()->toIso8601ZuluString(),
            bandUnmet: $bandRow->unmet_searches ?? 0,
            venueHourUnmet: (int) $rows->sum('unmet_searches'),
            selloutSecondsP50: $rows->pluck('sellout_seconds_p50')->first(static fn (?int $p50): bool => $p50 !== null),
        );
    }
}
