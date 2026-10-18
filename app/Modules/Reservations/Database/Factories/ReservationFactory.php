<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Database\Factories;

use App\Modules\Reservations\Enums\ReservationStatus;
use App\Modules\Reservations\Models\Reservation;
use App\Shared\Members\Member;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Builds a bare reservations row (schema and listing tests). It posts no ledger rows and touches no
 * slot: tests that need a real booking go through ConfirmReservation instead.
 *
 * @extends Factory<Reservation>
 */
final class ReservationFactory extends Factory
{
    protected $model = Reservation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'member_id' => Member::factory(),
            'hold_id' => fake()->unique()->numberBetween(1, 1_000_000_000),
            'slot_id' => fake()->numberBetween(1, 1_000_000),
            'venue_id' => fake()->numberBetween(1, 1_000_000),
            'venue_timezone' => 'America/New_York',
            'starts_at' => now()->addDays(3)->startOfHour(),
            'party_size' => 2,
            'min_spend_cents' => 50000,
            'coupon_cents' => 0,
            'credit_cents' => 0,
            'card_cents' => 50000,
            'currency' => 'USD',
            'payment_id' => null,
            'status' => ReservationStatus::Confirmed->value,
            'idempotency_key' => (string) Str::uuid(),
            'confirmed_at' => now(),
            'cancelled_at' => null,
        ];
    }
}
