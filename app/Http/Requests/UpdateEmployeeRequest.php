<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmployeeRequest extends FormRequest
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
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],
            'last_name' => ['sometimes', 'required', 'string', 'max:100'],
            'personal_number' => ['sometimes', 'nullable', 'string', 'max:20'],
            'position' => ['sometimes', 'nullable', 'string', 'max:120'],
            'contract_type' => ['sometimes', 'required', 'string', 'in:indefinite,fixed_term,part_time,seasonal'],
            'employment_start' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'employment_end' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'gross_salary' => ['sometimes', 'required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999.99'],
            'is_primary_employment' => ['sometimes', 'required', 'boolean'],
            'iban' => ['sometimes', 'nullable', 'string', 'max:34'],
            'bank_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'status' => ['sometimes', 'required', 'string', 'in:active,inactive'],
        ];
    }
}
