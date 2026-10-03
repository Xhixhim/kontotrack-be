<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePayrollRunRequest extends FormRequest
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
            'period_year' => ['required', 'integer', 'between:2000,2100'],
            'period_month' => ['required', 'integer', 'between:1,12'],
            'pay_date' => ['required', 'date_format:Y-m-d'],
            'additions' => ['sometimes', 'array', 'max:500'],
            'additions.*.employee_id' => ['required', 'integer', 'distinct'],
            'additions.*.amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999.99'],
            'additions.*.note' => ['nullable', 'string', 'max:120'],
        ];
    }
}
