<?php

namespace Tests\Feature\Api\V1;

use App\Models\AuditLog;
use App\Models\Counterparty;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBookkeepingFixtures;
use Tests\TestCase;

class CounterpartyUpdateTest extends TestCase
{
    use CreatesBookkeepingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-06-20 10:00:00');
    }

    public function test_accountant_updates_a_contact_and_the_change_is_audited(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $customer = Counterparty::factory()->for($business)->customer()->create(['name' => 'Old Shop', 'nui' => null]);

        $this->actingAs($user)
            ->putJson("/api/v1/businesses/{$business->id}/counterparties/{$customer->id}", ['name' => 'New Shop', 'nui' => '811111111', 'status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Shop')
            ->assertJsonPath('data.nui', '811111111')
            ->assertJsonPath('data.status', 'inactive');

        $auditLog = AuditLog::query()->where('action', 'counterparty.updated')->sole();
        $this->assertSame($customer->id, $auditLog->subject_id);
        $this->assertEquals(['name' => 'Old Shop', 'nui' => null, 'status' => 'active'], $auditLog->old_values);
        $this->assertEquals(['name' => 'New Shop', 'nui' => '811111111', 'status' => 'inactive'], $auditLog->new_values);
    }

    public function test_rejects_making_a_customer_with_sales_invoices_supplier_only_with_422(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $customer = Counterparty::factory()->for($business)->customer()->create();
        $this->createDraft($user, $business, ['kind' => 'sale', 'counterparty_id' => $customer->id], 'S18');

        $this->actingAs($user)
            ->putJson("/api/v1/businesses/{$business->id}/counterparties/{$customer->id}", ['type' => 'supplier'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type' => 'This contact has sales invoices, so it must stay a customer or both.']);

        $this->assertSame('customer', $customer->refresh()->type);
    }

    public function test_rejects_making_a_supplier_with_purchase_invoices_customer_only_with_422(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $supplier = Counterparty::factory()->for($business)->supplier()->create();
        $this->createDraft($user, $business, ['kind' => 'purchase', 'counterparty_id' => $supplier->id, 'invoice_number' => 'B-1'], 'S18');

        $this->actingAs($user)
            ->putJson("/api/v1/businesses/{$business->id}/counterparties/{$supplier->id}", ['type' => 'customer'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type' => 'This contact has purchase invoices, so it must stay a supplier or both.']);
    }

    public function test_rejects_making_an_inventory_supplier_customer_only_with_422(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $supplier = Counterparty::factory()->for($business)->supplier()->create();
        InventoryItem::factory()->for($business)->create(['supplier_counterparty_id' => $supplier->id]);

        $this->actingAs($user)
            ->putJson("/api/v1/businesses/{$business->id}/counterparties/{$supplier->id}", ['type' => 'customer'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type' => 'This contact is the supplier of inventory items, so it must stay a supplier or both.']);
    }

    public function test_allows_widening_a_customer_with_invoices_to_both(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $customer = Counterparty::factory()->for($business)->customer()->create();
        $this->createDraft($user, $business, ['kind' => 'sale', 'counterparty_id' => $customer->id], 'S18');

        $this->actingAs($user)
            ->putJson("/api/v1/businesses/{$business->id}/counterparties/{$customer->id}", ['type' => 'both'])
            ->assertOk()
            ->assertJsonPath('data.type', 'both');
    }

    public function test_returns_404_for_a_contact_of_another_business(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $foreignContact = Counterparty::factory()->customer()->create(['name' => 'Foreign']);

        $this->actingAs($user)
            ->putJson("/api/v1/businesses/{$business->id}/counterparties/{$foreignContact->id}", ['name' => 'Hijacked'])
            ->assertNotFound();

        $this->assertSame('Foreign', $foreignContact->refresh()->name);
    }

    public function test_rejects_an_unknown_status_with_422(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $customer = Counterparty::factory()->for($business)->customer()->create();

        $this->actingAs($user)
            ->putJson("/api/v1/businesses/{$business->id}/counterparties/{$customer->id}", ['status' => 'deleted'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_auditor_cannot_update_a_contact(): void
    {
        [, $business] = $this->bookkeepingBusiness();
        $customer = Counterparty::factory()->for($business)->customer()->create(['name' => 'Kept']);
        $auditor = User::factory()->create();
        $business->users()->attach($auditor, ['role' => 'auditor', 'status' => 'active']);

        $this->actingAs($auditor)
            ->putJson("/api/v1/businesses/{$business->id}/counterparties/{$customer->id}", ['name' => 'Changed'])
            ->assertForbidden();

        $this->assertSame('Kept', $customer->refresh()->name);
    }

    public function test_rejects_identifiers_that_are_not_a_kosovo_business_or_personal_number_with_422(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $base = "/api/v1/businesses/{$business->id}/counterparties";

        foreach (['81100', '81100022A', '12345678901'] as $identifier) {
            $this->actingAs($user)->postJson($base, ['type' => 'customer', 'name' => 'Klient', 'nui' => $identifier])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('nui');
        }

        $this->actingAs($user)->postJson($base, ['type' => 'customer', 'name' => 'Biznes', 'nui' => '811000222'])->assertCreated();
        $this->actingAs($user)->postJson($base, ['type' => 'customer', 'name' => 'Individ', 'nui' => '1234567890'])->assertCreated();
        $this->actingAs($user)->postJson($base, ['type' => 'customer', 'name' => 'Pa numër'])->assertCreated();
        $this->actingAs($user)->postJson($base, ['type' => 'supplier', 'name' => 'Lieferant GmbH', 'nui' => 'DE123456789', 'country_code' => 'DE'])->assertCreated();
    }

    public function test_an_update_judges_the_identifier_by_the_stored_country(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $foreign = Counterparty::factory()->for($business)->supplier()->create(['nui' => null, 'country_code' => 'DE']);
        $local = Counterparty::factory()->for($business)->customer()->create(['nui' => null]);

        $this->actingAs($user)->putJson("/api/v1/businesses/{$business->id}/counterparties/{$foreign->id}", ['nui' => 'DE123456789'])->assertOk();
        $this->actingAs($user)->putJson("/api/v1/businesses/{$business->id}/counterparties/{$local->id}", ['nui' => 'DE123456789'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('nui');
        $this->actingAs($user)->putJson("/api/v1/businesses/{$business->id}/counterparties/{$local->id}", ['nui' => 'DE123456789', 'country_code' => 'DE'])->assertOk();
    }
}
