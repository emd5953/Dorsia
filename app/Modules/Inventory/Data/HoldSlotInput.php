<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Data;

final readonly class HoldSlotInput
{
    public function __construct(public int $memberId, public int $slotId, public int $partySize, public string $idempotencyKey) {}
}
