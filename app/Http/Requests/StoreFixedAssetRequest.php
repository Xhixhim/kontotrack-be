<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreFixedAssetRequest extends FormRequest
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
            'asset_code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:180'],
            'category' => ['required', 'string', 'max:80'],
            'location' => ['nullable', 'string', 'max:120'],
            'acquired_on' => ['required', 'date_format:Y-m-d'],
            'cost' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:99999999999999.99'],
            'residual_value' => ['nullable', 'numeric', 'gte:0', 'decimal:0,2', 'max:99999999999999.99'],
            'opening_accumulated_depreciation' => ['nullable', 'numeric', 'gte:0', 'decimal:0,2', 'max:99999999999999.99'],
            'depreciation_method' => ['required', 'string', 'in:straight_line,declining_balance'],
            'useful_life_months' => ['nullable', 'integer', 'between:1,1200'],
            'annual_rate_percent' => ['nullable', 'numeric', 'gt:0', 'max:100', 'decimal:0,4'],
            'document_id' => ['nullable', 'integer'],
        ];
    }
}
