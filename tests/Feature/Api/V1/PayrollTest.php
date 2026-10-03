<?php

namespace Tests\Feature\Api\V1;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\BusinessPostingAccount;
use App\Models\ComplianceRuleSet;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\PayrollRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-07-20 10:00:00');
    }

    public function test_accountant_adds_an_employee_and_it_is_audited(): void
    {
        [$user, $business] = $this->payrollBusiness();

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/employees", [
            'first_name' => 'Arbër',
            'last_name' => 'Hoxha',
            'personal_number' => '1234567890',
            'position' => 'Kamarier',
            'contract_type' => 'indefinite',
            'employment_start' => '2026-03-01',
            'gross_salary' => '500.00',
            'is_primary_employment' => true,
        ])->assertCreated()->assertJsonPath('data.gross_salary', '500.00');

        $this->assertSame('employee.created', AuditLog::query()->where('subject_type', Employee::class)->sole()->action);
    }

    public function test_rejects_a_personal_number_already_used_in_the_business_with_422(): void
    {
        [$user, $business] = $this->payrollBusiness();
        Employee::factory()->for($business)->create(['personal_number' => '1234567890']);

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/employees", [
            'first_name' => 'Besa',
            'last_name' => 'Krasniqi',
            'personal_number' => '1234567890',
            'contract_type' => 'indefinite',
            'employment_start' => '2026-03-01',
            'gross_salary' => '500.00',
            'is_primary_employment' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors(['personal_number' => 'Another employee of this business already has this personal number.']);
    }

    public function test_salary_change_is_recorded_with_old_and_new_values(): void
    {
        [$user, $business] = $this->payrollBusiness();
        $employee = Employee::factory()->for($business)->create(['gross_salary' => '600.00']);

        $this->actingAs($user)
            ->putJson("/api/v1/businesses/{$business->id}/employees/{$employee->id}", ['gross_salary' => '650.00'])
            ->assertOk()
            ->assertJsonPath('data.gross_salary', '650.00');

        $auditLog = AuditLog::query()->where('action', 'employee.updated')->sole();
        $this->assertSame(['gross_salary' => '600.00'], $auditLog->old_values);
        $this->assertSame(['gross_salary' => '650.00'], $auditLog->new_values);
    }

    public function test_rejects_an_employment_end_before_the_start_with_422(): void
    {
        [$user, $business] = $this->payrollBusiness();
        $employee = Employee::factory()->for($business)->create(['employment_start' => '2026-03-01']);

        $this->actingAs($user)
            ->putJson("/api/v1/businesses/{$business->id}/employees/{$employee->id}", ['employment_end' => '2026-02-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['employment_end' => 'The employment end date cannot be before the start date.']);
    }

    public function test_returns_404_for_an_employee_of_another_business(): void
    {
        [$user, $business] = $this->payrollBusiness();
        $foreignEmployee = Employee::factory()->create();

        $this->actingAs($user)
            ->putJson("/api/v1/businesses/{$business->id}/employees/{$foreignEmployee->id}", ['gross_salary' => '1.00'])
            ->assertNotFound();
    }

    public function test_assistant_cannot_see_salaries_but_auditor_can(): void
    {
        [, $business] = $this->payrollBusiness();
        Employee::factory()->for($business)->create();
        $assistant = $this->member($business, 'assistant_accountant');
        $auditor = $this->member($business, 'auditor');

        $this->actingAs($assistant)->getJson("/api/v1/businesses/{$business->id}/employees")->assertForbidden();
        $this->actingAs($assistant)->getJson("/api/v1/businesses/{$business->id}/payroll-runs")->assertForbidden();
        $this->actingAs($auditor)->getJson("/api/v1/businesses/{$business->id}/employees")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_draft_payroll_includes_only_employees_working_that_month(): void
    {
        [$user, $business] = $this->payrollBusiness();
        Employee::factory()->for($business)->create(['first_name' => 'Ana', 'last_name' => 'A', 'gross_salary' => '400.00']);
        Employee::factory()->for($business)->create(['first_name' => 'Bujar', 'last_name' => 'B', 'gross_salary' => '1000.00', 'employment_end' => '2026-06-10']);
        Employee::factory()->for($business)->create(['last_name' => 'Later', 'employment_start' => '2026-07-01']);
        Employee::factory()->for($business)->create(['last_name' => 'Gone', 'employment_end' => '2026-05-31']);
        Employee::factory()->for($business)->create(['last_name' => 'Inactive', 'status' => 'inactive']);

        $response = $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/payroll-runs", [
            'period_year' => 2026,
            'period_month' => 6,
            'pay_date' => '2026-07-05',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.rule_version', 'payroll-test-1')
            ->assertJsonCount(2, 'data.lines')
            ->assertJsonPath('data.lines.0.employee_name', 'Ana A')
            ->assertJsonPath('data.lines.0.income_tax', '10.40')
            ->assertJsonPath('data.lines.0.net_salary', '369.60')
            ->assertJsonPath('data.lines.1.worked_days', 10)
            ->assertJsonPath('data.lines.1.gross_salary', '333.33')
            ->assertJsonPath('data.lines.1.net_salary', '311.33')
            ->assertJsonPath('data.gross_total', '733.33')
            ->assertJsonPath('data.employee_contribution_total', '36.67')
            ->assertJsonPath('data.employer_contribution_total', '36.67')
            ->assertJsonPath('data.income_tax_total', '15.73')
            ->assertJsonPath('data.net_total', '680.93');
    }

    public function test_pro_rates_mid_month_starters_and_adds_bonuses_to_gross(): void
    {
        [$user, $business] = $this->payrollBusiness();
        $starter = Employee::factory()->for($business)->create(['last_name' => 'A-Starter', 'gross_salary' => '1000.00', 'employment_start' => '2026-06-16']);
        $regular = Employee::factory()->for($business)->create(['last_name' => 'B-Regular', 'gross_salary' => '1000.00']);

        $lines = collect($this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/payroll-runs", [
            'period_year' => 2026,
            'period_month' => 6,
            'pay_date' => '2026-07-05',
            'additions' => [['employee_id' => $regular->id, 'amount' => '200.00', 'note' => 'Overtime']],
        ])->assertCreated()->json('data.lines'))->keyBy('employee_id');

        $starterLine = $lines[$starter->id];
        $regularLine = $lines[$regular->id];
        $this->assertSame([15, 30, '500.00', '0.00', '500.00'], [$starterLine['worked_days'], $starterLine['days_in_month'], $starterLine['base_salary'], $starterLine['additions'], $starterLine['gross_salary']]);
        $this->assertSame(['1000.00', '200.00', 'Overtime', '1200.00'], [$regularLine['base_salary'], $regularLine['additions'], $regularLine['additions_note'], $regularLine['gross_salary']]);
        $this->assertSame('1055.00', $regularLine['net_salary']);
    }

    public function test_rejects_additions_for_employees_not_in_the_month_with_422(): void
    {
        [$user, $business] = $this->payrollBusiness();
        Employee::factory()->for($business)->create();
        $gone = Employee::factory()->for($business)->create(['employment_end' => '2026-04-30']);

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/payroll-runs", [
            'period_year' => 2026,
            'period_month' => 6,
            'pay_date' => '2026-07-05',
            'additions' => [['employee_id' => $gone->id, 'amount' => '50.00']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['additions' => 'Additions can only be given to employees included in this month.']);
    }

    public function test_rejects_a_second_payroll_for_the_same_month_with_422(): void
    {
        [$user, $business] = $this->payrollBusiness();
        Employee::factory()->for($business)->create();
        $this->createRun($user, $business);

        $this->actingAs($user)
            ->postJson("/api/v1/businesses/{$business->id}/payroll-runs", ['period_year' => 2026, 'period_month' => 6, 'pay_date' => '2026-07-05'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['period_month' => 'A payroll already exists for this month. Delete the draft to recalculate it.']);
    }

    public function test_rejects_a_payroll_when_no_payroll_rules_are_approved_with_422(): void
    {
        [$user, $business] = $this->payrollBusiness();
        Employee::factory()->for($business)->create();
        ComplianceRuleSet::query()->where('domain', 'payroll')->update(['approval_status' => 'draft']);

        $this->actingAs($user)
            ->postJson("/api/v1/businesses/{$business->id}/payroll-runs", ['period_year' => 2026, 'period_month' => 6, 'pay_date' => '2026-07-05'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['period_month' => 'No approved payroll rules are effective for this month.']);
    }

    public function test_approving_posts_the_payroll_journal_on_the_last_day_of_the_month(): void
    {
        [$user, $business] = $this->payrollBusiness();
        $accounts = $this->mapPayrollAccounts($business);
        Employee::factory()->for($business)->create(['gross_salary' => '1000.00']);
        $runId = $this->createRun($user, $business);

        $this->actingAs($user)
            ->postJson("/api/v1/businesses/{$business->id}/payroll-runs/{$runId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $entry = JournalEntry::query()->with('lines')->findOrFail(PayrollRun::query()->findOrFail($runId)->journal_entry_id);
        $this->assertSame('posted', $entry->status);
        $this->assertSame('2026-06-30', $entry->entry_date->toDateString());
        $this->assertSame([
            [$accounts['salary_expense']->id, '1000.00', '0.00'],
            [$accounts['employer_contribution_expense']->id, '50.00', '0.00'],
            [$accounts['net_salaries_payable']->id, '0.00', '884.00'],
            [$accounts['pension_contributions_payable']->id, '0.00', '100.00'],
            [$accounts['income_tax_payable']->id, '0.00', '66.00'],
        ], $entry->lines->sortBy('id')->map(fn ($line): array => [$line->account_id, (string) $line->debit, (string) $line->credit])->values()->all());
        $periodId = $business->accountingPeriods()->value('id');
        $checks = $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/periods/{$periodId}/closing-checks")->json('data.checks');
        $this->assertSame('passed', collect($checks)->firstWhere('key', 'source_documents')['status']);
    }

    public function test_rejects_approval_with_422_and_keeps_the_draft_when_payroll_accounts_are_not_mapped(): void
    {
        [$user, $business] = $this->payrollBusiness();
        Employee::factory()->for($business)->create();
        $runId = $this->createRun($user, $business);

        $this->actingAs($user)
            ->postJson("/api/v1/businesses/{$business->id}/payroll-runs/{$runId}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('posting_accounts');

        $this->assertSame('draft', PayrollRun::query()->findOrFail($runId)->status);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_a_draft_can_be_deleted_but_an_approved_payroll_cannot(): void
    {
        [$user, $business] = $this->payrollBusiness();
        $this->mapPayrollAccounts($business);
        Employee::factory()->for($business)->create();
        $runId = $this->createRun($user, $business);
        $this->actingAs($user)->deleteJson("/api/v1/businesses/{$business->id}/payroll-runs/{$runId}")->assertOk();
        $this->assertDatabaseMissing('payroll_runs', ['id' => $runId]);

        $approvedRunId = $this->createRun($user, $business);
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/payroll-runs/{$approvedRunId}/approve")->assertOk();

        $this->actingAs($user)
            ->deleteJson("/api/v1/businesses/{$business->id}/payroll-runs/{$approvedRunId}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payroll_run' => 'Only draft payrolls can be deleted. Reverse the journal entry to correct an approved payroll.']);
    }

    public function test_auditor_cannot_create_a_payroll(): void
    {
        [, $business] = $this->payrollBusiness();
        Employee::factory()->for($business)->create();

        $this->actingAs($this->member($business, 'auditor'))
            ->postJson("/api/v1/businesses/{$business->id}/payroll-runs", ['period_year' => 2026, 'period_month' => 6, 'pay_date' => '2026-07-05'])
            ->assertForbidden();
    }

    public function test_approving_a_payroll_rule_set_with_invalid_brackets_returns_422(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_system_admin' => true])->save();
        $rules = $this->payrollRules();
        $rules['income_tax']['primary_brackets'][2]['up_to'] = '900';
        $ruleSetId = $this->actingAs($admin)->postJson('/api/v1/compliance/rule-sets', [
            'jurisdiction' => 'XK',
            'domain' => 'payroll',
            'version' => 'payroll-bad',
            'valid_from' => '2027-01-01',
            'source_title' => 'Test fixture',
            'source_url' => 'https://example.test/payroll',
            'rules' => [
                ['rule_key' => 'pension_contributions', 'configuration' => $rules['pension_contributions']],
                ['rule_key' => 'income_tax', 'configuration' => $rules['income_tax']],
            ],
        ])->assertCreated()->json('data.id');

        $this->actingAs($admin)
            ->postJson("/api/v1/compliance/rule-sets/{$ruleSetId}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rules' => 'The last income tax bracket must have up_to null.']);
    }

    /** @return array{User, Business} */
    private function payrollBusiness(): array
    {
        $business = Business::factory()->create();
        $user = $this->member($business, 'accountant');
        AccountingPeriod::factory()->for($business)->create();
        $ruleSet = ComplianceRuleSet::factory()->create([
            'domain' => 'payroll',
            'version' => 'payroll-test-1',
            'approval_status' => 'approved',
            'approved_at' => now(),
        ]);
        $rules = $this->payrollRules();
        $ruleSet->rules()->createMany([
            ['rule_key' => 'pension_contributions', 'configuration' => $rules['pension_contributions']],
            ['rule_key' => 'income_tax', 'configuration' => $rules['income_tax']],
        ]);

        return [$user, $business];
    }

    /**
     * Test fixture parameters, not official rates.
     *
     * @return array{pension_contributions: array<string, string>, income_tax: array<string, mixed>}
     */
    private function payrollRules(): array
    {
        return [
            'pension_contributions' => ['employee_percent' => '5', 'employer_percent' => '5'],
            'income_tax' => [
                'primary_brackets' => [
                    ['up_to' => '250', 'rate_percent' => '0'],
                    ['up_to' => '450', 'rate_percent' => '8'],
                    ['up_to' => null, 'rate_percent' => '10'],
                ],
                'secondary_rate_percent' => '10',
            ],
        ];
    }

    /** @return array<string, Account> */
    private function mapPayrollAccounts(Business $business): array
    {
        $types = [
            'salary_expense' => 'expense',
            'employer_contribution_expense' => 'expense',
            'net_salaries_payable' => 'liability',
            'pension_contributions_payable' => 'liability',
            'income_tax_payable' => 'liability',
        ];
        $accounts = [];

        foreach ($types as $purpose => $type) {
            $accounts[$purpose] = Account::factory()->for($business)->ofType($type)->create();
            BusinessPostingAccount::factory()->create(['business_id' => $business->id, 'purpose' => $purpose, 'account_id' => $accounts[$purpose]->id]);
        }

        return $accounts;
    }

    private function createRun(User $user, Business $business): int
    {
        return $this->actingAs($user)
            ->postJson("/api/v1/businesses/{$business->id}/payroll-runs", ['period_year' => 2026, 'period_month' => 6, 'pay_date' => '2026-07-05'])
            ->assertCreated()
            ->json('data.id');
    }

    private function member(Business $business, string $role): User
    {
        $user = User::factory()->create();
        $business->users()->attach($user, ['role' => $role, 'status' => 'active']);

        return $user;
    }
}
