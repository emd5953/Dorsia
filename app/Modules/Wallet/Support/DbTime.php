<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Support;

use Carbon\CarbonInterface;

/**
 * Microsecond-precision UTC timestamp for query bindings (C§3); Laravel's default format truncates to seconds.
 */
final class DbTime
{
    public const FORMAT = 'Y-m-d H:i:s.uP';

    public static function format(CarbonInterface $time): string
    {
        return $time->utc()->format(self::FORMAT);
    }
}
