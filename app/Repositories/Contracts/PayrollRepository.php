<?php

namespace App\Repositories\Contracts;

use App\Models\Business;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\PayrollRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

interface PayrollRepository
{
    /** @return Collection<int, Employee> */
    public function listEmployees(Business $business): Collection;

    public function findEmployeeForUpdate(Business $business, int $employeeId): Employee;

    /** @param array<string, mixed> $attributes */
    public function createEmployee(Business $business, array $attributes): Employee;

    public function saveEmployee(Employee $employee): Employee;

    /** @return Collection<int, Employee> */
    public function employeesActiveBetween(Business $business, string $from, string $to): Collection;

    public function personalNumberExists(Business $business, string $personalNumber, ?int $exceptEmployeeId = null): bool;

    /** @return Collection<int, PayrollRun> */
    public function listRuns(Business $business): Collection;

    public function runExistsForPeriod(Business $business, int $year, int $month): bool;

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $lines
     */
    public function createRun(Business $business, array $attributes, array $lines): PayrollRun;

    public function findRun(Business $business, int $runId): PayrollRun;

    public function findRunForUpdate(Business $business, int $runId): PayrollRun;

    public function markRunApproved(PayrollRun $run, JournalEntry $journalEntry, User $user): PayrollRun;

    public function deleteRun(PayrollRun $run): void;
}
