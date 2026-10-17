<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Console;

use App\Modules\Inventory\Support\ExpireOverdueHolds;
use App\Modules\Inventory\Support\NotifyExpiringHolds;
use App\Modules\Inventory\Support\RebuildRecentGates;
use Illuminate\Console\Command;

final class ExpireHoldsCommand extends Command
{
    protected $signature = 'inventory:expire-holds';

    protected $description = 'Expire overdue holds, announce expiring ones and rebuild recently touched slot gates';

    public function handle(ExpireOverdueHolds $expire, NotifyExpiringHolds $notify, RebuildRecentGates $rebuild): int
    {
        $batch = (int) config('inventory.sweep_batch');

        $expired = $expire->handle(limit: $batch);
        $notified = $notify->handle($batch);
        $rebuilt = $rebuild->handle();

        $this->info("Expired {$expired} holds, notified {$notified}, rebuilt {$rebuilt} gates.");

        return self::SUCCESS;
    }
}
