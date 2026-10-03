<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAccountRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:255'],
            'account_type' => ['required', 'string', 'in:asset,liability,equity,revenue,expense'],
            'normal_balance' => ['required', 'string', 'in:debit,credit'],
            'parent_id' => ['nullable', 'integer'],
        ];
    }
}
