<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Models;

use App\Shared\Support\Timestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * Read model: ledger rows are written only by Support\PostTransaction.
 *
 * @property int $id
 * @property int $transaction_id
 * @property int $account_id
 * @property int $amount_cents Positive = debit, negative = credit.
 */
final class LedgerEntry extends Model
{
    public $timestamps = false;

    protected $dateFormat = Timestamps::FORMAT;

    protected $table = 'ledger_entries';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'transaction_id' => 'integer',
            'account_id' => 'integer',
            'amount_cents' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
