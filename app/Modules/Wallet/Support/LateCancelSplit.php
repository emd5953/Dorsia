<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Support;

use App\Shared\Money\Money;
use InvalidArgumentException;

/**
 * Late-cancel split (D14): each share is floored, the rounding remainder stays with the venue.
 */
final readonly class LateCancelSplit
{
    public function __construct(public Money $member, public Money $platform, public Money $venueRetained) {}

    /**
     * @throws InvalidArgumentException if the shares exceed 100%
     */
    public static function calculate(Money $amount, int $memberBps, int $platformBps): self
    {
        if ($memberBps < 0 || $platformBps < 0 || $memberBps + $platformBps > 10000) {
            throw new InvalidArgumentException('Late-cancel shares must be non-negative and total at most 10000 bps.');
        }

        $member = $amount->bps($memberBps);
        $platform = $amount->bps($platformBps);

        return new self($member, $platform, $amount->subtract($member)->subtract($platform));
    }
}
