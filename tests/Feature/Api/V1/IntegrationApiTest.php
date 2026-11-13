<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\BusinessApiKey;
use App\Models\Counterparty;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Concerns\CreatesBookkeepingFixtures;
use Tests\TestCase;

class IntegrationApiTest extends TestCase
{
    use CreatesBookkeepingFixtures, RefreshDatabase;

    private User $owner;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-06-20 10:00:00');
        [$accountant, $this->business] = $this->bookkeepingBusiness();
        $this->owner = User::factory()->create();
        $this->business->users()->attach($this->owner, ['role' => 'owner', 'status' => 'active']);
        $this->business->users()->updateExistingPivot($accountant->id, ['role' => 'auditor']);
    }

    public function test_owner_creates_a_key_whose_token_is_shown_once_and_can_revoke_it(): void
    {
        $response = $this->actingAs($this->owner)->postJson("/api/v1/businesses/{$this->business->id}/api-keys", ['name' => 'Kasa e dyqanit', 'abilities' => ['read', 'sales.write']])
            ->assertCreated()
            ->assertJsonPath('data.abilities', ['read', 'sales.write']);
        $token = $response->json('data.token');
        $keyId = $response->json('data.id');

        $this->assertMatchesRegularExpression('/^kt_\d+_[A-Za-z0-9]{48}$/', $token);
        $this->assertStringNotContainsString(explode('_', $token)[2], (string) BusinessApiKey::query()->findOrFail($keyId)->token_hash);
        $listed = $this->actingAs($this->owner)->getJson("/api/v1/businesses/{$this->business->id}/api-keys")->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->assertArrayNotHasKey('token', $listed);
        $this->assertArrayNotHasKey('token_hash', $listed);
        $this->asKey($token)->getJson('/api/v1/integration/business')->assertOk()->assertJsonPath('data.nui', $this->business->nui)->assertJsonPath('data.api_key.name', 'Kasa e dyqanit');

        $this->actingAs($this->owner)->deleteJson("/api/v1/businesses/{$this->business->id}/api-keys/{$keyId}")->assertNoContent();
        $this->asKey($token)->getJson('/api/v1/integration/business')->assertUnauthorized();
        $this->assertDatabaseHas('audit_logs', ['business_id' => $this->business->id, 'action' => 'api_key.created', 'subject_id' => $keyId]);
        $this->assertDatabaseHas('audit_logs', ['business_id' => $this->business->id, 'action' => 'api_key.revoked', 'subject_id' => $keyId]);
    }

    public function test_only_the_owner_manages_keys(): void
    {
        $accountant = User::factory()->create();
        $this->business->users()->attach($accountant, ['role' => 'accountant', 'status' => 'active']);

        $this->actingAs($accountant)->postJson("/api/v1/businesses/{$this->business->id}/api-keys", ['name' => 'X', 'abilities' => ['read']])->assertForbidden();
        $this->actingAs($accountant)->getJson("/api/v1/businesses/{$this->business->id}/api-keys")->assertForbidden();
        $this->actingAs($this->owner)->postJson("/api/v1/businesses/{$this->business->id}/api-keys", ['name' => 'X', 'abilities' => ['everything']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('abilities.0');
    }

    public function test_rejects_wrong_keys_and_missing_abilities(): void
    {
        $token = $this->key(['read']);

        $this->getJson('/api/v1/integration/business')->assertUnauthorized();
        $this->asKey('kt_999_'.str_repeat('a', 48))->getJson('/api/v1/integration/business')->assertUnauthorized();
        $this->asKey(substr($token, 0, -1).'x')->getJson('/api/v1/integration/business')->assertUnauthorized();
        $this->asKey($token)->postJson('/api/v1/integration/sales', [])->assertForbidden();
        $this->asKey($token)->postJson('/api/v1/integration/customers', ['name' => 'X'])->assertForbidden();
    }

    public function test_a_key_stops_working_when_its_creator_loses_access(): void
    {
        $token = $this->key(['read']);
        $this->asKey($token)->getJson('/api/v1/integration/items')->assertOk();

        $this->owner->forceFill(['deactivated_at' => now()])->save();

        $this->asKey($token)->getJson('/api/v1/integration/items')->assertUnauthorized();
    }

    public function test_a_sale_is_recorded_once_as_a_draft_however_often_it_is_sent(): void
    {
        $customer = Counterparty::factory()->for($this->business)->customer()->create(['nui' => '811000222']);
        $token = $this->key(['read', 'sales.write']);
        $payload = [
            'external_id' => 'POS-2026-000123',
            'issued_on' => '2026-06-18',
            'payment_method' => 'cash',
            'customer' => ['nui' => '811000222'],
            'lines' => [['description' => 'Kafe 1 kg', 'quantity' => '2', 'unit_price' => '50.00', 'vat_code' => 'S18']],
        ];

        $invoiceId = $this->asKey($token)->postJson('/api/v1/integration/sales', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.external_id', 'POS-2026-000123')
            ->assertJsonPath('data.gross_amount', '118.00')
            ->assertJsonPath('data.counterparty.id', $customer->id)
            ->json('data.id');
        $this->asKey($token)->postJson('/api/v1/integration/sales', $payload)->assertOk()->assertJsonPath('data.id', $invoiceId);

        $this->assertSame(1, Invoice::query()->where('business_id', $this->business->id)->count());
        $this->asKey($token)->getJson('/api/v1/integration/sales/POS-2026-000123')->assertOk()->assertJsonPath('data.id', $invoiceId);
        $this->asKey($token)->getJson('/api/v1/integration/sales/unknown')->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['business_id' => $this->business->id, 'action' => 'integration.sale_received', 'subject_id' => $invoiceId, 'user_id' => $this->owner->id]);
    }

    public function test_a_sale_can_be_issued_and_take_stock_by_item_code(): void
    {
        $this->mapPostingAccounts($this->business);
        $item = InventoryItem::factory()->for($this->business)->create(['sku' => 'KAFE-1']);
        $warehouse = Warehouse::factory()->for($this->business)->create(['is_default' => true]);
        $this->business->stockMovements()->create(['inventory_item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'movement_type' => 'opening', 'quantity_delta' => '10.0000', 'occurred_on' => '2026-06-01', 'notes' => 'Opening']);
        $token = $this->key(['read', 'sales.write', 'customers.write']);

        $response = $this->asKey($token)->postJson('/api/v1/integration/sales', [
            'external_id' => 'WEB-77',
            'issued_on' => '2026-06-18',
            'issue' => true,
            'customer' => ['name' => 'Blerës i ri', 'nui' => '811000999'],
            'lines' => [['description' => 'Kafe 1 kg', 'quantity' => '3', 'unit_price' => '12.00', 'vat_code' => 'S18', 'sku' => 'KAFE-1']],
        ])->assertCreated()->assertJsonPath('data.status', 'issued');

        $this->assertNotNull($response->json('data.journal_entry_id'));
        $this->assertSame($item->id, $response->json('data.lines.0.inventory_item_id'));
        $this->assertDatabaseHas('counterparties', ['business_id' => $this->business->id, 'name' => 'Blerës i ri', 'nui' => '811000999', 'type' => 'customer']);
        $this->assertEqualsWithDelta(7.0, (float) $this->business->stockMovements()->where('inventory_item_id', $item->id)->sum('quantity_delta'), 0.00001);
        $this->asKey($token)->getJson('/api/v1/integration/items')->assertOk()->assertJsonPath('data.0.sku', 'KAFE-1')->assertJsonPath('data.0.on_hand_quantity', fn (string $quantity): bool => (float) $quantity === 7.0);
    }

    public function test_a_sale_is_refused_with_clear_reasons(): void
    {
        $token = $this->key(['read', 'sales.write']);
        $base = ['external_id' => 'POS-1', 'issued_on' => '2026-06-18', 'lines' => [['description' => 'Kafe', 'quantity' => '1', 'unit_price' => '10.00', 'vat_code' => 'S18']]];

        $this->asKey($token)->postJson('/api/v1/integration/sales', [...$base, 'customer' => ['name' => 'I panjohur']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer' => 'This customer is not recorded yet and this API key may not create customers.']);
        $this->asKey($token)->postJson('/api/v1/integration/sales', [...$base, 'customer' => ['id' => Counterparty::factory()->customer()->create()->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('customer.id');
        $customer = Counterparty::factory()->for($this->business)->customer()->create();
        $this->asKey($token)->postJson('/api/v1/integration/sales', [...$base, 'customer' => ['id' => $customer->id], 'lines' => [[...$base['lines'][0], 'sku' => 'NO-SUCH']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.0.sku');

        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_customers_can_be_listed_and_created(): void
    {
        Counterparty::factory()->for($this->business)->customer()->create(['name' => 'Drini', 'nui' => '811000222']);
        Counterparty::factory()->for($this->business)->supplier()->create(['name' => 'Furnitori']);
        Counterparty::factory()->customer()->create(['name' => 'Drini i huaj', 'nui' => '811000333']);
        $token = $this->key(['read', 'customers.write']);

        $this->asKey($token)->getJson('/api/v1/integration/customers')->assertOk()->assertJsonCount(1, 'data');
        $this->asKey($token)->getJson('/api/v1/integration/customers?nui=811000333')->assertOk()->assertJsonCount(0, 'data');
        $this->asKey($token)->postJson('/api/v1/integration/customers', ['name' => 'Ereniku', 'nui' => '12345'])->assertUnprocessable()->assertJsonValidationErrors('nui');
        $this->asKey($token)->postJson('/api/v1/integration/customers', ['name' => 'Ereniku', 'nui' => '811000444'])->assertCreated()->assertJsonPath('data.name', 'Ereniku');
    }

    /** @param list<string> $abilities */
    private function key(array $abilities): string
    {
        $token = $this->actingAs($this->owner)->postJson("/api/v1/businesses/{$this->business->id}/api-keys", ['name' => 'Test', 'abilities' => $abilities])->assertCreated()->json('data.token');
        Auth::forgetGuards();

        return $token;
    }

    private function asKey(string $token): self
    {
        Auth::forgetGuards();
        $this->app['auth']->forgetUser();

        return $this->withHeaders(['Authorization' => "Bearer {$token}"]);
    }
}
