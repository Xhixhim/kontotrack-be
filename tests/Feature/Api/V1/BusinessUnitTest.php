<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\BusinessUnit;
use App\Models\Counterparty;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBookkeepingFixtures;
use Tests\TestCase;

class BusinessUnitTest extends TestCase
{
    use CreatesBookkeepingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-06-20 10:00:00');
    }

    public function test_bookkeeper_creates_renames_and_retires_units(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();

        $unitId = $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/units", ['name' => 'Dyqani Prishtinë', 'code' => 'PR'])
            ->assertCreated()
            ->assertJsonPath('data.is_active', true)
            ->json('data.id');
        $this->actingAs($user)->putJson("/api/v1/businesses/{$business->id}/units/{$unitId}", ['name' => 'Dyqani Prishtinë 1', 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.name', 'Dyqani Prishtinë 1')
            ->assertJsonPath('data.is_active', false);

        $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/units")->assertOk()->assertJsonCount(1, 'data');
        $this->assertDatabaseHas('audit_logs', ['business_id' => $business->id, 'action' => 'business_unit.created', 'subject_id' => $unitId]);
        $this->assertDatabaseHas('audit_logs', ['business_id' => $business->id, 'action' => 'business_unit.updated', 'subject_id' => $unitId]);
    }

    public function test_rejects_a_duplicate_unit_name_with_422(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        BusinessUnit::factory()->for($business)->create(['name' => 'Depo']);
        BusinessUnit::factory()->create(['name' => 'Zyra']);

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/units", ['name' => 'depo'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/units", ['name' => 'Zyra'])->assertCreated();
    }

    public function test_only_bookkeepers_manage_units_and_only_their_own(): void
    {
        [, $business] = $this->bookkeepingBusiness();
        $auditor = User::factory()->create();
        $business->users()->attach($auditor, ['role' => 'auditor', 'status' => 'active']);
        $foreignUnit = BusinessUnit::factory()->create();
        [$user] = [User::factory()->create()];
        $business->users()->attach($user, ['role' => 'accountant', 'status' => 'active']);

        $this->actingAs($auditor)->postJson("/api/v1/businesses/{$business->id}/units", ['name' => 'X'])->assertForbidden();
        $this->actingAs($auditor)->getJson("/api/v1/businesses/{$business->id}/units")->assertOk();
        $this->actingAs($user)->putJson("/api/v1/businesses/{$business->id}/units/{$foreignUnit->id}", ['name' => 'Taken over'])->assertNotFound();
    }

    public function test_an_invoice_carries_its_unit_into_the_ledger_and_its_credit_note(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $this->mapPostingAccounts($business);
        $unit = BusinessUnit::factory()->for($business)->create(['name' => 'Dyqani']);
        $customer = Counterparty::factory()->for($business)->customer()->create();

        $invoiceId = $this->createDraft($user, $business, ['kind' => 'sale', 'counterparty_id' => $customer->id, 'business_unit_id' => $unit->id], 'S18');
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/issue")->assertOk()->assertJsonPath('data.business_unit_id', $unit->id);

        $invoice = Invoice::query()->findOrFail($invoiceId);
        $this->assertSame($unit->id, JournalEntry::query()->findOrFail($invoice->journal_entry_id)->business_unit_id);

        $unit->update(['is_active' => false]);
        $creditNoteId = $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/credit-notes", [
            'issued_on' => '2026-06-18',
            'notes' => 'Kthim',
            'lines' => [['description' => 'Consulting', 'quantity' => '1', 'unit_price' => '50.00', 'vat_code' => 'S18']],
        ])->assertCreated()->json('data.id');
        $this->assertSame($unit->id, Invoice::query()->findOrFail($creditNoteId)->business_unit_id);
    }

    public function test_rejects_a_foreign_or_retired_unit_on_a_new_invoice_with_422(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $customer = Counterparty::factory()->for($business)->customer()->create();
        $retired = BusinessUnit::factory()->for($business)->create(['is_active' => false]);
        $foreign = BusinessUnit::factory()->create();

        foreach ([$retired, $foreign] as $unit) {
            $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices", [
                'kind' => 'sale', 'counterparty_id' => $customer->id, 'business_unit_id' => $unit->id, 'issued_on' => '2026-06-15',
                'lines' => [['description' => 'Consulting', 'quantity' => '2', 'unit_price' => '50.00', 'vat_code' => 'S18']],
            ])->assertUnprocessable()->assertJsonValidationErrors('business_unit_id');
        }
    }

    public function test_profit_and_loss_turnover_and_dashboard_can_be_limited_to_one_unit(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $this->mapPostingAccounts($business);
        $shop = BusinessUnit::factory()->for($business)->create(['name' => 'Dyqani']);
        $office = BusinessUnit::factory()->for($business)->create(['name' => 'Zyra']);
        $customer = Counterparty::factory()->for($business)->customer()->create();

        foreach ([$shop->id, $shop->id, $office->id, null] as $unitId) {
            $invoiceId = $this->createDraft($user, $business, ['kind' => 'sale', 'counterparty_id' => $customer->id, 'business_unit_id' => $unitId], 'S18');
            $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/issue")->assertOk();
        }

        $base = "/api/v1/businesses/{$business->id}";
        $this->actingAs($user)->getJson("{$base}/reports/financial?type=profit_loss&from=2026-06-01&to=2026-06-30")->assertOk()->assertJsonPath('data.totals.result', '400.00');
        $this->actingAs($user)->getJson("{$base}/reports/financial?type=profit_loss&from=2026-06-01&to=2026-06-30&business_unit_id={$shop->id}")
            ->assertOk()
            ->assertJsonPath('data.totals.result', '200.00')
            ->assertJsonPath('data.business_unit.name', 'Dyqani');
        $this->actingAs($user)->getJson("{$base}/reports/financial?type=turnover&from=2026-06-01&to=2026-06-30&business_unit_id={$office->id}")
            ->assertOk()
            ->assertJsonPath('data.totals.sales_gross', '118.00');
        $this->actingAs($user)->getJson("{$base}/dashboard?from=2026-06-01&to=2026-06-30&business_unit_id={$shop->id}")
            ->assertOk()
            ->assertJsonPath('data.business_unit_id', $shop->id)
            ->assertJsonPath('data.revenue', '200.00');
        $this->actingAs($user)->getJson("{$base}/dashboard?from=2026-06-01&to=2026-06-30")->assertOk()->assertJsonPath('data.revenue', '400.00');
    }

    public function test_reports_reject_a_unit_of_another_business_with_422(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $foreign = BusinessUnit::factory()->for(Business::factory())->create();

        $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/reports/financial?type=profit_loss&business_unit_id={$foreign->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('business_unit_id');
        $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/dashboard?business_unit_id={$foreign->id}")->assertUnprocessable();
    }
}
