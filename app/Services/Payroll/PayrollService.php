<?php

namespace App\Services\Payroll;

use App\Models\Business;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\User;
use App\Repositories\Contracts\ComplianceRuleSetRepository;
use App\Repositories\Contracts\PayrollRepository;
use App\Services\Accounting\JournalEntryService;
use App\Services\Accounting\PostingAccountService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayrollService
{
    public function __construct(
        private PayrollRepository $payrollRepository,
        private ComplianceRuleSetRepository $complianceRuleSetRepository,
        private PayrollCalculator $calculator,
        private JournalEntryService $journalEntryService,
        private PostingAccountService $postingAccountService,
    ) {}

    /** @return Collection<int, Employee> */
    public function listEmployees(Business $business): Collection
    {
        return $this->payrollRepository->listEmployees($business);
    }

    /** @param array<string, mixed> $attributes */
    public function createEmployee(Business $business, User $user, array $attributes): Employee
    {
        return DB::transaction(function () use ($business, $user, $attributes): Employee {
            $this->assertUniquePersonalNumber($business, $attributes['personal_number'] ?? null, null);
            $employee = $this->payrollRepository->createEmployee($business, $attributes);
            $business->auditLogs()->create([
                'user_id' => $user->getKey(),
                'action' => 'employee.created',
                'subject_type' => Employee::class,
                'subject_id' => $employee->getKey(),
                'new_values' => $employee->only(['first_name', 'last_name', 'position', 'gross_salary', 'employment_start', 'is_primary_employment']),
            ]);

            return $employee;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function updateEmployee(Business $business, User $user, int $employeeId, array $attributes): Employee
    {
        return DB::transaction(function () use ($business, $user, $employeeId, $attributes): Employee {
            $employee = $this->payrollRepository->findEmployeeForUpdate($business, $employeeId);

            if (array_key_exists('personal_number', $attributes)) {
                $this->assertUniquePersonalNumber($business, $attributes['personal_number'], (int) $employee->getKey());
            }

            $employee->fill($attributes);

            if ($employee->employment_end !== null && $employee->employment_end->lt($employee->employment_start)) {
                throw ValidationException::withMessages(['employment_end' => ['The employment end date cannot be before the start date.']]);
            }

            $changedAttributes = array_keys($employee->getDirty());

            if ($changedAttributes === []) {
                return $employee;
            }

            $oldValues = [];
            $newValues = [];

            foreach ($changedAttributes as $attribute) {
                $oldValues[$attribute] = $this->auditValue($attribute, $employee->getOriginal($attribute));
                $newValues[$attribute] = $this->auditValue($attribute, $employee->getAttribute($attribute));
            }
            $this->payrollRepository->saveEmployee($employee);
            $business->auditLogs()->create([
                'user_id' => $user->getKey(),
                'action' => 'employee.updated',
                'subject_type' => Employee::class,
                'subject_id' => $employee->getKey(),
                'old_values' => $oldValues,
                'new_values' => $newValues,
            ]);

            return $employee;
        });
    }

    /** @return Collection<int, PayrollRun> */
    public function listRuns(Business $business): Collection
    {
        return $this->payrollRepository->listRuns($business);
    }

    public function findRun(Business $business, int $runId): PayrollRun
    {
        return $this->payrollRepository->findRun($business, $runId);
    }

    /**
     * Calculate a draft payroll for every employee active during the month.
     *
     * Salaries are pro-rated by calendar days for employees who start or leave during the month; additions (bonus, overtime) are added to gross.
     *
     * @param  array{period_year: int, period_month: int, pay_date: string, additions?: list<array{employee_id: int, amount: string, note?: string|null}>}  $attributes
     */
    public function createDraftRun(Business $business, User $user, array $attributes): PayrollRun
    {
        return DB::transaction(function () use ($business, $user, $attributes): PayrollRun {
            $year = (int) $attributes['period_year'];
            $month = (int) $attributes['period_month'];
            $monthStart = Carbon::create($year, $month, 1)->toDateString();
            $monthEnd = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();

            if ($this->payrollRepository->runExistsForPeriod($business, $year, $month)) {
                throw ValidationException::withMessages(['period_month' => ['A payroll already exists for this month. Delete the draft to recalculate it.']]);
            }

            $ruleSet = $this->complianceRuleSetRepository->findEffective('XK', 'payroll', $monthEnd);

            if ($ruleSet === null) {
                throw ValidationException::withMessages(['period_month' => ['No approved payroll rules are effective for this month.']]);
            }

            $rules = [
                'pension_contributions' => $ruleSet->rules->firstWhere('rule_key', 'pension_contributions')?->configuration,
                'income_tax' => $ruleSet->rules->firstWhere('rule_key', 'income_tax')?->configuration,
            ];
            $problems = $this->calculator->problems($rules);

            if ($problems !== []) {
                throw ValidationException::withMessages(['compliance_rule_set' => $problems]);
            }

            $employees = $this->payrollRepository->employeesActiveBetween($business, $monthStart, $monthEnd);

            if ($employees->isEmpty()) {
                throw ValidationException::withMessages(['period_month' => ['No active employees were employed during this month.']]);
            }

            $additions = collect($attributes['additions'] ?? [])->keyBy(fn (array $addition): int => (int) $addition['employee_id']);
            $unknownEmployees = $additions->keys()->diff($employees->pluck('id')->map(fn ($id): int => (int) $id));

            if ($unknownEmployees->isNotEmpty()) {
                throw ValidationException::withMessages(['additions' => ['Additions can only be given to employees included in this month.']]);
            }

            $daysInMonth = Carbon::create($year, $month, 1)->daysInMonth;
            $totals = ['gross' => 0, 'employee' => 0, 'employer' => 0, 'tax' => 0, 'net' => 0];
            $lines = [];

            foreach ($employees as $employee) {
                $firstDay = max($employee->employment_start->toDateString(), $monthStart);
                $lastDay = min($employee->employment_end?->toDateString() ?? $monthEnd, $monthEnd);
                $workedDays = (int) Carbon::parse($firstDay)->diffInDays(Carbon::parse($lastDay)) + 1;
                $monthlyCents = $this->toCents((string) $employee->gross_salary);
                $baseCents = $workedDays >= $daysInMonth ? $monthlyCents : intdiv($monthlyCents * $workedDays + intdiv($daysInMonth, 2), $daysInMonth);
                $addition = $additions->get((int) $employee->getKey());
                $additionCents = $addition === null ? 0 : $this->toCents((string) $addition['amount']);
                $result = $this->calculator->calculate($baseCents + $additionCents, $employee->is_primary_employment, $rules);
                $totals['gross'] += $result['gross_cents'];
                $totals['employee'] += $result['employee_contribution_cents'];
                $totals['employer'] += $result['employer_contribution_cents'];
                $totals['tax'] += $result['income_tax_cents'];
                $totals['net'] += $result['net_cents'];
                $lines[] = [
                    'business_id' => $business->getKey(),
                    'employee_id' => $employee->getKey(),
                    'employee_name' => $employee->fullName(),
                    'is_primary_employment' => $employee->is_primary_employment,
                    'worked_days' => $workedDays,
                    'days_in_month' => $daysInMonth,
                    'base_salary' => $this->fromCents($baseCents),
                    'additions' => $this->fromCents($additionCents),
                    'additions_note' => $addition['note'] ?? null,
                    'gross_salary' => $this->fromCents($result['gross_cents']),
                    'employee_contribution' => $this->fromCents($result['employee_contribution_cents']),
                    'employer_contribution' => $this->fromCents($result['employer_contribution_cents']),
                    'taxable_income' => $this->fromCents($result['taxable_income_cents']),
                    'income_tax' => $this->fromCents($result['income_tax_cents']),
                    'net_salary' => $this->fromCents($result['net_cents']),
                ];
            }

            $run = $this->payrollRepository->createRun($business, [
                'period_year' => $year,
                'period_month' => $month,
                'pay_date' => $attributes['pay_date'],
                'status' => 'draft',
                'compliance_rule_set_id' => $ruleSet->getKey(),
                'rule_snapshot' => ['version' => $ruleSet->version, 'source_title' => $ruleSet->source_title, 'source_url' => $ruleSet->source_url, 'rules' => $rules],
                'gross_total' => $this->fromCents($totals['gross']),
                'employee_contribution_total' => $this->fromCents($totals['employee']),
                'employer_contribution_total' => $this->fromCents($totals['employer']),
                'income_tax_total' => $this->fromCents($totals['tax']),
                'net_total' => $this->fromCents($totals['net']),
                'created_by' => $user->getKey(),
            ], $lines);
            $business->auditLogs()->create([
                'user_id' => $user->getKey(),
                'action' => 'payroll_run.draft_created',
                'subject_type' => PayrollRun::class,
                'subject_id' => $run->getKey(),
                'new_values' => ['period' => sprintf('%04d-%02d', $year, $month), 'employees' => count($lines), 'gross_total' => $run->gross_total, 'net_total' => $run->net_total, 'rule_version' => $ruleSet->version],
            ]);

            return $run;
        });
    }

    /**
     * Approve a draft payroll by posting its journal entry on the last day of the month.
     */
    public function approveRun(Business $business, User $user, int $runId): PayrollRun
    {
        return DB::transaction(function () use ($business, $user, $runId): PayrollRun {
            $run = $this->payrollRepository->findRunForUpdate($business, $runId);

            if ($run->status !== 'draft') {
                throw ValidationException::withMessages(['payroll_run' => ['Only draft payrolls can be approved.']]);
            }

            $entryDate = Carbon::create($run->period_year, $run->period_month, 1)->endOfMonth()->toDateString();
            $periodId = $this->journalEntryService->findOpenPeriodIdForDate($business, $entryDate);

            if ($periodId === null) {
                throw ValidationException::withMessages(['payroll_run' => ['Open an accounting period that contains the last day of the payroll month.']]);
            }

            $grossCents = $this->toCents((string) $run->gross_total);
            $employeeCents = $this->toCents((string) $run->employee_contribution_total);
            $employerCents = $this->toCents((string) $run->employer_contribution_total);
            $taxCents = $this->toCents((string) $run->income_tax_total);
            $netCents = $this->toCents((string) $run->net_total);
            $purposes = ['salary_expense', 'net_salaries_payable'];

            if ($employerCents > 0) {
                $purposes[] = 'employer_contribution_expense';
            }

            if ($employeeCents + $employerCents > 0) {
                $purposes[] = 'pension_contributions_payable';
            }

            if ($taxCents > 0) {
                $purposes[] = 'income_tax_payable';
            }

            $accounts = $this->postingAccountService->requireAccounts($business, $purposes);
            $period = sprintf('%04d-%02d', $run->period_year, $run->period_month);
            $lines = [
                $this->line($accounts['salary_expense']->getKey(), "Gross salaries {$period}", $grossCents, 0),
                $employerCents > 0 ? $this->line($accounts['employer_contribution_expense']->getKey(), "Employer pension contributions {$period}", $employerCents, 0) : null,
                $this->line($accounts['net_salaries_payable']->getKey(), "Net salaries payable {$period}", 0, $netCents),
                $employeeCents + $employerCents > 0 ? $this->line($accounts['pension_contributions_payable']->getKey(), "Pension contributions payable {$period}", 0, $employeeCents + $employerCents) : null,
                $taxCents > 0 ? $this->line($accounts['income_tax_payable']->getKey(), "Income tax withheld {$period}", 0, $taxCents) : null,
            ];

            $journalEntry = $this->journalEntryService->createDraft($business, $user, [
                'accounting_period_id' => $periodId,
                'entry_date' => $entryDate,
                'reference' => "PAYROLL-{$period}",
                'description' => "Payroll {$period}",
                'lines' => array_values(array_filter($lines)),
            ]);
            $journalEntry = $this->journalEntryService->post($business, $user, (int) $journalEntry->getKey());
            $approvedRun = $this->payrollRepository->markRunApproved($run, $journalEntry, $user);
            $business->auditLogs()->create([
                'user_id' => $user->getKey(),
                'action' => 'payroll_run.approved',
                'subject_type' => PayrollRun::class,
                'subject_id' => $run->getKey(),
                'old_values' => ['status' => 'draft'],
                'new_values' => ['status' => 'approved', 'journal_entry_id' => $journalEntry->getKey()],
            ]);

            return $approvedRun;
        });
    }

    public function deleteDraftRun(Business $business, User $user, int $runId): void
    {
        DB::transaction(function () use ($business, $user, $runId): void {
            $run = $this->payrollRepository->findRunForUpdate($business, $runId);

            if ($run->status !== 'draft') {
                throw ValidationException::withMessages(['payroll_run' => ['Only draft payrolls can be deleted. Reverse the journal entry to correct an approved payroll.']]);
            }

            $business->auditLogs()->create([
                'user_id' => $user->getKey(),
                'action' => 'payroll_run.draft_deleted',
                'subject_type' => PayrollRun::class,
                'subject_id' => $run->getKey(),
                'old_values' => ['period' => sprintf('%04d-%02d', $run->period_year, $run->period_month), 'gross_total' => $run->gross_total],
            ]);
            $this->payrollRepository->deleteRun($run);
        });
    }

    /** Personal numbers and IBANs are encrypted at rest, so the audit log keeps only their last 4 characters. */
    private function auditValue(string $attribute, mixed $value): mixed
    {
        if (in_array($attribute, ['personal_number', 'iban'], true)) {
            return $value === null || $value === '' ? $value : '••••'.mb_substr((string) $value, -4);
        }

        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
    }

    private function assertUniquePersonalNumber(Business $business, ?string $personalNumber, ?int $exceptEmployeeId): void
    {
        if ($personalNumber !== null && $personalNumber !== '' && $this->payrollRepository->personalNumberExists($business, $personalNumber, $exceptEmployeeId)) {
            throw ValidationException::withMessages(['personal_number' => ['Another employee of this business already has this personal number.']]);
        }
    }

    /** @return array{account_id: int, counterparty_id: null, description: string, debit: string, credit: string} */
    private function line(int $accountId, string $description, int $debitCents, int $creditCents): array
    {
        return [
            'account_id' => $accountId,
            'counterparty_id' => null,
            'description' => $description,
            'debit' => $this->fromCents($debitCents),
            'credit' => $this->fromCents($creditCents),
        ];
    }

    private function toCents(string $amount): int
    {
        $negative = str_starts_with($amount, '-');
        $amount = ltrim($amount, '+-');
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $cents = ((int) $whole * 100) + (int) substr(str_pad($fraction, 2, '0'), 0, 2);

        return $negative ? -$cents : $cents;
    }

    private function fromCents(int $amount): string
    {
        $sign = $amount < 0 ? '-' : '';
        $absoluteAmount = abs($amount);

        return $sign.intdiv($absoluteAmount, 100).'.'.str_pad((string) ($absoluteAmount % 100), 2, '0', STR_PAD_LEFT);
    }
}
