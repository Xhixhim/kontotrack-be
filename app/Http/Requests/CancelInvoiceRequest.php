<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelInvoiceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'cancelled_on' => ['required', 'date_format:Y-m-d'],
            'cancellation_reason' => ['required', 'string', 'max:500'],
        ];
    }
}
