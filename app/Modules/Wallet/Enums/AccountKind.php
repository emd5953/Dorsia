<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Enums;

enum AccountKind: string
{
    case Cash = 'cash';
    case Credit = 'credit';
    case Coupon = 'coupon';
    case VenuePayable = 'venue_payable';
    case Platform = 'platform';

    /** C§5.4: cash (card clearing) is debit-normal, every other kind is credit-normal. */
    public function isDebitNormal(): bool
    {
        return $this === self::Cash;
    }
}
