<?php

declare(strict_types=1);

namespace App\Modules\Realtime\Support;

use App\Shared\Outbox\OutboxTopic;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * C§7 payload shapes: required keys and their types, per outbox topic.
 */
final class PayloadShape
{
    private const SLOT_RELEASE_REASONS = ['expired', 'payment_failed', 'payment_timeout', 'confirm_failed', 'cancelled'];

    /**
     * Validates the payload and returns only the C§7 keys; extra keys are reported in `dropped`.
     *
     * @param  array<string, mixed>  $payload
     * @return array{payload: array<string, mixed>, dropped: list<string>}
     *
     * @throws InvalidArgumentException when a required key is missing or mistyped
     */
    public static function validate(OutboxTopic $topic, array $payload): array
    {
        $shape = self::shape($topic);
        $problems = [];
        $filtered = [];

        foreach ($shape as $key => $type) {
            if (! array_key_exists($key, $payload)) {
                $problems[] = "missing {$key}";

                continue;
            }

            if (! self::matches($type, $payload[$key])) {
                $problems[] = "invalid {$key} (expected {$type})";

                continue;
            }

            $filtered[$key] = $payload[$key];
        }

        if ($problems !== []) {
            throw new InvalidArgumentException("Invalid {$topic->value} outbox payload: ".implode(', ', $problems));
        }

        $dropped = array_values(array_map('strval', array_diff(array_keys($payload), array_keys($shape))));

        return ['payload' => $filtered, 'dropped' => $dropped];
    }

    /** @return array<string, string> */
    private static function shape(OutboxTopic $topic): array
    {
        $slot = ['slot_id' => 'id', 'venue_id' => 'id', 'drop_id' => '?id', 'starts_at' => 'timestamp', 'capacity' => 'uint', 'available' => 'uint', 'occurred_at' => 'timestamp'];

        return match ($topic) {
            OutboxTopic::SlotHeld, OutboxTopic::SlotBooked => $slot,
            OutboxTopic::SlotReleased => [...$slot, 'reason' => 'reason'],
            OutboxTopic::DropOpened => ['drop_id' => 'id', 'venue_id' => 'id', 'opens_at' => 'timestamp', 'slot_ids' => 'ids'],
            OutboxTopic::DropSoldOut => ['drop_id' => 'id', 'venue_id' => 'id', 'sold_out_at' => 'timestamp'],
            OutboxTopic::HoldExpiring => ['hold_id' => 'id', 'member_id' => 'id', 'slot_id' => 'id', 'expires_at' => 'timestamp'],
            OutboxTopic::ReservationConfirmed => ['reservation_id' => 'id', 'member_id' => 'id', 'slot_id' => 'id', 'venue_id' => 'id', 'starts_at' => 'timestamp', 'party_size' => 'id', 'min_spend_cents' => 'uint', 'currency' => 'currency'],
            OutboxTopic::WalletUpdated => ['member_id' => 'id', 'currency' => 'currency', 'credit_cents' => 'uint', 'coupon_cents' => 'uint'],
        };
    }

    private static function matches(string $type, mixed $value): bool
    {
        return match ($type) {
            'id' => is_int($value) && $value >= 1,
            '?id' => $value === null || (is_int($value) && $value >= 1),
            'uint' => is_int($value) && $value >= 0,
            'ids' => is_array($value) && array_is_list($value) && array_reduce($value, static fn (bool $ok, mixed $id): bool => $ok && is_int($id) && $id >= 1, true),
            'timestamp' => self::isUtcTimestamp($value),
            'currency' => is_string($value) && preg_match('/^[A-Z]{3}$/', $value) === 1,
            'reason' => is_string($value) && in_array($value, self::SLOT_RELEASE_REASONS, true),
            default => false,
        };
    }

    private static function isUtcTimestamp(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value) !== 1) {
            return false;
        }

        $parsed = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $value);

        return $parsed !== false && $parsed->format('Y-m-d\TH:i:s\Z') === $value;
    }
}
