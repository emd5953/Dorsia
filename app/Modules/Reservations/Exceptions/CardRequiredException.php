<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Exceptions;

use App\Shared\Exceptions\DomainError;

final class CardRequiredException extends DomainError
{
    public function __construct(public readonly int $cardCents)
    {
        parent::__construct('A card is required to cover the remaining amount.');
    }

    public function errorCode(): string
    {
        return 'card_required';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return ['card_cents' => $this->cardCents];
    }
}
