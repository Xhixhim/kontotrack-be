<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'personal_number' => $this->personal_number,
            'position' => $this->position,
            'contract_type' => $this->contract_type,
            'employment_start' => $this->employment_start?->toDateString(),
            'employment_end' => $this->employment_end?->toDateString(),
            'gross_salary' => $this->gross_salary,
            'is_primary_employment' => $this->is_primary_employment,
            'iban' => $this->iban,
            'bank_name' => $this->bank_name,
            'status' => $this->status,
        ];
    }
}
