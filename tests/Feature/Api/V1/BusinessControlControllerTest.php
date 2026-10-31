<?php

namespace Tests\Feature\Api\V1;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Business;
use App\Models\CashAccount;
use App\Models\ComplianceRuleSet;
use App\Models\Counterparty;
use App\Models\Document;
use App\Models\Employee;
use App\Models\InventoryItem;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\PayrollRun;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesBookkeepingFixtures;
use Tests\TestCase;

class BusinessControlControllerTest extends TestCase
{
    use CreatesBookkeepingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-07-20 10:00:00');
    }

    public function test_returns_401_without_authentication(): void
    {
        $business = Business::factory()->create();

        $this->getJson("/api/v1/businesses/{$business->id}/controls")->assertUnauthorized();
    }

    public function test_returns_403_to_a_user_outside_the_business(): void
    {
        $business = Business::factory()->create();

        $this->actingAs(User::factory()->create())->getJson("/api/v1/businesses/{$business->id}/controls")->assertForbidden();
    }

    public function test_new_business_warns_about_vat_status_and_posting_accounts_and_passes_the_rest(): void
    {
        $user = User::factory()->create();
        $business = Business::factory()->create();
        $business->users()->attach($user, ['role' => 'auditor', 'status' => 'active']);

        $response = $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/controls")->assertOk();

        $this->assertSame('warning', $this->severity($response, 'vat_status'));
        $this->assertSame('warning', $this->severity($response, 'posting_accounts'));
        $this->assertSame('info', $this->severity($response, 'vat_threshold'));
        $this->assertSame('info', $this->severity($response, 'bank_differences'));
        $response->assertJsonPath('data.summary', ['critical' => 0, 'warning' => 2, 'ok' => 14, 'info' => 2])
            ->assertJsonPath('data.as_of', '2026-07-20');
    }

    public function test_negative_cash_balance_is_critical(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $cashLedger = Account::factory()->for($business)->ofType('asset')->create();
        $cashAccount = CashAccount::factory()->for($business)->create(['name' => 'Main till', 'account_id' => $cashLedger->id]);
        $expense = Account::factory()->for($business)->ofType('expense')->create();
        $entry = JournalEntry::query()->create([
            'business_id' => $business->id,
            'accounting_period_id' => $business->accountingPeriods()->value('id'),
            'entry_date' => '2026-07-01',
            'description' => 'Cash paid out',
            'status' => 'posted',
            'document_id' => Document::factory()->for($business)->create()->id,
        ]);
        $entry->lines()->createMany([
            ['business_id' => $business->id, 'account_id' => $expense->id, 'debit' => '40.00', 'credit' => '0.00'],
            ['business_id' => $business->id, 'account_id' => $cashLedger->id, 'debit' => '0.00', 'credit' => '40.00'],
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/controls")->assertOk();

        $this->assertSame('critical', $this->severity($response, 'negative_cash'));
        $this->assertSame([['id' => $cashAccount->id, 'name' => 'Main till', 'balance' => '-40.00']], $this->items($response, 'negative_cash'));
    }

    public function test_flags_supplier_bills_with_the_same_supplier_date_and_total(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $this->mapPostingAccounts($business);
        $supplier = Counterparty::factory()->for($business)->supplier()->create(['name' => 'Furnitori A']);

        foreach (['F-100', 'F-100B'] as $number) {
            $invoiceId = $this->createDraft($user, $business, [
                'kind' => 'purchase',
                'counterparty_id' => $supplier->id,
                'invoice_number' => $number,
                'document_id' => Document::factory()->for($business)->invoice()->create()->id,
            ], 'S18');
            $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/issue")->assertOk();
        }

        $response = $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/controls")->assertOk();

        $this->assertSame('warning', $this->severity($response, 'duplicate_purchases'));
        $group = $this->items($response, 'duplicate_purchases')[0];
        $this->assertSame(['F-100', 'F-100B'], $group['invoice_numbers']);
        $this->assertSame('Furnitori A', $group['counterparty_name']);
        $this->assertSame('2026-06-15', $group['issued_on']);
    }

    public function test_flags_contacts_without_a_nui_and_contacts_sharing_a_nui(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $missing = Counterparty::factory()->for($business)->customer()->create(['name' => 'No NUI Shop', 'nui' => null]);
        Counterparty::factory()->for($business)->customer()->count(2)->create(['nui' => '811000222']);
        Counterparty::factory()->for($business)->customer()->create(['nui' => null, 'status' => 'inactive']);

        $response = $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/controls")->assertOk();

        $this->assertSame([['id' => $missing->id, 'name' => 'No NUI Shop']], $this->items($response, 'counterparty_nui'));
        $this->assertSame([['nui' => '811000222', 'contact_count' => 2]], $this->items($response, 'duplicate_nui'));
    }

    public function test_flags_overdue_customer_invoices_with_the_outstanding_amount(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $this->mapPostingAccounts($business);
        $customer = Counterparty::factory()->for($business)->customer()->create(['name' => 'Late Payer']);
        $invoiceId = $this->createDraft($user, $business, ['kind' => 'sale', 'counterparty_id' => $customer->id, 'due_on' => '2026-07-01'], 'S18');
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/issue")->assertOk();

        $response = $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/controls")->assertOk();

        $check = collect($response->json('data.checks'))->firstWhere('key', 'overdue_receivables');
        $this->assertSame('warning', $check['severity']);
        $this->assertSame('1 customer invoice(s) are past due, with 118.00 outstanding.', $check['detail']);
        $this->assertSame('Late Payer', $check['items'][0]['counterparty_name']);
        $this->assertSame('ok', $this->severity($response, 'overdue_payables'));
    }

    public function test_flags_drafts_older_than_fourteen_days(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $customer = Counterparty::factory()->for($business)->customer()->create();
        $this->createDraft($user, $business, ['kind' => 'sale', 'counterparty_id' => $customer->id], 'S18');

        $response = $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/controls")->assertOk();

        $this->assertSame('warning', $this->severity($response, 'stale_drafts'));
        $this->assertSame('2026-06-15', $this->items($response, 'stale_drafts')[0]['issued_on']);
    }

    public function test_flags_items_below_their_minimum_stock(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $item = InventoryItem::factory()->for($business)->create(['sku' => 'SKU-1', 'minimum_quantity' => '5.0000']);
        InventoryItem::factory()->for($business)->create(['minimum_quantity' => '0.0000']);
        StockMovement::factory()->for($business)->create([
            'inventory_item_id' => $item->id,
            'warehouse_id' => Warehouse::factory()->for($business)->create()->id,
            'movement_type' => 'opening',
            'quantity_delta' => '2.0000',
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/controls")->assertOk();

        $this->assertSame('warning', $this->severity($response, 'low_stock'));
        $this->assertCount(1, $this->items($response, 'low_stock'));
        $this->assertSame('SKU-1', $this->items($response, 'low_stock')[0]['sku']);
    }

    public function test_vat_threshold_warns_near_the_configured_limit_and_is_critical_above_it(): void
    {
        [$user, $business] = $this->nonVatBusinessWithThreshold('250.00');
        $customer = Counterparty::factory()->for($business)->customer()->create();
        $this->issueNonVatSale($user, $business, $customer);
        $this->issueNonVatSale($user, $business, $customer);

        $response = $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/controls")->assertOk();
        $this->assertSame('warning', $this->severity($response, 'vat_threshold'));
        $this->assertSame([['sales_net' => '200.00', 'threshold' => '250.00', 'used_percent' => 80]], $this->items($response, 'vat_threshold'));

        $this->issueNonVatSale($user, $business, $customer);

        $response = $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/controls")->assertOk();
        $this->assertSame('critical', $this->severity($response, 'vat_threshold'));
    }

    public function test_warns_when_last_months_payroll_is_missing_and_passes_once_approved(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        Employee::factory()->for($business)->create(['employment_start' => '2026-01-01']);

        $response = $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/controls")->assertOk();
        $check = collect($response->json('data.checks'))->firstWhere('key', 'payroll');
        $this->assertSame('warning', $check['severity']);
        $this->assertSame('No payroll has been calculated for 2026-06 (1 employee(s)).', $check['detail']);
        $this->assertSame('warning', $this->severity($response, 'posting_accounts'));

        PayrollRun::query()->create(['business_id' => $business->id, 'period_year' => 2026, 'period_month' => 6, 'pay_date' => '2026-07-05', 'status' => 'approved']);

        $response = $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/controls")->assertOk();
        $this->assertSame('ok', $this->severity($response, 'payroll'));
    }

    public function test_flags_a_recent_payment_far_above_the_usual_amount(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $cash = CashAccount::factory()->for($business)->create(['account_id' => Account::factory()->for($business)->ofType('asset')->create()->id]);

        foreach (range(1, 10) as $index) {
            Payment::query()->create(['business_id' => $business->id, 'cash_account_id' => $cash->id, 'paid_on' => "2026-0{$this->month($index)}-10", 'direction' => 'out', 'amount' => '100.00', 'currency' => 'EUR']);
        }

        $large = Payment::query()->create(['business_id' => $business->id, 'cash_account_id' => $cash->id, 'paid_on' => '2026-07-15', 'direction' => 'out', 'reference' => 'BIG-1', 'amount' => '900.00', 'currency' => 'EUR']);
        Payment::query()->create(['business_id' => $business->id, 'cash_account_id' => $cash->id, 'paid_on' => '2026-07-16', 'direction' => 'out', 'amount' => '400.00', 'currency' => 'EUR']);

        $response = $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/controls")->assertOk();

        $this->assertSame('warning', $this->severity($response, 'unusual_transactions'));
        $this->assertSame([['type' => 'payment', 'id' => $large->id, 'reference' => 'BIG-1', 'date' => '2026-07-15', 'amount' => '900.00', 'typical_amount' => '100.00']], $this->items($response, 'unusual_transactions'));
    }

    private function month(int $index): int
    {
        return (($index - 1) % 6) + 1;
    }

    public function test_approving_a_vat_rule_set_with_an_invalid_threshold_returns_422(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_system_admin' => true])->save();
        $ruleSetId = $this->actingAs($admin)->postJson('/api/v1/compliance/rule-sets', [
            'jurisdiction' => 'XK',
            'domain' => 'vat',
            'version' => 'test-1',
            'valid_from' => '2026-01-01',
            'source_title' => 'Test fixture',
            'source_url' => 'https://example.test/vat',
            'rules' => [
                ['rule_key' => 'vat_codes', 'configuration' => ['codes' => [['code' => 'NV', 'label' => 'Non-VAT', 'rate_percent' => '0', 'treatment' => 'non_vat']]]],
                ['rule_key' => 'registration_threshold', 'configuration' => ['amount' => '-5']],
            ],
        ])->assertCreated()->json('data.id');

        $this->actingAs($admin)
            ->postJson("/api/v1/compliance/rule-sets/{$ruleSetId}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rules' => 'A registration_threshold rule needs a positive amount and an optional warning_percent between 1 and 100.']);
    }

    /** @return array{User, Business} */
    private function nonVatBusinessWithThreshold(string $amount): array
    {
        $user = User::factory()->create();
        $business = Business::factory()->create(['vat_status' => 'not_registered']);
        $business->users()->attach($user, ['role' => 'accountant', 'status' => 'active']);
        AccountingPeriod::factory()->for($business)->create();
        $ruleSet = ComplianceRuleSet::factory()->approvedVat()->create();
        $ruleSet->rules()->createMany([
            ['rule_key' => 'vat_codes', 'configuration' => ['codes' => [['code' => 'NV', 'label' => 'Non-VAT (test fixture)', 'rate_percent' => '0', 'treatment' => 'non_vat']]]],
            ['rule_key' => 'registration_threshold', 'configuration' => ['amount' => $amount, 'warning_percent' => 80]],
        ]);
        $this->mapPostingAccounts($business, ['accounts_receivable', 'sales_revenue']);

        return [$user, $business];
    }

    private function issueNonVatSale(User $user, Business $business, Counterparty $customer): void
    {
        $invoiceId = $this->createDraft($user, $business, ['kind' => 'sale', 'counterparty_id' => $customer->id], 'NV');
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/issue")->assertOk();
    }

    private function severity(TestResponse $response, string $key): string
    {
        return collect($response->json('data.checks'))->firstWhere('key', $key)['severity'];
    }

    /** @return list<array<string, mixed>> */
    private function items(TestResponse $response, string $key): array
    {
        return collect($response->json('data.checks'))->firstWhere('key', $key)['items'];
    }
}
