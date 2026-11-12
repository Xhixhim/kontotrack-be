<?php

namespace App\Http\Requests;

use App\Policies\BusinessPolicy;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBusinessMemberRequest extends FormRequest
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
            'role' => ['sometimes', 'required', 'string', 'in:accountant,assistant_accountant,auditor'],
            'restrictions' => ['sometimes', 'array'],
            'restrictions.*' => ['string', 'distinct', Rule::in(BusinessPolicy::RESTRICTIONS)],
        ];
    }
}
