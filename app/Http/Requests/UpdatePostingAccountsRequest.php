<?php

namespace App\Http\Requests;

use App\Services\Accounting\PostingAccountService;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePostingAccountsRequest extends FormRequest
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
            'accounts' => ['required', 'array', 'array:'.implode(',', array_keys(PostingAccountService::PURPOSES))],
            'accounts.*' => ['nullable', 'integer'],
        ];
    }
}
