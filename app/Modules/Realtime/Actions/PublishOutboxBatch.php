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
                ->orderBy('id')
                ->limit($limit)
                ->lock('for update skip locked')
                ->get();

            $published = 0;

            foreach ($rows as $row) {
                try {
                    $topic = OutboxTopic::from($row->topic);
                    $target = ChannelMap::map($topic, $row->payload);
                    Event::dispatch(new OutboxBroadcast($target->channel, $target->event, $row->payload, $row->id));
                    $row->published_at = Timestamps::bind(now());
                    $row->save();
                    $published++;
                } catch (Throwable $exception) {
                    $row->attempts++;
                    $row->last_error = mb_substr($exception->getMessage(), 0, 4000);
                    $row->save();

                    break;
                }
            }

            return $published;
        });
    }
}
