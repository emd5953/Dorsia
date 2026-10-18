<?php

declare(strict_types=1);

namespace App\Shared\Outbox;

use App\Shared\Support\Timestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model for outbox_events. Realtime may use it (it is Shared).
 *
 * @property int $id
 * @property string $topic
 * @property array<string, mixed> $payload
 * @property int $attempts
 * @property string|null $last_error
 */
final class OutboxEvent extends Model
{
    public $timestamps = false;

    protected $table = 'outbox_events';

    protected $dateFormat = Timestamps::FORMAT;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'created_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
        ];
    }
}
