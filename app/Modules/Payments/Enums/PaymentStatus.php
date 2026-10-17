<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Authorized = 'authorized';
    case Captured = 'captured';
    case Failed = 'failed';
    case TimedOut = 'timed_out';
    case Refunded = 'refunded';
    case CaptureFailed = 'capture_failed';
}
