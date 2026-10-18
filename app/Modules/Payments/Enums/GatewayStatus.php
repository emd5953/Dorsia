<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

enum GatewayStatus: string
{
    case Authorized = 'authorized';
    case Captured = 'captured';
    case Refunded = 'refunded';
    case PaidOut = 'paid_out';
    case Declined = 'declined';
    case Failed = 'failed';
}
