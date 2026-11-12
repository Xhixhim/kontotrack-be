<?php

namespace App\Http\Requests;

use App\Rules\CounterpartyIdentifier;
use Illuminate\Foundation\Http\FormRequest;

class StoreCounterpartyRequest extends FormRequest
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
            'type' => ['required', 'string', 'in:customer,supplier,both'],
            'name' => ['required', 'string', 'max:255'],
            'nui' => ['nullable', 'string', 'max:32', new CounterpartyIdentifier((string) ($this->input('country_code') ?: 'XK'))],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'municipality' => ['nullable', 'string', 'max:100'],
            'country_code' => ['nullable', 'string', 'size:2'],
        ];
    }
}
