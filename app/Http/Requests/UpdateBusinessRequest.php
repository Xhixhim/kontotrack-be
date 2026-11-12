<?php

namespace App\Http\Requests;

use App\Support\KosovoIdentifier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBusinessRequest extends FormRequest
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
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'legal_name' => ['sometimes', 'required', 'string', 'max:255'],
            'trade_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'nui' => ['sometimes', 'required', 'string', 'regex:'.KosovoIdentifier::BUSINESS_PATTERN, Rule::unique('businesses', 'nui')->ignore($this->route('business'))],
            'legal_form' => ['sometimes', 'nullable', 'string', 'max:40'],
            'owner_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'vat_status' => ['sometimes', 'required', 'string', 'in:unknown,registered,not_registered'],
            'municipality' => ['sometimes', 'nullable', 'string', 'max:100'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'activity_code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'registered_at' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['nui.regex' => 'The NUI has 9 digits, as printed on the business registration certificate.'];
    }
}
