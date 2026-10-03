<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayrollRunResource extends JsonResource
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
            'period_year' => $this->period_year,
            'period_month' => $this->period_month,
            'pay_date' => $this->pay_date?->toDateString(),
            'status' => $this->status,
            'rule_version' => $this->rule_snapshot['version'] ?? null,
            'rule_source_title' => $this->rule_snapshot['source_title'] ?? null,
            'gross_total' => $this->gross_total,
            'employee_contribution_total' => $this->employee_contribution_total,
            'employer_contribution_total' => $this->employer_contribution_total,
            'income_tax_total' => $this->income_tax_total,
            'net_total' => $this->net_total,
            'journal_entry_id' => $this->journal_entry_id,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->sortBy('employee_name')->values()->map(fn ($line): array => [
                'id' => $line->id,
                'employee_id' => $line->employee_id,
                'employee_name' => $line->employee_name,
                'is_primary_employment' => $line->is_primary_employment,
                'worked_days' => $line->worked_days,
                'days_in_month' => $line->days_in_month,
                'base_salary' => $line->base_salary,
                'additions' => $line->additions,
                'additions_note' => $line->additions_note,
                'gross_salary' => $line->gross_salary,
                'employee_contribution' => $line->employee_contribution,
                'employer_contribution' => $line->employer_contribution,
                'taxable_income' => $line->taxable_income,
                'income_tax' => $line->income_tax,
                'net_salary' => $line->net_salary,
            ])),
        ];
    }
}
