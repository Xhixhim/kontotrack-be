<?php

namespace App\Http\Requests;

use App\Services\Accounting\BankCategories;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CategorizeBankTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'category' => ['present', 'nullable', 'string', 'in:'.implode(',', array_keys(BankCategories::ALL))],
            'account_id' => ['nullable', 'integer', 'min:1'],
            'counterparty_id' => ['nullable', 'integer', 'min:1'],
            // Saves a rule so future lines whose description contains this text get the same category.
            'remember_as' => ['nullable', 'string', 'min:3', 'max:120'],
        ];
    }
}
