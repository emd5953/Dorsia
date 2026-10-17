<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Database\Factories\HoldFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $slot_id
 * @property int $member_id
 * @property int $party_size
 * @property string $status
 * @property string $idempotency_key
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $expiring_notified_at
 * @property CarbonImmutable|null $closed_at
 * @property string|null $close_reason
 * @property CarbonImmutable $created_at
 */
final class Hold extends Model
{
    /** @use HasFactory<HoldFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected function casts(): array
    {
        return [
            'party_size' => 'integer',
            'expires_at' => 'immutable_datetime',
            'expiring_notified_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HoldFactory
     */
    protected static function newFactory(): Factory
    {
        return HoldFactory::new();
    }
}
