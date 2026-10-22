<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Exceptions;

use App\Shared\Exceptions\DomainError;

final class CancellationPreviewChangedException extends DomainError
{
    public function __construct()
    {
        parent::__construct('The cancellation refund has changed. Review the latest refund before cancelling.');
    }

    public function errorCode(): string
    {
        return 'cancellation_preview_changed';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
