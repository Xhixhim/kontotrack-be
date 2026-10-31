<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ImportBankStatementRequest extends FormRequest
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
        $isWorkbook = strtolower((string) $this->file('file')?->getClientOriginalExtension()) === 'xlsx';

        return [
            'file' => [
                'required', 'file', 'max:10240', 'extensions:csv,txt,xlsx',
                // Some spreadsheet apps write .xlsx files that are detected as plain zip archives.
                $isWorkbook ? 'mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip,application/octet-stream' : 'mimes:csv,txt',
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.extensions' => 'Upload the bank statement as .xlsx or .csv. Old .xls files must be saved as .xlsx first.',
        ];
    }
}
