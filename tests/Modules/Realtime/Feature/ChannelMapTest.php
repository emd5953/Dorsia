<?php

declare(strict_types=1);

use App\Modules\Realtime\Support\ChannelMap;
use App\Shared\Outbox\OutboxTopic;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;

it('maps every outbox topic to its contract channel and exact event', function (): void {
    $cases = [
        [OutboxTopic::SlotHeld, ['venue_id' => 4], Channel::class, 'venue.4.slots', 'slot.held'],
        [OutboxTopic::SlotReleased, ['venue_id' => 4], Channel::class, 'venue.4.slots', 'slot.released'],
        [OutboxTopic::SlotBooked, ['venue_id' => 4], Channel::class, 'venue.4.slots', 'slot.booked'],
        [OutboxTopic::DropOpened, ['drop_id' => 5], Channel::class, 'drop.5', 'drop.opened'],
        [OutboxTopic::DropSoldOut, ['drop_id' => 5], Channel::class, 'drop.5', 'drop.sold_out'],
        [OutboxTopic::HoldExpiring, ['member_id' => 6], PrivateChannel::class, 'private-member.6', 'hold.expiring'],
        [OutboxTopic::ReservationConfirmed, ['member_id' => 6], PrivateChannel::class, 'private-member.6', 'reservation.confirmed'],
        [OutboxTopic::WalletUpdated, ['member_id' => 6], PrivateChannel::class, 'private-member.6', 'wallet.updated'],
    ];

    expect($cases)->toHaveCount(count(OutboxTopic::cases()));

    foreach ($cases as [$topic, $payload, $channelClass, $channelName, $event]) {
        $target = ChannelMap::map($topic, $payload);
        expect($target->channel)->toBeInstanceOf($channelClass)
            ->and($target->channel->name)->toBe($channelName)
            ->and($target->event)->toBe($event);
    }
});
