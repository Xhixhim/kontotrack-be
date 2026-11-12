<?php

namespace App\Http\Requests;

use App\Models\Counterparty;
use App\Rules\CounterpartyIdentifier;
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
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        // The identifier is judged by the country sent with it, or the one already stored for this contact.
        $countryCode = $this->input('country_code') ?: Counterparty::query()
            ->where('business_id', $this->route('business')?->getKey())
            ->whereKey($this->route('counterparty'))
            ->value('country_code');

        return [
            'type' => ['sometimes', 'required', 'string', 'in:customer,supplier,both'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'nui' => ['sometimes', 'nullable', 'string', 'max:32', new CounterpartyIdentifier((string) ($countryCode ?: 'XK'))],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'municipality' => ['sometimes', 'nullable', 'string', 'max:100'],
            'country_code' => ['sometimes', 'required', 'string', 'size:2'],
            'status' => ['sometimes', 'required', 'string', 'in:active,inactive'],
        ];
    }
}
