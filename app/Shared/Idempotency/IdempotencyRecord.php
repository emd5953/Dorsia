<?php

declare(strict_types=1);

namespace App\Shared\Idempotency;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model for idempotency_keys.
 *
 * @property int $id
 * @property int $member_id
 * @property string $key
 * @property string $method
 * @property string $path
 * @property string $request_hash
 * @property string $status
 * @property int|null $response_status
 * @property string $lock_token
 * @property CarbonImmutable $locked_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable $created_at
 */
final class IdempotencyRecord extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'idempotency_keys';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'locked_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
