<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Data\SelloutStat;
use Carbon\CarbonImmutable;

class GetSelloutStats
{
    /**
     * @return list<SelloutStat>
     */
    public function handle(CarbonImmutable $since): array
    {
        return [];
    }
}
