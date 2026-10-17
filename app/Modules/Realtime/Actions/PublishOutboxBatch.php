<?php

declare(strict_types=1);

namespace App\Modules\Realtime\Actions;

use App\Modules\Realtime\Support\BatchOutcome;
use App\Modules\Realtime\Support\ChannelMap;
use App\Modules\Realtime\Support\OutboxBroadcast;
use App\Modules\Realtime\Support\PayloadShape;
use App\Shared\Outbox\OutboxEvent;
use App\Shared\Outbox\OutboxTopic;
use App\Shared\Support\Timestamps;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Throwable;

class PublishOutboxBatch
{
    public function __construct(private readonly BatchOutcome $outcome) {}

    public function handle(int $limit = 100): int
    {
        $this->outcome->broadcastFailed = false;

        if ($limit < 1) {
            return 0;
        }

        /** @var list<array{id: int, error: string, reason: string}> $deadLettered */
        $deadLettered = [];

        $published = DB::transaction(function () use ($limit, &$deadLettered): int {
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
                    $shaped = PayloadShape::validate($topic, $row->payload);
                    $target = ChannelMap::map($topic, $row->payload);
                    $payload = $shaped['payload'];
                } catch (Throwable $exception) {
                    $this->deadLetter($row, $exception);
                    $deadLettered[] = ['id' => $row->id, 'error' => (string) $row->last_error, 'reason' => 'Realtime outbox row could not be mapped and was dead-lettered.'];

                    continue;
                }

                if ($shaped['dropped'] !== []) {
                    Log::warning('Realtime outbox payload had keys outside the C§7 shape; they were dropped.', ['outbox_id' => $row->id, 'topic' => $topic->value, 'dropped_keys' => $shaped['dropped']]);
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
                    $this->outcome->broadcastFailed = true;
                    if ($row->attempts >= (int) config('realtime.outbox.max_attempts', 5)) {
                        $deadLettered[] = ['id' => $row->id, 'error' => $row->last_error, 'reason' => 'Realtime outbox row dead-lettered after repeated publish failures.'];

                        continue;
                    }
                    break;
                }
            }

            return $published;
        });

        foreach ($deadLettered as $row) {
            Log::critical($row['reason'], ['outbox_id' => $row['id'], 'error' => $row['error']]);
        }

        return $published;
    }

    private function deadLetter(OutboxEvent $row, Throwable $exception): void
    {
        $row->attempts = (int) config('realtime.outbox.max_attempts', 5);
        $row->last_error = mb_substr($exception->getMessage(), 0, 4000);
        $row->save();
    }
}
