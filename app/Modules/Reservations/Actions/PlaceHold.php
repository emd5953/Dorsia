<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Actions;

use App\Modules\Inventory\Actions\HoldSlot;
use App\Modules\Inventory\Data\HoldData;
use App\Modules\Inventory\Data\HoldSlotInput;
use App\Modules\Pricing\Actions\GetQuoteForHold;
use App\Modules\Pricing\Actions\QuoteHold;
use App\Modules\Pricing\Data\QuoteHoldInput;
use App\Modules\Reservations\Data\HoldView;
use App\Shared\Idempotency\IdempotencyKey;

class PlaceHold
{
    public function __construct(
        private readonly HoldSlot $holdSlot,
        private readonly QuoteHold $quoteHold,
        private readonly GetQuoteForHold $getQuote,
    ) {}

    /**
     * The quote is written inside the hold's transaction: a failure in pricing leaves no hold behind.
     */
    public function handle(int $memberId, int $slotId, int $partySize, IdempotencyKey $key): HoldView
    {
        $hold = $this->holdSlot->handle(
            new HoldSlotInput($memberId, $slotId, $partySize, $key->scoped()),
            function (HoldData $held): void {
                $this->quoteHold->handle(new QuoteHoldInput(
                    $held->id,
                    $held->slot->id,
                    $held->slot->venueId,
                    $held->slot->venueTimezone,
                    $held->slot->startsAt,
                    $held->partySize,
                    $held->slot->basePerPerson,
                    $held->expiresAt,
                ));
            },
        );

        return new HoldView($hold, $this->getQuote->handle($hold->id));
    }
}
