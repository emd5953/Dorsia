<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Exceptions;

use App\Shared\Exceptions\DomainError;

final class InsufficientWalletBalanceException extends DomainError
{
    public function errorCode(): string
    {
        return 'wallet_balance_changed';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
