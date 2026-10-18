<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Data;

use App\Shared\Money\CurrencyMismatchException;
use App\Shared\Money\Money;

final readonly class SpendPlan
{
    /**
     * @throws CurrencyMismatchException unless all three parts share one currency
     */
    public function __construct(public Money $coupon, public Money $credit, public Money $card)
    {
        $this->total();
    }

    public function total(): Money
    {
        return $this->coupon->add($this->credit)->add($this->card);
    }
}
