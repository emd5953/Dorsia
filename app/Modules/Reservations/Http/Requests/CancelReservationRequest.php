<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Http\Requests;

use App\Modules\Reservations\Data\CancellationExpectation;
use Illuminate\Foundation\Http\FormRequest;

final class CancelReservationRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'expected_refund' => ['sometimes', 'required', 'array:credit_cents,forfeited_cents,currency,late'],
            'expected_refund.credit_cents' => ['required_with:expected_refund', 'integer', 'min:0'],
            'expected_refund.forfeited_cents' => ['required_with:expected_refund', 'integer', 'min:0'],
            'expected_refund.currency' => ['required_with:expected_refund', 'string', 'in:USD'],
            'expected_refund.late' => ['required_with:expected_refund', 'boolean'],
        ];
    }

    public function expectation(): ?CancellationExpectation
    {
        if (! $this->has('expected_refund')) {
            return null;
        }

        return new CancellationExpectation(
            $this->integer('expected_refund.credit_cents'),
            $this->integer('expected_refund.forfeited_cents'),
            $this->string('expected_refund.currency')->toString(),
            $this->boolean('expected_refund.late'),
        );
    }
}
