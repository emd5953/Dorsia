<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Shared\Support\Timestamps;
use Illuminate\Support\Facades\DB;

/**
 * Bounds counter drift: rebuilds the gate of every upcoming slot touched in the last few minutes (C§6.1).
 */
final class RebuildRecentGates
{
    public function __construct(private readonly SlotGate $gate) {}

    /**
     * @return int gates rebuilt
     */
    public function handle(): int
    {
        $now = now();

        $ids = DB::table('slots')
            ->where('starts_at', '>', Timestamps::bind($now))
            ->where('updated_at', '>=', Timestamps::bind($now->subMinutes((int) config('inventory.gate.rebuild_recent_minutes'))))
            ->pluck('id');

        foreach ($ids as $id) {
            $this->gate->rebuild((int) $id, force: true);
        }

        return $ids->count();
    }
}
