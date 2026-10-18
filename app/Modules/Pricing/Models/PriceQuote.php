<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Models;

use App\Modules\Pricing\Data\QuoteData;
use App\Modules\Pricing\Database\Factories\PriceQuoteFactory;
use App\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $hold_id
 * @property int $slot_id
 * @property int $venue_id
 * @property int $party_size
 * @property int $per_person_cents
 * @property int $amount_cents
 * @property string $currency
 * @property array<string, mixed> $inputs
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable $created_at
 */
final class PriceQuote extends Model
{
    /** @use HasFactory<PriceQuoteFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'price_quotes';

    protected $guarded = [];

    public function toData(): QuoteData
    {
        return new QuoteData(
            id: $this->id,
            holdId: $this->hold_id,
            slotId: $this->slot_id,
            partySize: $this->party_size,
            perPerson: Money::of($this->per_person_cents, $this->currency),
            amount: Money::of($this->amount_cents, $this->currency),
            inputs: $this->inputs,
            expiresAt: $this->expires_at,
        );
    }

    protected static function newFactory(): PriceQuoteFactory
    {
        return PriceQuoteFactory::new();
    }

    protected function casts(): array
    {
        return [
            'inputs' => 'array',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
