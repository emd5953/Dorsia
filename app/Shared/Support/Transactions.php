<?php

declare(strict_types=1);

namespace App\Shared\Support;

use Illuminate\Support\Facades\DB;
use LogicException;

final class Transactions
{
    /** Transaction level that counts as "no transaction" (D17); tests set it after RefreshDatabase opens its wrapper. */
    public static int $baseLevel = 0;

    /**
     * @throws LogicException unless DB::transactionLevel() > self::$baseLevel
     */
    public static function assertActive(): void
    {
        if (DB::transactionLevel() <= self::$baseLevel) {
            throw new LogicException('This operation must run inside a database transaction.');
        }
    }

    /**
     * @throws LogicException if DB::transactionLevel() > self::$baseLevel
     */
    public static function assertNone(): void
    {
        if (DB::transactionLevel() > self::$baseLevel) {
            throw new LogicException('This operation must not run inside a database transaction.');
        }
    }
}
