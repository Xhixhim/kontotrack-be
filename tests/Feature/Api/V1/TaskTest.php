<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\BusinessTask;
use App\Models\ComplianceRuleSet;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-07-20 10:00:00');
    }

    public function test_generates_the_years_filing_deadlines_once_from_the_approved_calendar(): void
    {
        [$user, $business] = $this->taskBusiness('accountant');
        $this->approvedCalendar();

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/tasks/generate-tax-calendar", ['year' => 2026])
            ->assertOk()
            ->assertJsonPath('data.created', 13)
            ->assertJsonPath('data.rule_version', 'calendar-test-1');
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/tasks/generate-tax-calendar", ['year' => 2026])
            ->assertOk()
            ->assertJsonPath('data.created', 0);

        $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/tasks?from=2026-08-01&to=2026-08-31")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'VAT return 2026-07')
            ->assertJsonPath('data.0.due_on', '2026-08-20')
            ->assertJsonPath('data.0.kind', 'tax_filing');
    }

    public function test_rejects_generation_without_an_approved_calendar_with_422(): void
    {
        [$user, $business] = $this->taskBusiness('accountant');

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/tasks/generate-tax-calendar", ['year' => 2026])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['year' => 'No approved tax calendar is effective for this year.']);
    }

    public function test_filing_a_declaration_requires_the_confirmation_document(): void
    {
        [$user, $business] = $this->taskBusiness('accountant');
        $filing = BusinessTask::factory()->for($business)->create(['kind' => 'tax_filing', 'obligation_type' => 'vat_return', 'period_label' => '2026-06', 'title' => 'VAT return 2026-06']);

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/tasks/{$filing->id}/complete", ['completed_on' => '2026-07-18'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['document_id' => 'Attach the filing confirmation before marking this declaration as filed.']);

        $confirmation = Document::factory()->for($business)->create(['category' => 'tax_filing']);
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/tasks/{$filing->id}/complete", [
            'completed_on' => '2026-07-18',
            'reference' => 'EDI-778899',
            'amount' => '1250.40',
            'document_id' => $confirmation->id,
        ])->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.reference', 'EDI-778899')
            ->assertJsonPath('data.document.id', $confirmation->id);

        $this->assertDatabaseHas('audit_logs', ['action' => 'tax_filing.filed', 'subject_id' => $filing->id]);
    }

    public function test_owner_creates_and_completes_a_manual_task_assigned_to_a_member(): void
    {
        [$owner, $business] = $this->taskBusiness('owner');
        $accountant = User::factory()->create();
        $business->users()->attach($accountant, ['role' => 'accountant', 'status' => 'active']);

        $taskId = $this->actingAs($owner)->postJson("/api/v1/businesses/{$business->id}/tasks", ['title' => 'Send lease contract', 'due_on' => '2026-07-25', 'assigned_to' => $accountant->id])
            ->assertCreated()
            ->assertJsonPath('data.assigned_to.id', $accountant->id)
            ->json('data.id');

        $this->actingAs($owner)->postJson("/api/v1/businesses/{$business->id}/tasks/{$taskId}/complete", ['completed_on' => '2026-07-21'])
            ->assertOk()
            ->assertJsonPath('data.status', 'done');
        $this->actingAs($owner)->putJson("/api/v1/businesses/{$business->id}/tasks/{$taskId}", ['title' => 'Changed'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['task' => 'Completed tasks cannot be changed.']);
    }

    public function test_rejects_assigning_a_task_to_a_non_member_with_422(): void
    {
        [$user, $business] = $this->taskBusiness('accountant');

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/tasks", ['title' => 'Check', 'due_on' => '2026-07-25', 'assigned_to' => User::factory()->create()->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['assigned_to' => 'Assign the task to an active member of this business.']);
    }

    public function test_auditor_cannot_create_tasks(): void
    {
        [$auditor, $business] = $this->taskBusiness('auditor');

        $this->actingAs($auditor)->postJson("/api/v1/businesses/{$business->id}/tasks", ['title' => 'Check', 'due_on' => '2026-07-25'])->assertForbidden();
    }

    public function test_overdue_tax_filing_is_critical_in_the_checks_and_appears_in_notifications(): void
    {
        [$user, $business] = $this->taskBusiness('accountant');
        BusinessTask::factory()->for($business)->create(['kind' => 'tax_filing', 'obligation_type' => 'vat_return', 'period_label' => '2026-05', 'title' => 'VAT return 2026-05', 'due_on' => '2026-06-20']);
        BusinessTask::factory()->for($business)->create(['title' => 'Due soon', 'due_on' => '2026-07-24']);
        BusinessTask::factory()->for($business)->create(['title' => 'Far away', 'due_on' => '2026-09-30']);
        BusinessTask::factory()->for(Business::factory())->create(['title' => 'Other business', 'due_on' => '2026-06-01']);

        $checks = $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/controls")->json('data.checks');
        $this->assertSame('critical', collect($checks)->firstWhere('key', 'deadlines')['severity']);

        $notifications = $this->actingAs($user)->getJson('/api/v1/me/notifications')->assertOk()->json('data');
        $this->assertSame([['task_overdue', 'VAT return 2026-05'], ['task_due_soon', 'Due soon']], array_map(fn (array $item): array => [$item['type'], $item['title']], $notifications));
    }

    public function test_approving_an_invalid_tax_calendar_returns_422(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_system_admin' => true])->save();
        $ruleSetId = $this->actingAs($admin)->postJson('/api/v1/compliance/rule-sets', [
            'jurisdiction' => 'XK',
            'domain' => 'tax_calendar',
            'version' => 'calendar-bad',
            'valid_from' => '2027-01-01',
            'source_title' => 'Test fixture',
            'source_url' => 'https://example.test/calendar',
            'rules' => [['rule_key' => 'deadlines', 'configuration' => ['deadlines' => [['type' => 'vat_return', 'title' => 'VAT', 'frequency' => 'weekly', 'due_day' => 20]]]]],
        ])->assertCreated()->json('data.id');

        $this->actingAs($admin)->postJson("/api/v1/compliance/rule-sets/{$ruleSetId}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rules');
    }

    /** @return array{User, Business} */
    private function taskBusiness(string $role): array
    {
        $user = User::factory()->create();
        $business = Business::factory()->vatRegistered()->create();
        $business->users()->attach($user, ['role' => $role, 'status' => 'active']);

        return [$user, $business];
    }

    private function approvedCalendar(): void
    {
        $ruleSet = ComplianceRuleSet::factory()->create(['domain' => 'tax_calendar', 'version' => 'calendar-test-1', 'approval_status' => 'approved', 'approved_at' => now()]);
        $ruleSet->rules()->create(['rule_key' => 'deadlines', 'configuration' => ['deadlines' => [
            ['type' => 'vat_return', 'title' => 'VAT return', 'frequency' => 'monthly', 'due_day' => 20, 'months_after_period' => 1, 'applies_to' => 'vat_registered'],
            ['type' => 'payroll_declaration', 'title' => 'Payroll declaration', 'frequency' => 'monthly', 'due_day' => 15, 'months_after_period' => 1, 'applies_to' => 'employers'],
            ['type' => 'annual_return', 'title' => 'Annual return', 'frequency' => 'annual', 'due_day' => 31, 'due_month' => 3, 'applies_to' => 'all'],
        ]]]);
    }
}
