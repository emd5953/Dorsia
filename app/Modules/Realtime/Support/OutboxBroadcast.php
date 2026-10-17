<?php

declare(strict_types=1);

namespace App\Modules\Realtime\Support;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

final class OutboxBroadcast implements ShouldBroadcastNow
{
    use InteractsWithSockets;
    use SerializesModels;

    /** @param array<string, mixed> $payload */
    public function __construct(
        private readonly Channel $channel,
        private readonly string $event,
        private readonly array $payload,
        private readonly int $outboxId,
    ) {}

    public function broadcastOn(): array
    {
        return [$this->channel];
    }

    public function broadcastAs(): string
    {
        return $this->event;
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [...$this->payload, 'outbox_id' => $this->outboxId];
    }
}
