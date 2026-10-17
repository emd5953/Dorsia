<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Support;

use InvalidArgumentException;

final class PartyBands
{
    /**
     * Ordered band keys from config.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_map(static fn (array $band): string => $band['key'], self::bands());
    }

    /**
     * First configured band that contains the party size.
     *
     * @throws InvalidArgumentException if no band matches
     */
    public static function forPartySize(int $partySize): string
    {
        foreach (self::bands() as $band) {
            if ($partySize >= $band['min'] && ($band['max'] === null || $partySize <= $band['max'])) {
                return $band['key'];
            }
        }

        throw new InvalidArgumentException("No party band for party size {$partySize}.");
    }

    /**
     * @return list<array{key: string, min: int, max: int|null}>
     */
    private static function bands(): array
    {
        /** @var list<array{key: string, min: int, max: int|null}> $bands */
        $bands = config('pricing.bands');

        return $bands;
    }
}
