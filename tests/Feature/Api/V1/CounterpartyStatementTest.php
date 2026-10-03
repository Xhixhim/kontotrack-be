<?php

namespace Tests\Feature\Api\V1;

use App\Models\Account;
use App\Models\Business;
use App\Models\CashAccount;
use App\Models\Counterparty;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBookkeepingFixtures;
use Tests\TestCase;

class CounterpartyStatementTest extends TestCase
{
    use CreatesBookkeepingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-07-20 10:00:00');
    }

    public function test_statement_shows_opening_balance_running_lines_and_closing_balance(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $this->mapPostingAccounts($business);
        $customer = Counterparty::factory()->for($business)->customer()->create();
        $mayInvoice = $this->issuedSale($user, $business, $customer, '2026-05-10', '2026-05-20');
        $juneInvoice = $this->issuedSale($user, $business, $customer, '2026-06-15', '2026-06-30');
        $this->receive($user, $business, $mayInvoice, '2026-06-20', '50.00');
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$juneInvoice}/cancel", [
            'cancelled_on' => '2026-07-01',
            'cancellation_reason' => 'Wrong amount',
        ])->assertOk();

        $response = $this->actingAs($user)
            ->getJson("/api/v1/businesses/{$business->id}/counterparties/{$customer->id}/statement?from=2026-06-01&to=2026-07-20")
            ->assertOk();

        $response->assertJsonCount(1, 'data.sections')
            ->assertJsonPath('data.counterparty.id', $customer->id)
            ->assertJsonPath('data.sections.0.kind', 'sale')
            ->assertJsonPath('data.sections.0.opening_balance', '118.00')
            ->assertJsonPath('data.sections.0.closing_balance', '68.00');
        $this->assertSame(
            [['invoice', '118.00', '0.00', '236.00'], ['payment', '0.00', '50.00', '186.00'], ['cancellation', '0.00', '118.00', '68.00']],
            collect($response->json('data.sections.0.lines'))->map(fn (array $line): array => [$line['type'], $line['increase'], $line['decrease'], $line['balance']])->all(),
        );
    }

    public function test_card_lists_open_invoices_with_outstanding_and_overdue_amounts(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $this->mapPostingAccounts($business);
        $customer = Counterparty::factory()->for($business)->customer()->create();
        $overdueInvoice = $this->issuedSale($user, $business, $customer, '2026-06-15', '2026-07-10');
        $this->issuedSale($user, $business, $customer, '2026-07-15', '2026-08-15');
        $this->receive($user, $business, $overdueInvoice, '2026-07-12', '18.00');

        $section = $this->actingAs($user)
            ->getJson("/api/v1/businesses/{$business->id}/counterparties/{$customer->id}/statement")
            ->assertOk()
            ->assertJsonPath('data.from', '2026-01-01')
            ->assertJsonPath('data.to', '2026-07-20')
            ->json('data.sections.0');

        $this->assertSame('218.00', $section['outstanding_amount']);
        $this->assertSame('100.00', $section['overdue_amount']);
        $this->assertSame(10, $section['oldest_days_overdue']);
        $this->assertSame(['100.00', '118.00'], array_column($section['open_invoices'], 'outstanding_amount'));
    }

    public function test_contact_trading_both_ways_gets_separate_receivable_and_payable_sections(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $this->mapPostingAccounts($business);
        $partner = Counterparty::factory()->for($business)->create(['type' => 'both']);
        $this->issuedSale($user, $business, $partner, '2026-06-15', null);
        $billId = $this->createDraft($user, $business, [
            'kind' => 'purchase',
            'counterparty_id' => $partner->id,
            'invoice_number' => 'P-1',
            'document_id' => Document::factory()->for($business)->invoice()->create()->id,
        ], 'EX');
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$billId}/issue")->assertOk();

        $this->actingAs($user)
            ->getJson("/api/v1/businesses/{$business->id}/counterparties/{$partner->id}/statement")
            ->assertOk()
            ->assertJsonPath('data.sections.0.kind', 'sale')
            ->assertJsonPath('data.sections.0.closing_balance', '118.00')
            ->assertJsonPath('data.sections.1.kind', 'purchase')
            ->assertJsonPath('data.sections.1.closing_balance', '100.00');
    }

    public function test_drafts_do_not_appear_on_the_statement(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $customer = Counterparty::factory()->for($business)->customer()->create();
        $this->createDraft($user, $business, ['kind' => 'sale', 'counterparty_id' => $customer->id], 'S18');

        $this->actingAs($user)
            ->getJson("/api/v1/businesses/{$business->id}/counterparties/{$customer->id}/statement")
            ->assertOk()
            ->assertJsonCount(0, 'data.sections.0.lines')
            ->assertJsonPath('data.sections.0.closing_balance', '0.00');
    }

    public function test_returns_404_for_a_contact_of_another_business(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $foreignContact = Counterparty::factory()->customer()->create();

        $this->actingAs($user)
            ->getJson("/api/v1/businesses/{$business->id}/counterparties/{$foreignContact->id}/statement")
            ->assertNotFound();
    }

    public function test_rejects_an_end_date_before_the_start_date_with_422(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $customer = Counterparty::factory()->for($business)->customer()->create();

        $this->actingAs($user)
            ->getJson("/api/v1/businesses/{$business->id}/counterparties/{$customer->id}/statement?from=2026-07-01&to=2026-06-01")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');
    }

    public function test_returns_403_to_a_user_outside_the_business(): void
    {
        [, $business] = $this->bookkeepingBusiness();
        $customer = Counterparty::factory()->for($business)->customer()->create();

        $this->actingAs(User::factory()->create())
            ->getJson("/api/v1/businesses/{$business->id}/counterparties/{$customer->id}/statement")
            ->assertForbidden();
    }

    private function issuedSale(User $user, Business $business, Counterparty $customer, string $issuedOn, ?string $dueOn): int
    {
        $invoiceId = $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices", [
            'kind' => 'sale',
            'counterparty_id' => $customer->id,
            'issued_on' => $issuedOn,
            'due_on' => $dueOn,
            'lines' => [['description' => 'Service', 'quantity' => '1', 'unit_price' => '100.00', 'vat_code' => 'S18']],
        ])->assertCreated()->json('data.id');
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/issue")->assertOk();

        return $invoiceId;
    }

    private function receive(User $user, Business $business, int $invoiceId, string $paidOn, string $amount): void
    {
        $cashAccount = CashAccount::factory()->for($business)->create([
            'account_id' => Account::factory()->for($business)->ofType('asset')->create()->id,
        ]);
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/payments", [
            'cash_account_id' => $cashAccount->id,
            'document_id' => Document::factory()->for($business)->create()->id,
            'paid_on' => $paidOn,
            'direction' => 'in',
            'amount' => $amount,
            'allocations' => [['invoice_id' => $invoiceId, 'amount' => $amount]],
        ])->assertCreated();
    }
}
