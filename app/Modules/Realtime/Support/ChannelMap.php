<?php

declare(strict_types=1);

namespace App\Modules\Realtime\Support;

use App\Shared\Outbox\OutboxTopic;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;

final class ChannelMap
{
    /** @param array<string, mixed> $payload */
    public static function map(OutboxTopic $topic, array $payload): ChannelTarget
    {
        return match ($topic) {
            OutboxTopic::SlotHeld => new ChannelTarget(new Channel('venue.'.self::int($payload, 'venue_id').'.slots'), 'slot.held'),
            OutboxTopic::SlotReleased => new ChannelTarget(new Channel('venue.'.self::int($payload, 'venue_id').'.slots'), 'slot.released'),
            OutboxTopic::SlotBooked => new ChannelTarget(new Channel('venue.'.self::int($payload, 'venue_id').'.slots'), 'slot.booked'),
            OutboxTopic::DropOpened => new ChannelTarget(new Channel('drop.'.self::int($payload, 'drop_id')), 'drop.opened'),
            OutboxTopic::DropSoldOut => new ChannelTarget(new Channel('drop.'.self::int($payload, 'drop_id')), 'drop.sold_out'),
            OutboxTopic::HoldExpiring => new ChannelTarget(new PrivateChannel('member.'.self::int($payload, 'member_id')), 'hold.expiring'),
            OutboxTopic::ReservationConfirmed => new ChannelTarget(new PrivateChannel('member.'.self::int($payload, 'member_id')), 'reservation.confirmed'),
            OutboxTopic::WalletUpdated => new ChannelTarget(new PrivateChannel('member.'.self::int($payload, 'member_id')), 'wallet.updated'),
        };
    }

    /** @param array<string, mixed> $payload */
    private static function int(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;

        if (! is_int($value) || $value < 1) {
            throw new \InvalidArgumentException("Invalid outbox payload key: {$key}");
        }

        return $value;
    }
}
