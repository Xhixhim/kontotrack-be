<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreInventoryItemRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'supplier_counterparty_id' => ['nullable', 'integer'],
            'sku' => ['required', 'string', 'max:80'],
            'barcode' => ['nullable', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:1000'],
            'unit' => ['required', 'string', 'max:24'],
            'purchase_price' => ['required', 'numeric', 'gte:0', 'decimal:0,4', 'max:999999999999.9999'],
            'sale_price' => ['required', 'numeric', 'gte:0', 'decimal:0,4', 'max:999999999999.9999'],
            'minimum_quantity' => ['required', 'numeric', 'gte:0', 'decimal:0,4', 'max:999999999999.9999'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
