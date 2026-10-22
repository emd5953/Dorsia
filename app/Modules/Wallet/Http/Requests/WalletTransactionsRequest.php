<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class WalletTransactionsRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['before_id' => ['sometimes', 'required', 'integer', 'min:1', 'max:9007199254740991']];
    }
}
