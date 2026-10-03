<?php

namespace Tests\Feature\Api\V1;

use App\Models\Counterparty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBookkeepingFixtures;
use Tests\TestCase;

class InvoiceShowTest extends TestCase
{
    use CreatesBookkeepingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-06-20 10:00:00');
    }

    public function test_returns_the_invoice_with_lines_and_buyer_details_for_printing(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $customer = Counterparty::factory()->for($business)->customer()->create(['nui' => '811111111', 'address' => 'Rr. Nëna Terezë 1', 'municipality' => 'Prishtinë']);
        $invoiceId = $this->createDraft($user, $business, ['kind' => 'sale', 'counterparty_id' => $customer->id], 'S18');

        $this->actingAs($user)
            ->getJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}")
            ->assertOk()
            ->assertJsonPath('data.id', $invoiceId)
            ->assertJsonPath('data.gross_amount', '118.00')
            ->assertJsonPath('data.counterparty.nui', '811111111')
            ->assertJsonPath('data.counterparty.address', 'Rr. Nëna Terezë 1')
            ->assertJsonPath('data.lines.0.description', 'Consulting')
            ->assertJsonPath('data.lines.0.tax_snapshot.rate_percent', '18.0000');
    }

    public function test_stores_the_payment_method_and_rejects_unknown_ones(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $customer = Counterparty::factory()->for($business)->customer()->create();
        $invoiceId = $this->createDraft($user, $business, ['kind' => 'sale', 'counterparty_id' => $customer->id, 'payment_method' => 'bank_transfer'], 'S18');

        $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}")->assertJsonPath('data.payment_method', 'bank_transfer');
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices", [
            'kind' => 'sale',
            'counterparty_id' => $customer->id,
            'issued_on' => '2026-06-15',
            'payment_method' => 'barter',
            'lines' => [['description' => 'X', 'quantity' => '1', 'unit_price' => '1', 'vat_code' => 'S18']],
        ])->assertUnprocessable()->assertJsonValidationErrors('payment_method');
    }

    public function test_returns_404_for_an_invoice_of_another_business(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        [$otherUser, $otherBusiness] = $this->bookkeepingBusiness();
        $customer = Counterparty::factory()->for($otherBusiness)->customer()->create();
        $foreignInvoiceId = $this->createDraft($otherUser, $otherBusiness, ['kind' => 'sale', 'counterparty_id' => $customer->id], 'S18');

        $this->actingAs($user)
            ->getJson("/api/v1/businesses/{$business->id}/invoices/{$foreignInvoiceId}")
            ->assertNotFound();
    }

    public function test_returns_403_to_a_user_outside_the_business(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $customer = Counterparty::factory()->for($business)->customer()->create();
        $invoiceId = $this->createDraft($user, $business, ['kind' => 'sale', 'counterparty_id' => $customer->id], 'S18');

        $this->actingAs(User::factory()->create())
            ->getJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}")
            ->assertForbidden();
    }
}
