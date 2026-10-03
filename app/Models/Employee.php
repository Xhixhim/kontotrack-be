<?php

namespace App\Models;

use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'first_name', 'last_name', 'personal_number', 'position', 'contract_type', 'employment_start', 'employment_end', 'gross_salary', 'is_primary_employment', 'iban', 'bank_name', 'status'])]
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'employment_start' => 'date',
            'employment_end' => 'date',
            'gross_salary' => 'decimal:2',
            'is_primary_employment' => 'boolean',
        ];
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }
}
