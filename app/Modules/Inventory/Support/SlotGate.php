<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use LogicException;

/**
 * Redis counter that sheds losing requests before they reach Postgres (C§6.1).
 * Postgres stays the final word; the counter is only ever a hint.
 */
final class SlotGate
{
    public const MISSING = -2;

    public const SOLD_OUT = -1;

    private const ACQUIRE = <<<'LUA'
local v = redis.call('GET', KEYS[1])
if not v then return -2 end
local n = redis.call('DECR', KEYS[1])
if n < 0 then redis.call('INCR', KEYS[1]); return -1 end
return n
LUA;

    private const RELEASE = <<<'LUA'
if redis.call('EXISTS', KEYS[1]) == 1 then return redis.call('INCR', KEYS[1]) end
return nil
LUA;

    private const POLL_MICROSECONDS = 5_000;

    /**
     * @return int units left after taking one, or MISSING / SOLD_OUT
     */
    public function acquire(int $slotId): int
    {
        return (int) $this->connection()->command('eval', [self::ACQUIRE, [$this->key($slotId)], 1]);
    }

    /** Returns one unit; does nothing when the key is missing. */
    public function release(int $slotId): void
    {
        $this->connection()->command('eval', [self::RELEASE, [$this->key($slotId)], 1]);
    }

    /** Current counter value, or null when the key is missing. */
    public function value(int $slotId): ?int
    {
        $value = $this->connection()->command('get', [$this->key($slotId)]);

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Rebuilds the counter as capacity - booked - held from Postgres, under a lock.
     *
     * Without $force an existing key is left alone (double-checked under the lock), and a caller that
     * loses the lock race waits for the winner's key instead of rebuilding again. With $force (Postgres
     * just proved the counter wrong) the value is always overwritten; a caller that loses the lock
     * race skips, because someone else is already rebuilding.
     *
     * @return bool whether the key exists when this returns
     */
    public function rebuild(int $slotId, bool $force = false): bool
    {
        $seconds = (int) config('inventory.gate.rebuild_lock_seconds');
        $store = Cache::store('redis')->getStore();

        if (! $store instanceof LockProvider) {
            throw new LogicException('The redis cache store must support locks.');
        }

        $lock = $store->lock("slot_gate:{$slotId}:lock", $seconds);

        if (! $lock->get()) {
            return $force ? $this->value($slotId) !== null : $this->waitForKey($slotId, $seconds);
        }

        try {
            if (! $force && $this->value($slotId) !== null) {
                return true;
            }

            $slot = DB::table('slots')->where('id', $slotId)->first(['capacity', 'booked_count', 'held_count', 'starts_at']);

            if ($slot === null) {
                return false;
            }

            $ttl = max(1, (int) now()->diffInSeconds(CarbonImmutable::parse((string) $slot->starts_at)->addDay(), false));
            $value = max(0, (int) $slot->capacity - (int) $slot->booked_count - (int) $slot->held_count);

            $this->connection()->command('set', [$this->key($slotId), $value, ['EX' => $ttl]]);

            return true;
        } finally {
            $lock->release();
        }
    }

    private function waitForKey(int $slotId, int $seconds): bool
    {
        $deadline = microtime(true) + $seconds;

        do {
            if ($this->value($slotId) !== null) {
                return true;
            }

            usleep(self::POLL_MICROSECONDS);
        } while (microtime(true) < $deadline);

        return false;
    }

    private function connection(): Connection
    {
        return Redis::connection();
    }

    private function key(int $slotId): string
    {
        return "slot_gate:{$slotId}";
    }
}
