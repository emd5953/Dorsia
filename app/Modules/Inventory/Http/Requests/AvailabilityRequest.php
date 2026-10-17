<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AvailabilityRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'hour' => ['required', 'integer', 'min:0', 'max:23'],
            'party_size' => ['required', 'integer', 'min:1', 'max:20'],
        ];
    }
}
