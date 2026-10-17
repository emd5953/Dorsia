<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Enums\HoldStatus;
use App\Modules\Inventory\Support\HoldCloser;

class ExpireHold
{
    public function __construct(private readonly HoldCloser $closer) {}

    /** Returns true only if this call expired the hold (`now() >= expires_at`, still active). */
    public function handle(int $holdId): bool
    {
        return $this->closer->close($holdId, HoldStatus::Expired, 'expired', onlyIfOverdue: true);
    }
}
