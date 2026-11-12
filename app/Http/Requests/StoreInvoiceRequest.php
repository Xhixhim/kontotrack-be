<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInvoiceRequest extends FormRequest
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
            'kind' => ['required', 'string', 'in:sale,purchase'],
            'counterparty_id' => ['required', 'integer'],
            'business_unit_id' => ['nullable', 'integer'],
            'document_id' => ['nullable', 'integer'],
            'invoice_number' => ['required_if:kind,purchase', 'prohibited_if:kind,sale', 'nullable', 'string', 'max:80'],
            'issued_on' => ['required', 'date'],
            'due_on' => ['nullable', 'date', 'after_or_equal:issued_on'],
            'payment_method' => ['nullable', 'string', 'in:cash,bank_transfer,card,other'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,4', 'max:100000'],
            'lines.*.unit' => ['nullable', 'string', 'max:24'],
            'lines.*.unit_price' => ['required', 'numeric', 'gte:0', 'decimal:0,4', 'max:100000'],
            'lines.*.discount_amount' => ['sometimes', 'numeric', 'gte:0', 'decimal:0,2', 'max:99999999999999.99'],
            'lines.*.inventory_item_id' => ['nullable', 'integer'],
            'lines.*.warehouse_id' => ['nullable', 'integer'],
            'lines.*.vat_code' => ['required', 'string', 'max:40'],
        ];
    }
}
