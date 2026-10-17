<?php

declare(strict_types=1);

namespace App\Shared\Support;

use Carbon\CarbonInterface;

/**
 * Microsecond timestamp format (C§3). Laravel binds dates as `Y-m-d H:i:s` unless told otherwise,
 * which truncates to seconds even on a timestamptz(6) column.
 */
final class Timestamps
{
    public const FORMAT = 'Y-m-d H:i:s.uP';

    /** Formats an instant in UTC for use as a query binding. */
    public static function bind(CarbonInterface $time): string
    {
        return $time->copy()->utc()->format(self::FORMAT);
    }
}
