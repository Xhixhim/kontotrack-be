<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RequestPeriodReopeningRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            // What must be corrected and why; kept permanently in the audit trail.
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }
}
