<?php

namespace App\Http\Requests;

use App\Support\KosovoIdentifier;
use Illuminate\Foundation\Http\FormRequest;

class StoreBusinessRequest extends FormRequest
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
            'legal_name' => ['required', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'nui' => ['required', 'string', 'regex:'.KosovoIdentifier::BUSINESS_PATTERN, 'unique:businesses,nui'],
            'legal_form' => ['nullable', 'string', 'max:40'],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'vat_status' => ['required', 'string', 'in:unknown,registered,not_registered'],
            'municipality' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'activity_code' => ['nullable', 'string', 'max:32'],
            'registered_at' => ['nullable', 'date'],
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
