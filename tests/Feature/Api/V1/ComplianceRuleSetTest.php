<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComplianceRuleSetTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_system_admins_can_create_rule_sets(): void
    {
        $this->actingAs(User::factory()->create())->postJson('/api/v1/compliance/rule-sets', $this->payload('v1', '2026-01-01'))->assertForbidden();
    }

    public function test_admin_drafts_edits_and_approves_a_rule_set_which_then_becomes_effective(): void
    {
        $admin = $this->admin();
        $ruleSetId = $this->actingAs($admin)->postJson('/api/v1/compliance/rule-sets', $this->payload('v1', '2026-01-01'))->assertCreated()->json('data.id');
        $this->actingAs($admin)->putJson("/api/v1/compliance/rule-sets/{$ruleSetId}", $this->payload('v1', '2026-02-01'))->assertOk();

        $this->actingAs($admin)->postJson("/api/v1/compliance/rule-sets/{$ruleSetId}/approve")->assertOk()->assertJsonPath('data.approval_status', 'approved');

        $this->actingAs($admin)->getJson('/api/v1/compliance/rule-sets/effective?jurisdiction=XK&domain=vat&date=2026-03-01')
            ->assertOk()
            ->assertJsonPath('data.version', 'v1');
        $this->assertDatabaseHas('audit_logs', ['action' => 'compliance_rule_set.approved', 'subject_id' => $ruleSetId]);
    }

    public function test_approved_rule_sets_cannot_be_edited(): void
    {
        $admin = $this->admin();
        $ruleSetId = $this->actingAs($admin)->postJson('/api/v1/compliance/rule-sets', $this->payload('v1', '2026-01-01'))->json('data.id');
        $this->actingAs($admin)->postJson("/api/v1/compliance/rule-sets/{$ruleSetId}/approve")->assertOk();

        $this->actingAs($admin)->putJson("/api/v1/compliance/rule-sets/{$ruleSetId}", $this->payload('v1', '2026-05-01'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['compliance_rule_set' => 'Approved compliance rule sets are immutable.']);
    }

    public function test_approval_rejects_dates_overlapping_an_approved_version(): void
    {
        $admin = $this->admin();
        $firstId = $this->actingAs($admin)->postJson('/api/v1/compliance/rule-sets', $this->payload('v1', '2026-01-01'))->json('data.id');
        $this->actingAs($admin)->postJson("/api/v1/compliance/rule-sets/{$firstId}/approve")->assertOk();
        $secondId = $this->actingAs($admin)->postJson('/api/v1/compliance/rule-sets', $this->payload('v2', '2026-06-01'))->json('data.id');

        $this->actingAs($admin)->postJson("/api/v1/compliance/rule-sets/{$secondId}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['valid_from' => 'An approved rule set already covers part of this effective date range.']);
    }

    public function test_requires_an_https_official_source(): void
    {
        $payload = $this->payload('v1', '2026-01-01');
        $payload['source_url'] = 'http://example.test/vat';

        $this->actingAs($this->admin())->postJson('/api/v1/compliance/rule-sets', $payload)->assertUnprocessable()->assertJsonValidationErrors('source_url');
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_system_admin' => true])->save();

        return $admin;
    }

    /** @return array<string, mixed> Test fixture codes, not official rates. */
    private function payload(string $version, string $validFrom): array
    {
        return [
            'jurisdiction' => 'XK',
            'domain' => 'vat',
            'version' => $version,
            'valid_from' => $validFrom,
            'source_title' => 'Test fixture',
            'source_url' => 'https://example.test/vat',
            'rules' => [['rule_key' => 'vat_codes', 'configuration' => ['codes' => [['code' => 'S', 'label' => 'Standard (fixture)', 'rate_percent' => '18', 'treatment' => 'taxable']]]]],
        ];
    }
}
