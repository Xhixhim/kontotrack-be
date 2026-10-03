<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DisposeFixedAssetRequest extends FormRequest
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
            'disposed_on' => ['required', 'date_format:Y-m-d'],
            'disposal_type' => ['required', 'string', 'in:sold,written_off'],
            'disposal_proceeds' => ['nullable', 'required_if:disposal_type,sold', 'numeric', 'gte:0', 'decimal:0,2', 'max:99999999999999.99'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
