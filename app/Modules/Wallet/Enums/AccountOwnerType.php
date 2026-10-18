<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Enums;

enum AccountOwnerType: string
{
    case Member = 'member';
    case Venue = 'venue';
    case System = 'system';
}
