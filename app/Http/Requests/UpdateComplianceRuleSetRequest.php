<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateComplianceRuleSetRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->is_system_admin === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'jurisdiction' => ['required', 'string', 'min:2', 'max:8'],
            'domain' => ['required', 'string', 'max:40'],
            'version' => ['required', 'string', 'max:40'],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'source_title' => ['required', 'string', 'max:255'],
            'source_url' => ['required', 'url', 'max:2048', 'regex:/^https:\/\//i'],
            'rules' => ['required', 'array', 'min:1', 'max:100'],
            'rules.*.rule_key' => ['required', 'string', 'max:100', 'distinct'],
            'rules.*.configuration' => ['required', 'array'],
            'rules.*.description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
