<?php

declare(strict_types=1);

namespace App\Shared\Outbox;

enum OutboxTopic: string
{
    case SlotHeld = 'slot.held';
    case SlotReleased = 'slot.released';
    case SlotBooked = 'slot.booked';
    case DropOpened = 'drop.opened';
    case DropSoldOut = 'drop.sold_out';
    case HoldExpiring = 'hold.expiring';
    case ReservationConfirmed = 'reservation.confirmed';
    case WalletUpdated = 'wallet.updated';
}
