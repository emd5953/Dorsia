<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Models;

use App\Modules\Wallet\Database\Factories\LedgerTransactionFactory;
use App\Modules\Wallet\Enums\LedgerTransactionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Read model: ledger rows are written only by Support\PostTransaction.
 *
 * @property int $id
 * @property LedgerTransactionType $type
 * @property string $reference_type
 * @property int $reference_id
 * @property string $idempotency_key
 * @property string $currency
 * @property array<string, mixed> $metadata
 */
final class LedgerTransaction extends Model
{
    /** @use HasFactory<LedgerTransactionFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $table = 'ledger_transactions';

    protected $guarded = [];

    protected static function newFactory(): LedgerTransactionFactory
    {
        return LedgerTransactionFactory::new();
    }

    protected function casts(): array
    {
        return [
            'type' => LedgerTransactionType::class,
            'reference_id' => 'integer',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
