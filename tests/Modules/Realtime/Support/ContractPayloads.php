<?php

declare(strict_types=1);

namespace Tests\Modules\Realtime\Support;

use App\Shared\Outbox\OutboxTopic;

/** Complete, C§7-shaped payloads for every outbox topic. */
final class ContractPayloads
{
    /**
     * @return array<string, mixed>
     */
    public static function for(OutboxTopic $topic): array
    {
        $slot = ['slot_id' => 1, 'venue_id' => 2, 'drop_id' => null, 'starts_at' => '2026-10-17T18:00:00Z', 'capacity' => 10, 'available' => 9, 'occurred_at' => '2026-10-17T12:00:00Z'];

        return match ($topic) {
            OutboxTopic::SlotHeld, OutboxTopic::SlotBooked => $slot,
            OutboxTopic::SlotReleased => [...$slot, 'reason' => 'expired'],
            OutboxTopic::DropOpened => ['drop_id' => 3, 'venue_id' => 2, 'opens_at' => '2026-10-17T18:00:00Z', 'slot_ids' => [1, 2]],
            OutboxTopic::DropSoldOut => ['drop_id' => 3, 'venue_id' => 2, 'sold_out_at' => '2026-10-17T18:00:00Z'],
            OutboxTopic::HoldExpiring => ['hold_id' => 4, 'member_id' => 5, 'slot_id' => 1, 'expires_at' => '2026-10-17T18:00:00Z'],
            OutboxTopic::ReservationConfirmed => ['reservation_id' => 6, 'member_id' => 5, 'slot_id' => 1, 'venue_id' => 2, 'starts_at' => '2026-10-17T18:00:00Z', 'party_size' => 2, 'min_spend_cents' => 5000, 'currency' => 'USD'],
            OutboxTopic::WalletUpdated => ['member_id' => 5, 'currency' => 'USD', 'credit_cents' => 4, 'coupon_cents' => 0],
        };
    }

    /**
     * A wrong-typed value for the key that exercises the topic's type checks.
     *
     * @return array{0: string, 1: mixed}
     */
    public static function wrongType(OutboxTopic $topic): array
    {
        return match ($topic) {
            OutboxTopic::SlotHeld, OutboxTopic::SlotBooked => ['capacity', '10'],
            OutboxTopic::SlotReleased => ['reason', 'because'],
            OutboxTopic::DropOpened => ['slot_ids', [1, '2']],
            OutboxTopic::DropSoldOut => ['sold_out_at', '2026-10-17 18:00:00'],
            OutboxTopic::HoldExpiring => ['expires_at', '2026-10-17T18:00:00+00:00'],
            OutboxTopic::ReservationConfirmed => ['party_size', '2'],
            OutboxTopic::WalletUpdated => ['credit_cents', '4'],
        };
    }
}
