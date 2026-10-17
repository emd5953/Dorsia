<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Payments\Data\PaymentData;
use App\Modules\Payments\Database\Factories\PaymentFactory;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Shared\Money\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $member_id
 * @property int $hold_id
 * @property int|null $reservation_id
 * @property string $provider
 * @property string|null $provider_ref
 * @property string $idempotency_key
 * @property int $amount_cents
 * @property string $currency
 * @property PaymentStatus $status
 * @property string|null $decline_code
 */
final class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    protected $table = 'payments';

    protected $guarded = [];

    protected static function newFactory(): PaymentFactory
    {
        return PaymentFactory::new();
    }

    public function amount(): Money
    {
        return Money::of($this->amount_cents, $this->currency);
    }

    public function toData(): PaymentData
    {
        return new PaymentData(
            id: $this->id,
            memberId: $this->member_id,
            holdId: $this->hold_id,
            reservationId: $this->reservation_id,
            status: $this->status,
            amount: $this->amount(),
            providerRef: $this->provider_ref,
            declineCode: $this->decline_code,
        );
    }

    protected function casts(): array
    {
        return [
            'member_id' => 'integer',
            'hold_id' => 'integer',
            'reservation_id' => 'integer',
            'amount_cents' => 'integer',
            'status' => PaymentStatus::class,
        ];
    }
}
