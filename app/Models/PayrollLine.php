<?php

namespace App\Models;

use Database\Factories\PayrollLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'payroll_run_id', 'employee_id', 'employee_name', 'is_primary_employment', 'worked_days', 'days_in_month', 'base_salary', 'additions', 'additions_note', 'gross_salary', 'employee_contribution', 'employer_contribution', 'taxable_income', 'income_tax', 'net_salary'])]
class PayrollLine extends Model
{
    /** @use HasFactory<PayrollLineFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_primary_employment' => 'boolean',
            'base_salary' => 'decimal:2',
            'additions' => 'decimal:2',
            'gross_salary' => 'decimal:2',
            'employee_contribution' => 'decimal:2',
            'employer_contribution' => 'decimal:2',
            'taxable_income' => 'decimal:2',
            'income_tax' => 'decimal:2',
            'net_salary' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<PayrollRun, $this> */
    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
