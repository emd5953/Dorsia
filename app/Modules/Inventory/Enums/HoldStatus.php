<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

enum HoldStatus: string
{
    case Active = 'active';
    case Converted = 'converted';
    case Released = 'released';
    case Expired = 'expired';
}
