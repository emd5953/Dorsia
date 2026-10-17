<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Data;

use App\Shared\Money\Money;

final readonly class WalletBalance
{
    public function __construct(public int $memberId, public Money $credit, public Money $coupon) {}
}
