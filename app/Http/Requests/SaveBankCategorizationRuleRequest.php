<?php

namespace App\Http\Requests;

use App\Services\Accounting\BankCategories;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveBankCategorizationRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'match_text' => [$required, 'string', 'min:3', 'max:120'],
            'direction' => ['sometimes', 'string', 'in:in,out,any'],
            'category' => [$required, 'string', 'in:'.implode(',', array_keys(BankCategories::ALL))],
            'account_id' => ['nullable', 'integer', 'min:1'],
            'counterparty_id' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
