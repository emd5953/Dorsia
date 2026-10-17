<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

enum HoldReleaseReason: string
{
    case PaymentFailed = 'payment_failed';
    case PaymentTimeout = 'payment_timeout';
    case ConfirmFailed = 'confirm_failed';
}
