<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StorePaymentRequest extends FormRequest
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
            'cash_account_id' => ['nullable', 'integer', 'required_without:bank_account_id'],
            'bank_account_id' => ['nullable', 'integer', 'required_without:cash_account_id'],
            'journal_entry_id' => ['nullable', 'integer'],
            'document_id' => ['required', 'integer', 'min:1'],
            'paid_on' => ['required', 'date'],
            'direction' => ['required', 'string', 'in:in,out'],
            'reference' => ['nullable', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:99999999999999.99'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'allocations' => ['sometimes', 'array', 'min:1', 'max:100'],
            'offset_account_id' => ['nullable', 'integer', 'prohibits:allocations,journal_entry_id'],
            'allocations.*.invoice_id' => ['required', 'integer', 'distinct'],
            'allocations.*.amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:99999999999999.99'],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->filled('cash_account_id') === $this->filled('bank_account_id')) {
                $validator->errors()->add('cash_account_id', 'Choose exactly one cash or bank account.');
            }
        }];
    }
}
