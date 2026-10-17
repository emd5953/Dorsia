<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Support;

use Carbon\CarbonImmutable;

/** C§10 steps 1-5. Pure integer math: basis points and cents only. */
final class PriceCalculator
{
    private const FORMULA_VERSION = 1;

    private const BPS = 10000;

    /**
     * @param  array<string, array{bps: int, days?: list<int>, hours?: list<int>}>  $tiers
     * @param  list<array{max_seconds: int, bps: int}>  $selloutRules
     */
    public function __construct(
        private readonly array $tiers,
        private readonly int $thinBandThreshold,
        private readonly int $perUnmetSearchBps,
        private readonly int $unmetCapBps,
        private readonly array $selloutRules,
        private readonly int $roundingCents,
        private readonly int $minPerPersonCents,
    ) {}

    public static function fromConfig(): self
    {
        /** @var array<string, array{bps: int, days?: list<int>, hours?: list<int>}> $tiers */
        $tiers = config('pricing.tiers');
        /** @var list<array{max_seconds: int, bps: int}> $sellout */
        $sellout = config('pricing.demand.sellout');

        return new self(
            $tiers,
            self::intConfig('pricing.thin_band_threshold'),
            self::intConfig('pricing.demand.per_unmet_search_bps'),
            self::intConfig('pricing.demand.unmet_cap_bps'),
            $sellout,
            self::intConfig('pricing.rounding_cents'),
            self::intConfig('pricing.min_per_person_cents'),
        );
    }

    public function calculate(
        int $basePerPersonCents,
        CarbonImmutable $startsAt,
        string $venueTimezone,
        string $partyBand,
        ?DemandSnapshot $signals,
    ): PriceBreakdown {
        $local = $startsAt->setTimezone($venueTimezone);
        $weekday = $local->dayOfWeekIso;
        $hour = $local->hour;

        [$tier, $tierBps] = $this->tier($weekday, $hour);

        $bandUnmet = $signals->bandUnmet ?? 0;
        $venueHourUnmet = $signals->venueHourUnmet ?? 0;
        $usedFallback = $signals !== null && $bandUnmet < $this->thinBandThreshold;
        $unmetUsed = $usedFallback ? $venueHourUnmet : $bandUnmet;
        $unmetBps = min($unmetUsed * $this->perUnmetSearchBps, $this->unmetCapBps);
        $selloutSeconds = $signals?->selloutSecondsP50;
        $selloutBps = $this->selloutBps($selloutSeconds);
        $demandBps = self::BPS + $unmetBps + $selloutBps;

        $divisor = self::BPS * self::BPS * $this->roundingCents;
        $perPerson = intdiv($basePerPersonCents * $tierBps * $demandBps + intdiv($divisor, 2), $divisor) * $this->roundingCents;
        $perPerson = max($perPerson, $this->minPerPersonCents);

        return new PriceBreakdown($perPerson, [
            'formula_version' => self::FORMULA_VERSION,
            'base_per_person_cents' => $basePerPersonCents,
            'local_weekday' => $weekday,
            'local_hour' => $hour,
            'tier' => $tier,
            'tier_bps' => $tierBps,
            'party_band' => $partyBand,
            'signals_computed_at' => $signals?->computedAt,
            'band_unmet' => $bandUnmet,
            'venue_hour_unmet' => $venueHourUnmet,
            'used_fallback' => $usedFallback,
            'unmet_used' => $unmetUsed,
            'sellout_seconds_p50' => $selloutSeconds,
            'unmet_bps' => $unmetBps,
            'sellout_bps' => $selloutBps,
            'demand_bps' => $demandBps,
            'rounding_cents' => $this->roundingCents,
            'min_per_person_cents' => $this->minPerPersonCents,
        ]);
    }

    /**
     * @return array{string, int}
     */
    private function tier(int $weekday, int $hour): array
    {
        foreach (['peak', 'shoulder'] as $name) {
            $tier = $this->tiers[$name];

            if (in_array($weekday, $tier['days'] ?? [], true) && in_array($hour, $tier['hours'] ?? [], true)) {
                return [$name, $tier['bps']];
            }
        }

        return ['off_peak', $this->tiers['off_peak']['bps']];
    }

    private function selloutBps(?int $seconds): int
    {
        if ($seconds === null) {
            return 0;
        }

        foreach ($this->selloutRules as $rule) {
            if ($seconds <= $rule['max_seconds']) {
                return $rule['bps'];
            }
        }

        return 0;
    }

    private static function intConfig(string $key): int
    {
        $value = config($key);

        return is_int($value) ? $value : 0;
    }
}
