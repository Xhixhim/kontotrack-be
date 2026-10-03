<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreStockMovementRequest extends FormRequest
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
            'inventory_item_id' => ['required', 'integer'],
            'warehouse_id' => ['required', 'integer'],
            'direction' => ['required', 'string', 'in:in,out'],
            'movement_type' => ['required', 'string', 'in:opening,adjustment'],
            'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,4', 'max:100000000'],
            'unit_cost' => ['nullable', 'numeric', 'gte:0', 'decimal:0,4', 'max:999999999999.9999'],
            'document_id' => ['required', 'integer'],
            'occurred_on' => ['required', 'date'],
            'notes' => ['required', 'string', 'max:5000'],
        ];
    }
}
