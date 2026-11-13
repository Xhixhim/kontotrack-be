<?php

namespace Tests\Feature\Api\V1;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Business;
use App\Models\ComplianceRuleSet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GrossIncomeTaxTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Business $business;

    private AccountingPeriod $period;

    /** @var array<string, Account> */
    private array $accounts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-05 10:00:00');
        $this->user = User::factory()->create();
        $this->business = Business::factory()->create();
        $this->business->users()->attach($this->user, ['role' => 'accountant', 'status' => 'active']);
        $this->period = AccountingPeriod::factory()->for($this->business)->create(['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);

        foreach (['bank' => 'asset', 'goods' => 'revenue', 'repairs' => 'revenue', 'other' => 'revenue'] as $key => $type) {
            $this->accounts[$key] = Account::factory()->for($this->business)->ofType($type)->create(['code' => ['bank' => '1000', 'goods' => '5100', 'repairs' => '5200', 'other' => '5900'][$key], 'name' => ucfirst($key)]);
        }
    }

    public function test_calculates_the_quarter_from_income_grouped_by_activity(): void
    {
        $this->rules();
        $this->assign('goods', 'trade');
        $this->assign('repairs', 'services');
        $this->income('goods', '4000.00', '2026-07-10');
        $this->income('goods', '1000.00', '2026-09-30');
        $this->income('repairs', '500.00', '2026-08-15');
        $this->income('goods', '9999.00', '2026-06-30');
        $this->income('goods', '8888.00', '2026-10-01');

        $this->actingAs($this->user)->getJson("/api/v1/businesses/{$this->business->id}/tax/gross-income?year=2026&quarter=3")
            ->assertOk()
            ->assertJsonPath('data.from', '2026-07-01')
            ->assertJsonPath('data.to', '2026-09-30')
            ->assertJsonPath('data.activities.0.gross_income', '5000.00')
            ->assertJsonPath('data.activities.0.tax', '150.00')
            ->assertJsonPath('data.activities.1.gross_income', '500.00')
            ->assertJsonPath('data.activities.1.tax', '45.00')
            ->assertJsonPath('data.gross_income', '5500.00')
            ->assertJsonPath('data.calculated_tax', '195.00')
            ->assertJsonPath('data.tax_due', '195.00')
            ->assertJsonPath('data.minimum_applied', false)
            ->assertJsonPath('data.complete', true)
            ->assertJsonPath('data.year_to_date_gross_income', '15499.00')
            ->assertJsonPath('data.over_threshold', false)
            ->assertJsonPath('data.rule_set.version', '2026.1');
    }

    public function test_applies_the_minimum_and_flags_income_without_an_activity_and_the_limit(): void
    {
        $this->rules();
        $this->assign('goods', 'trade');
        $this->income('goods', '300.00', '2026-08-01');
        $this->income('other', '200.00', '2026-08-02');
        $this->income('goods', '31000.00', '2026-02-01');

        $this->actingAs($this->user)->getJson("/api/v1/businesses/{$this->business->id}/tax/gross-income?year=2026&quarter=3")
            ->assertOk()
            ->assertJsonPath('data.calculated_tax', '9.00')
            ->assertJsonPath('data.tax_due', '37.50')
            ->assertJsonPath('data.minimum_applied', true)
            ->assertJsonPath('data.unassigned_gross_income', '200.00')
            ->assertJsonPath('data.complete', false)
            ->assertJsonPath('data.gross_income', '500.00')
            ->assertJsonPath('data.over_threshold', true)
            ->assertJsonCount(2, 'data.accounts');
    }

    public function test_needs_approved_rules_for_the_quarter(): void
    {
        $this->rules('draft');

        $this->actingAs($this->user)->getJson("/api/v1/businesses/{$this->business->id}/tax/gross-income?year=2026&quarter=3")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quarter' => 'No approved small-business tax rules are effective for this quarter.']);
        $this->actingAs($this->user)->getJson("/api/v1/businesses/{$this->business->id}/tax/gross-income?year=2026&quarter=5")->assertUnprocessable();
    }

    public function test_only_bookkeepers_group_revenue_accounts_and_only_revenue_accounts(): void
    {
        $auditor = User::factory()->create();
        $this->business->users()->attach($auditor, ['role' => 'auditor', 'status' => 'active']);
        $foreign = Account::factory()->ofType('revenue')->create();

        $this->actingAs($auditor)->putJson("/api/v1/businesses/{$this->business->id}/accounts/{$this->accounts['goods']->id}/tax-activity", ['activity' => 'trade'])->assertForbidden();
        $this->actingAs($this->user)->putJson("/api/v1/businesses/{$this->business->id}/accounts/{$this->accounts['bank']->id}/tax-activity", ['activity' => 'trade'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('account');
        $this->actingAs($this->user)->putJson("/api/v1/businesses/{$this->business->id}/accounts/{$foreign->id}/tax-activity", ['activity' => 'trade'])->assertNotFound();
        $this->actingAs($this->user)->putJson("/api/v1/businesses/{$this->business->id}/accounts/{$this->accounts['goods']->id}/tax-activity", ['activity' => 'trade'])
            ->assertOk()
            ->assertJsonPath('data.tax_activity', 'trade');
        $this->actingAs($this->user)->putJson("/api/v1/businesses/{$this->business->id}/accounts/{$this->accounts['goods']->id}/tax-activity", ['activity' => null])
            ->assertOk()
            ->assertJsonPath('data.tax_activity', null);
        $this->assertDatabaseHas('audit_logs', ['business_id' => $this->business->id, 'action' => 'account.tax_activity_changed', 'subject_id' => $this->accounts['goods']->id]);
    }

    public function test_a_rule_set_with_bad_rates_cannot_be_approved(): void
    {
        $administrator = User::factory()->create();
        $administrator->forceFill(['is_system_admin' => true])->save();
        $ruleSet = $this->rules('draft', ['threshold_amount' => '30000', 'minimum_per_quarter' => '37.50', 'activities' => [['key' => 'trade', 'label' => 'Trade', 'rate_percent' => '300']]]);

        $this->actingAs($administrator)->postJson("/api/v1/compliance/rule-sets/{$ruleSet->id}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rules');
    }

    /** @param array<string, mixed>|null $configuration */
    private function rules(string $status = 'approved', ?array $configuration = null): ComplianceRuleSet
    {
        $ruleSet = ComplianceRuleSet::factory()->create(['jurisdiction' => 'XK', 'domain' => 'small_business_tax', 'version' => '2026.1', 'valid_from' => '2026-01-01', 'valid_to' => null, 'approval_status' => $status, 'approved_at' => $status === 'approved' ? now() : null]);
        $ruleSet->rules()->create(['rule_key' => 'gross_income_tax', 'configuration' => $configuration ?? [
            'threshold_amount' => '30000.00',
            'minimum_per_quarter' => '37.50',
            'activities' => [['key' => 'trade', 'label' => 'Trade', 'rate_percent' => '3'], ['key' => 'services', 'label' => 'Services', 'rate_percent' => '9']],
        ]]);

        return $ruleSet;
    }

    private function assign(string $account, string $activity): void
    {
        $this->accounts[$account]->update(['metadata' => ['tax_activity' => $activity]]);
    }

    private function income(string $account, string $amount, string $date): void
    {
        $entryId = $this->actingAs($this->user)->postJson("/api/v1/businesses/{$this->business->id}/journal-entries", [
            'accounting_period_id' => $this->period->id,
            'entry_date' => $date,
            'description' => "Sale {$date}",
            'lines' => [
                ['account_id' => $this->accounts['bank']->id, 'debit' => $amount, 'credit' => '0.00'],
                ['account_id' => $this->accounts[$account]->id, 'debit' => '0.00', 'credit' => $amount],
            ],
        ])->assertCreated()->json('data.id');
        $this->actingAs($this->user)->postJson("/api/v1/businesses/{$this->business->id}/journal-entries/{$entryId}/post")->assertOk();
    }
}
