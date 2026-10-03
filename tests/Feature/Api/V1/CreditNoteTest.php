<?php

namespace Tests\Feature\Api\V1;

use App\Models\Account;
use App\Models\Business;
use App\Models\CashAccount;
use App\Models\Counterparty;
use App\Models\Document;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesBookkeepingFixtures;
use Tests\TestCase;

class CreditNoteTest extends TestCase
{
    use CreatesBookkeepingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-07-20 10:00:00');
    }

    public function test_partial_credit_posts_the_reverse_entry_and_reduces_what_is_owed(): void
    {
        [$user, $business, $accounts, $customer, $invoiceId] = $this->issuedSale();
        $original = Invoice::query()->findOrFail($invoiceId);

        $creditNote = $this->creditNote($user, $business, $invoiceId, '50.00')->assertCreated()
            ->assertJsonPath('data.document_type', 'credit_note')
            ->assertJsonPath('data.invoice_number', "CN-{$original->invoice_number}-1")
            ->assertJsonPath('data.gross_amount', '59.00');
        $this->issue($user, $business, $creditNote->json('data.id'))->assertOk();

        $entry = JournalEntry::query()->with('lines')->findOrFail(Invoice::query()->findOrFail($creditNote->json('data.id'))->journal_entry_id);
        $this->assertSame([
            [$accounts['accounts_receivable']->id, $customer->id, '0.00', '59.00'],
            [$accounts['sales_revenue']->id, null, '50.00', '0.00'],
            [$accounts['vat_output']->id, null, '9.00', '0.00'],
        ], $this->lineSummary($entry));
        $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}")
            ->assertJsonPath('data.status', 'partially_paid')
            ->assertJsonPath('data.credited_total', '59.00')
            ->assertJsonPath('data.outstanding_amount', '59.00');
    }

    public function test_rejects_crediting_more_than_is_left_with_422(): void
    {
        [$user, $business, , , $invoiceId] = $this->issuedSale();
        $first = $this->creditNote($user, $business, $invoiceId, '80.00')->json('data.id');
        $this->issue($user, $business, $first)->assertOk();

        $this->creditNote($user, $business, $invoiceId, '30.00')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines' => 'The credit note cannot exceed what is left to credit on the invoice (23.60).']);
    }

    public function test_payment_after_a_credit_is_limited_to_the_remaining_balance(): void
    {
        [$user, $business, , , $invoiceId] = $this->issuedSale();
        $this->issue($user, $business, $this->creditNote($user, $business, $invoiceId, '50.00')->json('data.id'))->assertOk();
        $cash = CashAccount::factory()->for($business)->create(['account_id' => Account::factory()->for($business)->ofType('asset')->create()->id]);
        $payment = fn (string $amount) => $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/payments", [
            'cash_account_id' => $cash->id,
            'document_id' => Document::factory()->for($business)->create()->id,
            'paid_on' => '2026-07-01',
            'direction' => 'in',
            'amount' => $amount,
            'allocations' => [['invoice_id' => $invoiceId, 'amount' => $amount]],
        ]);

        $payment('118.00')->assertUnprocessable()->assertJsonValidationErrors(['allocations' => 'An invoice allocation must be positive and cannot exceed the invoice balance.']);
        $payment('59.00')->assertCreated();

        $this->assertSame('paid', Invoice::query()->findOrFail($invoiceId)->status);
    }

    public function test_vat_summary_ageing_and_statement_reflect_the_credit_from_its_date(): void
    {
        [$user, $business, , $customer, $invoiceId] = $this->issuedSale();
        $this->issue($user, $business, $this->creditNote($user, $business, $invoiceId, '50.00', '2026-07-05')->json('data.id'))->assertOk();

        $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/reports/financial?type=vat_summary&from=2026-07-01&to=2026-07-31")
            ->assertJsonPath('data.rows.0.entry_type', 'credit_note')
            ->assertJsonPath('data.totals.sales.tax_amount', '-9.00');
        $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/reports/financial?type=receivable_aging&to=2026-07-04")
            ->assertJsonPath('data.rows.0.total', '118.00');
        $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/reports/financial?type=receivable_aging&to=2026-07-05")
            ->assertJsonPath('data.rows.0.total', '59.00');
        $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/dashboard?from=2026-07-01&to=2026-07-31")
            ->assertJsonPath('data.sales_vat', '-9.00')
            ->assertJsonPath('data.receivables', '59.00');
        $lines = $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/counterparties/{$customer->id}/statement?from=2026-06-01&to=2026-07-31")->json('data.sections.0.lines');
        $this->assertSame([['invoice', '118.00'], ['credit_note', '59.00']], array_map(fn (array $line): array => [$line['type'], $line['balance']], $lines));
    }

    public function test_credited_invoices_and_credit_notes_cannot_be_cancelled(): void
    {
        [$user, $business, , , $invoiceId] = $this->issuedSale();
        $creditNoteId = $this->creditNote($user, $business, $invoiceId, '10.00')->json('data.id');
        $this->issue($user, $business, $creditNoteId)->assertOk();
        $cancel = fn (int $id) => $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$id}/cancel", ['cancelled_on' => '2026-07-10', 'cancellation_reason' => 'Test']);

        $cancel($invoiceId)->assertUnprocessable()->assertJsonValidationErrors(['invoice' => 'An invoice with credit notes cannot be cancelled.']);
        $cancel($creditNoteId)->assertUnprocessable()->assertJsonValidationErrors(['invoice' => 'A credit note cannot be cancelled. Issue a new invoice to reverse it.']);
    }

    public function test_credit_note_for_returned_goods_puts_them_back_in_stock(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $this->mapPostingAccounts($business);
        $customer = Counterparty::factory()->for($business)->customer()->create();
        $item = InventoryItem::factory()->for($business)->create();
        $warehouse = Warehouse::factory()->for($business)->create();
        StockMovement::factory()->for($business)->create(['inventory_item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'movement_type' => 'opening', 'quantity_delta' => '5']);
        $stockLine = ['description' => $item->name, 'quantity' => '2', 'unit_price' => '10.00', 'vat_code' => 'S18', 'inventory_item_id' => $item->id, 'warehouse_id' => $warehouse->id];
        $invoiceId = $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices", ['kind' => 'sale', 'counterparty_id' => $customer->id, 'issued_on' => '2026-06-15', 'lines' => [$stockLine]])->json('data.id');
        $this->issue($user, $business, $invoiceId)->assertOk();

        $creditNoteId = $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/credit-notes", [
            'issued_on' => '2026-07-01',
            'notes' => 'One unit returned',
            'lines' => [['quantity' => '1'] + $stockLine],
        ])->assertCreated()->json('data.id');
        $this->issue($user, $business, $creditNoteId)->assertOk();

        $this->assertSame(4.0, (float) StockMovement::query()->where('inventory_item_id', $item->id)->sum('quantity_delta'));
        $this->assertDatabaseHas('stock_movements', ['invoice_id' => $creditNoteId, 'movement_type' => 'sale_credit', 'quantity_delta' => '1.0000']);
    }

    public function test_supplier_credit_note_needs_the_suppliers_number(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $this->mapPostingAccounts($business);
        $supplier = Counterparty::factory()->for($business)->supplier()->create();
        $billId = $this->createDraft($user, $business, ['kind' => 'purchase', 'counterparty_id' => $supplier->id, 'invoice_number' => 'B-1', 'document_id' => Document::factory()->for($business)->invoice()->create()->id], 'S18');
        $this->issue($user, $business, $billId)->assertOk();

        $this->creditNote($user, $business, $billId, '10.00')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['invoice_number' => "Enter the supplier's credit note number."]);
    }

    public function test_drafts_cannot_be_credited(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $customer = Counterparty::factory()->for($business)->customer()->create();
        $draftId = $this->createDraft($user, $business, ['kind' => 'sale', 'counterparty_id' => $customer->id], 'S18');

        $this->creditNote($user, $business, $draftId, '10.00')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['invoice' => 'Credit notes can only be made for issued, partially paid, or paid invoices.']);
    }

    /** @return array{User, Business, array<string, Account>, Counterparty, int} */
    private function issuedSale(): array
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $accounts = $this->mapPostingAccounts($business);
        $customer = Counterparty::factory()->for($business)->customer()->create();
        $invoiceId = $this->createDraft($user, $business, ['kind' => 'sale', 'counterparty_id' => $customer->id], 'S18');
        $this->issue($user, $business, $invoiceId)->assertOk();

        return [$user, $business, $accounts, $customer, $invoiceId];
    }

    private function creditNote(User $user, Business $business, int $invoiceId, string $net, string $date = '2026-07-01'): TestResponse
    {
        return $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/credit-notes", [
            'issued_on' => $date,
            'notes' => 'Price correction',
            'lines' => [['description' => 'Correction', 'quantity' => '1', 'unit_price' => $net, 'vat_code' => 'S18']],
        ]);
    }

    private function issue(User $user, Business $business, int $invoiceId): TestResponse
    {
        return $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/issue");
    }
}
