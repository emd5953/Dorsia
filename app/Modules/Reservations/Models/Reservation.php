<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Models;

use App\Modules\Reservations\Data\ReservationData;
use App\Modules\Reservations\Database\Factories\ReservationFactory;
use App\Modules\Reservations\Enums\ReservationStatus;
use App\Modules\Wallet\Data\SpendPlan;
use App\Shared\Money\Money;
use App\Shared\Support\Timestamps;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $member_id
 * @property int $hold_id
 * @property int $slot_id
 * @property int $venue_id
 * @property string $venue_timezone
 * @property CarbonImmutable $starts_at
 * @property int $party_size
 * @property int $min_spend_cents
 * @property int $coupon_cents
 * @property int $credit_cents
 * @property int $card_cents
 * @property string $currency
 * @property int|null $payment_id
 * @property ReservationStatus $status
 * @property string $idempotency_key
 * @property CarbonImmutable $confirmed_at
 * @property CarbonImmutable|null $cancelled_at
 */
final class Reservation extends Model
{
    /** @use HasFactory<ReservationFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $dateFormat = Timestamps::FORMAT;

    public function toData(): ReservationData
    {
        return new ReservationData(
            id: $this->id,
            memberId: $this->member_id,
            holdId: $this->hold_id,
            slotId: $this->slot_id,
            venueId: $this->venue_id,
            venueTimezone: $this->venue_timezone,
            startsAt: $this->starts_at,
            partySize: $this->party_size,
            minSpend: Money::of($this->min_spend_cents, $this->currency),
            payment: new SpendPlan(
                Money::of($this->coupon_cents, $this->currency),
                Money::of($this->credit_cents, $this->currency),
                Money::of($this->card_cents, $this->currency),
            ),
            status: $this->status,
            confirmedAt: $this->confirmed_at,
            cancelledAt: $this->cancelled_at,
        );
    }

    /**
     * @return ReservationFactory
     */
    protected static function newFactory(): Factory
    {
        return ReservationFactory::new();
    }

    protected function casts(): array
    {
        return [
            'party_size' => 'integer',
            'min_spend_cents' => 'integer',
            'coupon_cents' => 'integer',
            'credit_cents' => 'integer',
            'card_cents' => 'integer',
            'status' => ReservationStatus::class,
            'starts_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }
}
