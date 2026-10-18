<?php

declare(strict_types=1);

namespace App\Modules\Payments\Database\Factories;

use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Shared\Members\Member;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
final class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $holdId = fake()->unique()->numberBetween(1, 1_000_000_000);

        return [
            'member_id' => Member::factory(),
            'hold_id' => $holdId,
            'reservation_id' => null,
            'provider' => 'fake',
            'provider_ref' => 'fake_ch_'.Str::ulid(),
            'idempotency_key' => "hold:{$holdId}:auth",
            'amount_cents' => 55000,
            'currency' => 'USD',
            'status' => PaymentStatus::Authorized,
            'decline_code' => null,
        ];
    }

    public function pending(): self
    {
        return $this->state(['status' => PaymentStatus::Pending, 'provider_ref' => null]);
    }

    public function withStatus(PaymentStatus $status): self
    {
        return $this->state(['status' => $status]);
    }
}
