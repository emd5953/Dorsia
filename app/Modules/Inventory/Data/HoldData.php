<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Data;

use App\Modules\Inventory\Enums\HoldStatus;
use Carbon\CarbonImmutable;

final readonly class HoldData
{
    public function __construct(
        public int $id,
        public int $memberId,
        public int $partySize,
        public HoldStatus $status,
        public CarbonImmutable $expiresAt,
        public CarbonImmutable $createdAt,
        public SlotData $slot,
    ) {}
}
