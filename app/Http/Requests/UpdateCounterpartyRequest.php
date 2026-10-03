<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCounterpartyRequest extends FormRequest
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
            'type' => ['sometimes', 'required', 'string', 'in:customer,supplier,both'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'nui' => ['sometimes', 'nullable', 'string', 'max:32'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'municipality' => ['sometimes', 'nullable', 'string', 'max:100'],
            'country_code' => ['sometimes', 'required', 'string', 'size:2'],
            'status' => ['sometimes', 'required', 'string', 'in:active,inactive'],
        ];
    }
}
