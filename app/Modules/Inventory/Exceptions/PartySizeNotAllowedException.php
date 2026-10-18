<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use App\Shared\Exceptions\DomainError;

final class PartySizeNotAllowedException extends DomainError
{
    public function __construct(public readonly int $minPartySize, public readonly int $maxPartySize)
    {
        parent::__construct("Party size must be between {$minPartySize} and {$maxPartySize}.");
    }

    public function errorCode(): string
    {
        return 'party_size_not_allowed';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return ['min_party_size' => $this->minPartySize, 'max_party_size' => $this->maxPartySize];
    }
}
