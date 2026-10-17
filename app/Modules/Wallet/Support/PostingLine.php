<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Support;

use App\Modules\Wallet\Enums\AccountKind;
use App\Modules\Wallet\Enums\AccountOwnerType;

/**
 * One requested ledger line: an account (by owner and kind) and a signed amount (positive = debit, negative = credit).
 */
final readonly class PostingLine
{
    public function __construct(
        public AccountOwnerType $ownerType,
        public int $ownerId,
        public AccountKind $kind,
        public int $amountCents,
    ) {}

    public static function member(int $memberId, AccountKind $kind, int $amountCents): self
    {
        return new self(AccountOwnerType::Member, $memberId, $kind, $amountCents);
    }

    public static function venuePayable(int $venueId, int $amountCents): self
    {
        return new self(AccountOwnerType::Venue, $venueId, AccountKind::VenuePayable, $amountCents);
    }

    /** The card clearing account. */
    public static function cardClearing(int $amountCents): self
    {
        return new self(AccountOwnerType::System, 0, AccountKind::Cash, $amountCents);
    }

    public static function platform(int $amountCents): self
    {
        return new self(AccountOwnerType::System, 0, AccountKind::Platform, $amountCents);
    }

    /** Stable sort and lookup key for the account this line touches. */
    public function accountKey(): string
    {
        return sprintf('%s:%010d:%s', $this->ownerType->value, $this->ownerId, $this->kind->value);
    }
}
