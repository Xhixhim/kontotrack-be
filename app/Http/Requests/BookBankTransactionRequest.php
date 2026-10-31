<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class BookBankTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            // Optional for customer receipts and supplier payments; oldest open invoices are paid first otherwise.
            'allocations' => ['sometimes', 'array', 'min:1', 'max:100'],
            'allocations.*.invoice_id' => ['required', 'integer', 'distinct'],
            'allocations.*.amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:99999999999999.99'],
        ];
    }
}
