<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEmployeeRequest extends FormRequest
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
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'personal_number' => ['nullable', 'string', 'max:20'],
            'position' => ['nullable', 'string', 'max:120'],
            'contract_type' => ['required', 'string', 'in:indefinite,fixed_term,part_time,seasonal'],
            'employment_start' => ['required', 'date_format:Y-m-d'],
            'employment_end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:employment_start'],
            'gross_salary' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999.99'],
            'is_primary_employment' => ['required', 'boolean'],
            'iban' => ['nullable', 'string', 'max:34'],
            'bank_name' => ['nullable', 'string', 'max:150'],
        ];
    }
}
