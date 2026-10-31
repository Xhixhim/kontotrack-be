<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DecidePeriodReopeningRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            // Required when rejecting, so the requester knows why.
            'note' => [str_ends_with($this->path(), '/reject') ? 'required' : 'nullable', 'string', 'max:2000'],
        ];
    }
}
