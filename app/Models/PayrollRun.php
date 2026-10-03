<?php

namespace App\Models;

use Database\Factories\PayrollRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['business_id', 'period_year', 'period_month', 'pay_date', 'status', 'compliance_rule_set_id', 'rule_snapshot', 'gross_total', 'employee_contribution_total', 'employer_contribution_total', 'income_tax_total', 'net_total', 'created_by'])]
class PayrollRun extends Model
{
    /** @use HasFactory<PayrollRunFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'pay_date' => 'date',
            'approved_at' => 'datetime',
            'rule_snapshot' => 'array',
            'gross_total' => 'decimal:2',
            'employee_contribution_total' => 'decimal:2',
            'employer_contribution_total' => 'decimal:2',
            'income_tax_total' => 'decimal:2',
            'net_total' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return HasMany<PayrollLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PayrollLine::class);
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
