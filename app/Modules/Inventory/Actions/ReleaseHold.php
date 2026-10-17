<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Enums\HoldReleaseReason;
use App\Modules\Inventory\Enums\HoldStatus;
use App\Modules\Inventory\Support\HoldCloser;

class ReleaseHold
{
    public function __construct(private readonly HoldCloser $closer) {}

    /** Idempotent: returns false if the hold was not active. */
    public function handle(int $holdId, HoldReleaseReason $reason): bool
    {
        return $this->closer->close($holdId, HoldStatus::Released, $reason->value, onlyIfOverdue: false);
    }
}
