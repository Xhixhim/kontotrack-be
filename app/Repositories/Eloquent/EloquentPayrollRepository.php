<?php

namespace App\Repositories\Eloquent;

use App\Models\Business;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\PayrollRun;
use App\Models\User;
use App\Repositories\Contracts\PayrollRepository;
use Illuminate\Database\Eloquent\Collection;

class EloquentPayrollRepository implements PayrollRepository
{
    /** @return Collection<int, Employee> */
    public function listEmployees(Business $business): Collection
    {
        return $business->employees()->orderBy('last_name')->orderBy('first_name')->get();
    }

    public function findEmployeeForUpdate(Business $business, int $employeeId): Employee
    {
        return $business->employees()->whereKey($employeeId)->lockForUpdate()->firstOrFail();
    }

    /** @param array<string, mixed> $attributes */
    public function createEmployee(Business $business, array $attributes): Employee
    {
        return $business->employees()->create($attributes);
    }

    public function saveEmployee(Employee $employee): Employee
    {
        $employee->save();

        return $employee;
    }

    /** @return Collection<int, Employee> */
    public function employeesActiveBetween(Business $business, string $from, string $to): Collection
    {
        return $business->employees()
            ->where('status', 'active')
            ->whereDate('employment_start', '<=', $to)
            ->where(fn ($query) => $query->whereNull('employment_end')->orWhereDate('employment_end', '>=', $from))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
    }

    public function personalNumberExists(Business $business, string $personalNumber, ?int $exceptEmployeeId = null): bool
    {
        return $business->employees()
            ->where('personal_number', $personalNumber)
            ->when($exceptEmployeeId !== null, fn ($query) => $query->whereKeyNot($exceptEmployeeId))
            ->exists();
    }

    /** @return Collection<int, PayrollRun> */
    public function listRuns(Business $business): Collection
    {
        return $business->payrollRuns()->orderByDesc('period_year')->orderByDesc('period_month')->get();
    }

    public function runExistsForPeriod(Business $business, int $year, int $month): bool
    {
        return $business->payrollRuns()->where('period_year', $year)->where('period_month', $month)->exists();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $lines
     */
    public function createRun(Business $business, array $attributes, array $lines): PayrollRun
    {
        $run = $business->payrollRuns()->create($attributes);
        $run->lines()->createMany($lines);

        return $run->load('lines');
    }

    public function findRun(Business $business, int $runId): PayrollRun
    {
        return $business->payrollRuns()->whereKey($runId)->with('lines')->firstOrFail();
    }

    public function findRunForUpdate(Business $business, int $runId): PayrollRun
    {
        return $business->payrollRuns()->whereKey($runId)->lockForUpdate()->with('lines')->firstOrFail();
    }

    public function markRunApproved(PayrollRun $run, JournalEntry $journalEntry, User $user): PayrollRun
    {
        $run->forceFill([
            'status' => 'approved',
            'journal_entry_id' => $journalEntry->getKey(),
            'approved_by' => $user->getKey(),
            'approved_at' => now(),
        ])->save();

        return $run->refresh()->load('lines');
    }

    public function deleteRun(PayrollRun $run): void
    {
        $run->delete();
    }
}
