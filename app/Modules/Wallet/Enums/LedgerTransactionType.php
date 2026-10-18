<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Enums;

enum LedgerTransactionType: string
{
    case Booking = 'booking';
    case CancelEarly = 'cancel_early';
    case CancelLate = 'cancel_late';
    case GoodwillCredit = 'goodwill_credit';
}
