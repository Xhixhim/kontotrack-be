<?php

namespace App\Models;

use App\Support\BlindIndex;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Personal number and IBAN are encrypted at rest; personal_number_hash is the blind index used
 * to find duplicates (see App\Support\BlindIndex).
 */
#[Fillable(['business_id', 'first_name', 'last_name', 'personal_number', 'position', 'contract_type', 'employment_start', 'employment_end', 'gross_salary', 'is_primary_employment', 'iban', 'bank_name', 'status'])]
#[Hidden(['personal_number_hash'])]
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
            'personal_number' => 'encrypted',
            'iban' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Employee $employee): void {
            if ($employee->isDirty(['personal_number', 'business_id']) || ($employee->personal_number_hash === null && $employee->personal_number !== null)) {
                $employee->personal_number_hash = BlindIndex::personalNumber((int) $employee->business_id, $employee->personal_number);
            }
        });
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
