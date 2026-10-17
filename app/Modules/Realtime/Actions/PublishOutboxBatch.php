<?php

declare(strict_types=1);

namespace App\Modules\Realtime\Actions;

use App\Modules\Realtime\Support\ChannelMap;
use App\Modules\Realtime\Support\OutboxBroadcast;
use App\Shared\Outbox\OutboxEvent;
use App\Shared\Outbox\OutboxTopic;
use App\Shared\Support\Timestamps;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Throwable;

class PublishOutboxBatch
{
    public function handle(int $limit = 100): int
    {
        if ($limit < 1) {
            return 0;
        }

        return DB::transaction(function () use ($limit): int {
            $rows = OutboxEvent::query()
                ->whereNull('published_at')
                ->where('attempts', '<', (int) config('realtime.outbox.max_attempts', 5))
                ->orderBy('id')
                ->limit($limit)
                ->lock('for update skip locked')
                ->get();

            $published = 0;

            foreach ($rows as $row) {
                try {
                    $topic = OutboxTopic::from($row->topic);
                    $target = ChannelMap::map($topic, $row->payload);
                    $payload = self::publicPayload($topic, $row->payload);
                } catch (Throwable $exception) {
                    $this->deadLetter($row, $exception);

                    continue;
                }

                try {
                    Event::dispatch(new OutboxBroadcast($target->channel, $target->event, $payload, $row->id));
                    $row->published_at = Timestamps::bind(now());
                    $row->save();
                    $published++;
                } catch (Throwable $exception) {
                    $row->attempts++;
                    $row->last_error = mb_substr($exception->getMessage(), 0, 4000);
                    $row->save();
                    if ($row->attempts >= (int) config('realtime.outbox.max_attempts', 5)) {
                        Log::critical('Realtime outbox row dead-lettered after repeated publish failures.', ['outbox_id' => $row->id, 'error' => $row->last_error]);

                        continue;
                    }
                    break;
                }
            }

            return $published;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function publicPayload(OutboxTopic $topic, array $payload): array
    {
        $keys = match ($topic) {
            OutboxTopic::SlotHeld, OutboxTopic::SlotBooked => ['slot_id', 'venue_id', 'drop_id', 'starts_at', 'capacity', 'available', 'occurred_at'],
            OutboxTopic::SlotReleased => ['slot_id', 'venue_id', 'drop_id', 'starts_at', 'capacity', 'available', 'occurred_at', 'reason'],
            OutboxTopic::DropOpened => ['drop_id', 'venue_id', 'opens_at', 'slot_ids'],
            OutboxTopic::DropSoldOut => ['drop_id', 'venue_id', 'sold_out_at'],
            OutboxTopic::HoldExpiring => ['hold_id', 'member_id', 'slot_id', 'expires_at'],
            OutboxTopic::ReservationConfirmed => ['reservation_id', 'member_id', 'slot_id', 'venue_id', 'starts_at', 'party_size', 'min_spend_cents', 'currency'],
            OutboxTopic::WalletUpdated => ['member_id', 'currency', 'credit_cents', 'coupon_cents'],
        };

        /** @var array<string, mixed> $filtered */
        $filtered = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload)) {
                $filtered[$key] = $payload[$key];
            }
        }

        return $filtered;
    }

    private function deadLetter(OutboxEvent $row, Throwable $exception): void
    {
        $row->attempts = (int) config('realtime.outbox.max_attempts', 5);
        $row->last_error = mb_substr($exception->getMessage(), 0, 4000);
        $row->save();
        Log::critical('Realtime outbox row could not be mapped and was dead-lettered.', ['outbox_id' => $row->id, 'error' => $row->last_error]);
    }
}
