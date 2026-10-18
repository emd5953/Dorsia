<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ConfirmReservationRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'hold_id' => ['required', 'integer', 'min:1'],
            'card_token' => ['nullable', 'string', 'max:255'],
        ];
    }
}
