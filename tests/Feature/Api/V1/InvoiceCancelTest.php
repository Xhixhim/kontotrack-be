<?php

namespace Tests\Feature\Api\V1;

use App\Models\Account;
use App\Models\AuditLog;
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
use Tests\Concerns\CreatesBookkeepingFixtures;
use Tests\TestCase;

class InvoiceCancelTest extends TestCase
{
    use CreatesBookkeepingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-07-10 10:00:00');
    }

    public function test_cancelling_an_issued_invoice_posts_a_reversal_and_marks_it_cancelled(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $accounts = $this->mapPostingAccounts($business);
        $customer = Counterparty::factory()->for($business)->customer()->create();
        $invoiceId = $this->issuedSale($user, $business, $customer);

        $response = $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/cancel", [
            'cancelled_on' => '2026-07-05',
            'cancellation_reason' => 'Wrong customer',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancelled_on', '2026-07-05')
            ->assertJsonPath('data.cancellation_reason', 'Wrong customer');
        $invoice = Invoice::query()->findOrFail($invoiceId);
        $reversal = JournalEntry::query()->with('lines')->findOrFail($invoice->cancellation_journal_entry_id);
        $this->assertSame('posted', $reversal->status);
        $this->assertSame($invoice->journal_entry_id, $reversal->reversal_of_id);
        $this->assertSame('2026-07-05', $reversal->entry_date->toDateString());
        $this->assertSame([
            [$accounts['accounts_receivable']->id, $customer->id, '0.00', '118.00'],
            [$accounts['sales_revenue']->id, null, '100.00', '0.00'],
            [$accounts['vat_output']->id, null, '18.00', '0.00'],
        ], $this->lineSummary($reversal));
        $this->assertSame($user->id, AuditLog::query()->where('action', 'invoice.cancelled')->sole()->user_id);
    }

    public function test_cancelling_a_stock_sale_returns_the_quantity_to_the_warehouse(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $this->mapPostingAccounts($business);
        [$item, $warehouse] = $this->stockedItem($business, '5.0000');
        $customer = Counterparty::factory()->for($business)->customer()->create();
        $invoiceId = $this->issueStockInvoice($user, $business, ['kind' => 'sale', 'counterparty_id' => $customer->id], $item, $warehouse);
        $this->assertSame(3.0, $this->stockOnHand($item, $warehouse));

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/cancel", [
            'cancelled_on' => '2026-07-10',
            'cancellation_reason' => 'Goods returned',
        ])->assertOk();

        $this->assertSame(5.0, $this->stockOnHand($item, $warehouse));
        $this->assertDatabaseHas('stock_movements', ['invoice_id' => $invoiceId, 'movement_type' => 'invoice_cancellation', 'quantity_delta' => '2.0000', 'invoice_line_id' => null]);
    }

    public function test_rejects_cancelling_a_purchase_with_422_when_its_stock_was_already_sold(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $this->mapPostingAccounts($business);
        [$item, $warehouse] = $this->stockedItem($business, '0.0000');
        $supplier = Counterparty::factory()->for($business)->supplier()->create();
        $customer = Counterparty::factory()->for($business)->customer()->create();
        $purchaseId = $this->issueStockInvoice($user, $business, [
            'kind' => 'purchase',
            'counterparty_id' => $supplier->id,
            'invoice_number' => 'SUP-9',
            'document_id' => Document::factory()->for($business)->invoice()->create()->id,
        ], $item, $warehouse);
        $this->issueStockInvoice($user, $business, ['kind' => 'sale', 'counterparty_id' => $customer->id], $item, $warehouse);

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$purchaseId}/cancel", [
            'cancelled_on' => '2026-07-10',
            'cancellation_reason' => 'Duplicate bill',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['invoice' => "Cancelling this invoice would make stock of {$item->name} negative in {$warehouse->name}."]);

        $this->assertSame('issued', Invoice::query()->findOrFail($purchaseId)->status);
        $this->assertSame(0, JournalEntry::query()->whereNotNull('reversal_of_id')->count());
    }

    public function test_rejects_cancelling_an_invoice_with_payments_with_422(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $this->mapPostingAccounts($business);
        $customer = Counterparty::factory()->for($business)->customer()->create();
        $invoiceId = $this->issuedSale($user, $business, $customer);
        $cashAccount = CashAccount::factory()->for($business)->create([
            'account_id' => Account::factory()->for($business)->ofType('asset')->create()->id,
        ]);
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/payments", [
            'cash_account_id' => $cashAccount->id,
            'document_id' => Document::factory()->for($business)->create()->id,
            'paid_on' => '2026-07-01',
            'direction' => 'in',
            'amount' => '18.00',
            'allocations' => [['invoice_id' => $invoiceId, 'amount' => '18.00']],
        ])->assertCreated();

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/cancel", [
            'cancelled_on' => '2026-07-10',
            'cancellation_reason' => 'Customer dispute',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['invoice' => 'An invoice with recorded payments cannot be cancelled.']);
    }

    public function test_rejects_cancelling_a_draft_with_422(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $customer = Counterparty::factory()->for($business)->customer()->create();
        $invoiceId = $this->createDraft($user, $business, ['kind' => 'sale', 'counterparty_id' => $customer->id], 'S18');

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/cancel", [
            'cancelled_on' => '2026-07-10',
            'cancellation_reason' => 'Not needed',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['invoice' => 'Only issued invoices can be cancelled.']);
    }

    public function test_rejects_a_cancellation_date_before_the_invoice_date_with_422(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $this->mapPostingAccounts($business);
        $invoiceId = $this->issuedSale($user, $business, Counterparty::factory()->for($business)->customer()->create());

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/cancel", [
            'cancelled_on' => '2026-06-14',
            'cancellation_reason' => 'Typo',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cancelled_on' => 'The cancellation date cannot be before the invoice date.']);
    }

    public function test_rejects_cancellation_with_422_when_no_open_period_contains_the_date(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $this->mapPostingAccounts($business);
        $invoiceId = $this->issuedSale($user, $business, Counterparty::factory()->for($business)->customer()->create());

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/cancel", [
            'cancelled_on' => '2027-01-05',
            'cancellation_reason' => 'Late correction',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cancelled_on' => 'Open an accounting period that contains the cancellation date.']);

        $this->assertSame('issued', Invoice::query()->findOrFail($invoiceId)->status);
    }

    public function test_requires_a_cancellation_date_and_reason(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $this->mapPostingAccounts($business);
        $invoiceId = $this->issuedSale($user, $business, Counterparty::factory()->for($business)->customer()->create());

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/cancel", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cancelled_on', 'cancellation_reason']);
    }

    public function test_auditor_cannot_cancel_an_invoice(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $this->mapPostingAccounts($business);
        $invoiceId = $this->issuedSale($user, $business, Counterparty::factory()->for($business)->customer()->create());
        $auditor = User::factory()->create();
        $business->users()->attach($auditor, ['role' => 'auditor', 'status' => 'active']);

        $this->actingAs($auditor)->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/cancel", [
            'cancelled_on' => '2026-07-10',
            'cancellation_reason' => 'Not allowed',
        ])->assertForbidden();

        $this->assertSame('issued', Invoice::query()->findOrFail($invoiceId)->status);
    }

    public function test_vat_summary_keeps_the_original_month_and_reports_the_cancellation_in_its_own_month(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $this->mapPostingAccounts($business);
        $invoiceId = $this->issuedSale($user, $business, Counterparty::factory()->for($business)->customer()->create());
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/cancel", [
            'cancelled_on' => '2026-07-05',
            'cancellation_reason' => 'Wrong customer',
        ])->assertOk();

        $this->actingAs($user)
            ->getJson("/api/v1/businesses/{$business->id}/reports/financial?type=vat_summary&from=2026-06-01&to=2026-06-30")
            ->assertOk()
            ->assertJsonCount(1, 'data.rows')
            ->assertJsonPath('data.rows.0.entry_type', 'invoice')
            ->assertJsonPath('data.totals.sales.tax_amount', '18.00');
        $this->actingAs($user)
            ->getJson("/api/v1/businesses/{$business->id}/reports/financial?type=vat_summary&from=2026-07-01&to=2026-07-31")
            ->assertOk()
            ->assertJsonCount(1, 'data.rows')
            ->assertJsonPath('data.rows.0.entry_type', 'cancellation')
            ->assertJsonPath('data.rows.0.reported_on', '2026-07-05')
            ->assertJsonPath('data.totals.sales.tax_amount', '-18.00');
    }

    public function test_receivable_aging_includes_a_cancelled_invoice_only_before_its_cancellation_date(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $this->mapPostingAccounts($business);
        $invoiceId = $this->issuedSale($user, $business, Counterparty::factory()->for($business)->customer()->create());
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/cancel", [
            'cancelled_on' => '2026-07-05',
            'cancellation_reason' => 'Wrong customer',
        ])->assertOk();

        $this->actingAs($user)
            ->getJson("/api/v1/businesses/{$business->id}/reports/financial?type=receivable_aging&to=2026-07-04")
            ->assertOk()
            ->assertJsonPath('data.rows.0.invoices.0.id', $invoiceId);
        $this->actingAs($user)
            ->getJson("/api/v1/businesses/{$business->id}/reports/financial?type=receivable_aging&to=2026-07-05")
            ->assertOk()
            ->assertJsonCount(0, 'data.rows');
    }

    public function test_dashboard_nets_cancelled_vat_in_the_cancellation_month_and_drops_the_receivable(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $this->mapPostingAccounts($business);
        $invoiceId = $this->issuedSale($user, $business, Counterparty::factory()->for($business)->customer()->create());
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/cancel", [
            'cancelled_on' => '2026-07-05',
            'cancellation_reason' => 'Wrong customer',
        ])->assertOk();

        $this->actingAs($user)
            ->getJson("/api/v1/businesses/{$business->id}/dashboard?from=2026-06-01&to=2026-06-30")
            ->assertOk()
            ->assertJsonPath('data.sales_vat', '18.00')
            ->assertJsonPath('data.receivables', '118.00');
        $this->actingAs($user)
            ->getJson("/api/v1/businesses/{$business->id}/dashboard?from=2026-07-01&to=2026-07-31")
            ->assertOk()
            ->assertJsonPath('data.sales_vat', '-18.00')
            ->assertJsonPath('data.receivables', '0.00');
    }

    public function test_closing_review_treats_invoice_and_cancellation_journals_as_having_a_source(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $this->mapPostingAccounts($business);
        $invoiceId = $this->issuedSale($user, $business, Counterparty::factory()->for($business)->customer()->create());
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/cancel", [
            'cancelled_on' => '2026-07-05',
            'cancellation_reason' => 'Wrong customer',
        ])->assertOk();
        $periodId = $business->accountingPeriods()->value('id');

        $checks = $this->actingAs($user)
            ->getJson("/api/v1/businesses/{$business->id}/periods/{$periodId}/closing-checks")
            ->assertOk()
            ->json('data.checks');

        $this->assertSame('passed', collect($checks)->firstWhere('key', 'source_documents')['status']);
    }

    private function issuedSale(User $user, Business $business, Counterparty $customer): int
    {
        $invoiceId = $this->createDraft($user, $business, ['kind' => 'sale', 'counterparty_id' => $customer->id], 'S18');
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/issue")->assertOk();

        return $invoiceId;
    }

    /** @return array{InventoryItem, Warehouse} */
    private function stockedItem(Business $business, string $openingQuantity): array
    {
        $item = InventoryItem::factory()->for($business)->create();
        $warehouse = Warehouse::factory()->for($business)->create();

        if ($openingQuantity !== '0.0000') {
            StockMovement::factory()->for($business)->create([
                'inventory_item_id' => $item->id,
                'warehouse_id' => $warehouse->id,
                'movement_type' => 'opening',
                'quantity_delta' => $openingQuantity,
                'occurred_on' => '2026-06-01',
            ]);
        }

        return [$item, $warehouse];
    }

    /**
     * Create and issue an invoice for 2 units of the item.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function issueStockInvoice(User $user, Business $business, array $attributes, InventoryItem $item, Warehouse $warehouse): int
    {
        $invoiceId = $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices", $attributes + [
            'issued_on' => '2026-06-15',
            'lines' => [[
                'description' => $item->name,
                'quantity' => '2',
                'unit_price' => '10.00',
                'vat_code' => 'S18',
                'inventory_item_id' => $item->id,
                'warehouse_id' => $warehouse->id,
            ]],
        ])->assertCreated()->json('data.id');
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/issue")->assertOk();

        return $invoiceId;
    }

    private function stockOnHand(InventoryItem $item, Warehouse $warehouse): float
    {
        return (float) StockMovement::query()
            ->where('inventory_item_id', $item->id)
            ->where('warehouse_id', $warehouse->id)
            ->sum('quantity_delta');
    }
}
