<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Database\Factories;

use App\Modules\Inventory\Models\Hold;
use App\Modules\Inventory\Models\Slot;
use App\Shared\Members\Member;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Hold>
 */
final class HoldFactory extends Factory
{
    protected $model = Hold::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slot_id' => Slot::factory(),
            'member_id' => Member::factory(),
            'party_size' => 2,
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
            'expires_at' => now()->addSeconds((int) config('inventory.hold_ttl_seconds')),
        ];
    }
}
