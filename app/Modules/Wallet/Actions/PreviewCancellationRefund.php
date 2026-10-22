<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Actions;

use App\Modules\Wallet\Data\CancellationRefund;
use App\Modules\Wallet\Support\LateCancelSplit;
use App\Shared\Money\Money;

class PreviewCancellationRefund
{
    /** Pure calculation shared with posting; no ledger or account changes. */
    public function handle(Money $amount, bool $late): CancellationRefund
    {
        $split = LateCancelSplit::forPolicy($amount, $late);

        return new CancellationRefund(null, $split->member, $split->platform, $split->venueRetained);
    }
}
