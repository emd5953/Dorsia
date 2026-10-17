<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Models;

use App\Modules\Wallet\Database\Factories\LedgerAccountFactory;
use App\Modules\Wallet\Enums\AccountKind;
use App\Modules\Wallet\Enums\AccountOwnerType;
use App\Shared\Support\Timestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property AccountOwnerType $owner_type
 * @property int $owner_id
 * @property AccountKind $kind
 * @property string $currency
 * @property int $balance_cents Cached projection in normal-side orientation (C§5.4).
 */
final class LedgerAccount extends Model
{
    /** @use HasFactory<LedgerAccountFactory> */
    use HasFactory;

    protected $dateFormat = Timestamps::FORMAT;

    protected $table = 'ledger_accounts';

    protected $guarded = [];

    protected static function newFactory(): LedgerAccountFactory
    {
        return LedgerAccountFactory::new();
    }

    protected function casts(): array
    {
        return [
            'owner_type' => AccountOwnerType::class,
            'owner_id' => 'integer',
            'kind' => AccountKind::class,
            'balance_cents' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
