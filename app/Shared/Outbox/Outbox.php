<?php

declare(strict_types=1);

namespace App\Shared\Outbox;

use App\Shared\Support\Transactions;
use LogicException;

final class Outbox
{
    /**
     * Inserts one outbox_events row on the current connection.
     *
     * @param  array<string, mixed>  $payload  shape per C§7
     *
     * @throws LogicException if called outside a DB transaction
     */
    public function record(OutboxTopic $topic, array $payload): void
    {
        Transactions::assertActive();

        OutboxEvent::query()->create([
            'topic' => $topic->value,
            'payload' => $payload,
            'created_at' => now(),
        ]);
    }
}
