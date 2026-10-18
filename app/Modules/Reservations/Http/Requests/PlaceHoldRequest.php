<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PlaceHoldRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'slot_id' => ['required', 'integer', 'min:1'],
            'party_size' => ['required', 'integer', 'min:1', 'max:20'],
        ];
    }
}
