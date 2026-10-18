<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Actions;

use App\Modules\Pricing\Data\QuoteData;
use App\Modules\Pricing\Exceptions\QuoteNotFoundException;
use App\Modules\Pricing\Models\PriceQuote;

class GetQuoteForHold
{
    /**
     * @throws QuoteNotFoundException
     */
    public function handle(int $holdId): QuoteData
    {
        $quote = PriceQuote::query()->where('hold_id', $holdId)->first();

        if ($quote === null) {
            throw new QuoteNotFoundException("No quote for hold {$holdId}.");
        }

        return $quote->toData();
    }
}
