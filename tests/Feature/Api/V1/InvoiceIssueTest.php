<?php

namespace Tests\Feature\Api\V1;

use App\Models\AuditLog;
use App\Models\Counterparty;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\JournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBookkeepingFixtures;
use Tests\TestCase;

class InvoiceIssueTest extends TestCase
{
    use CreatesBookkeepingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-06-15 10:00:00');
    }

    public function test_issuing_a_sale_without_a_journal_entry_posts_receivable_revenue_and_output_vat(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $accounts = $this->mapPostingAccounts($business);
        $customer = Counterparty::factory()->for($business)->customer()->create();
        $invoiceId = $this->createDraft($user, $business, ['kind' => 'sale', 'counterparty_id' => $customer->id], 'S18');

        $response = $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/issue");

        $response->assertOk()->assertJsonPath('data.status', 'issued');
        $invoice = Invoice::query()->findOrFail($invoiceId);
        $entry = JournalEntry::query()->with('lines')->findOrFail($invoice->journal_entry_id);
        $this->assertSame('posted', $entry->status);
        $this->assertSame('2026-06-15', $entry->entry_date->toDateString());
        $this->assertSame($invoice->invoice_number, $entry->reference);
        $this->assertSame([
            [$accounts['accounts_receivable']->id, $customer->id, '118.00', '0.00'],
            [$accounts['sales_revenue']->id, null, '0.00', '100.00'],
            [$accounts['vat_output']->id, null, '0.00', '18.00'],
        ], $this->lineSummary($entry));
    }

    public function test_issuing_a_purchase_without_a_journal_entry_posts_expense_input_vat_and_payable(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $accounts = $this->mapPostingAccounts($business);
        $supplier = Counterparty::factory()->for($business)->supplier()->create();
        $bill = Document::factory()->for($business)->invoice()->create();
        $invoiceId = $this->createDraft($user, $business, [
            'kind' => 'purchase',
            'counterparty_id' => $supplier->id,
            'invoice_number' => 'SUP-2026-77',
            'document_id' => $bill->id,
        ], 'S18');

        $this->actingAs($user)
            ->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/issue")
            ->assertOk()
            ->assertJsonPath('data.status', 'issued');

        $entry = JournalEntry::query()->with('lines')->findOrFail(Invoice::query()->findOrFail($invoiceId)->journal_entry_id);
        $this->assertSame($bill->id, $entry->document_id);
        $this->assertSame([
            [$accounts['purchase_expense']->id, null, '100.00', '0.00'],
            [$accounts['vat_input']->id, null, '18.00', '0.00'],
            [$accounts['accounts_payable']->id, $supplier->id, '0.00', '118.00'],
        ], $this->lineSummary($entry));
    }

    public function test_exempt_sale_posts_without_a_vat_line_or_vat_mapping(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $accounts = $this->mapPostingAccounts($business, ['accounts_receivable', 'sales_revenue']);
        $customer = Counterparty::factory()->for($business)->customer()->create();
        $invoiceId = $this->createDraft($user, $business, ['kind' => 'sale', 'counterparty_id' => $customer->id], 'EX');

        $this->actingAs($user)
            ->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/issue")
            ->assertOk();

        $entry = JournalEntry::query()->with('lines')->sole();
        $this->assertSame([
            [$accounts['accounts_receivable']->id, $customer->id, '100.00', '0.00'],
            [$accounts['sales_revenue']->id, null, '0.00', '100.00'],
        ], $this->lineSummary($entry));
    }

    public function test_rejects_issue_with_422_and_creates_no_journal_entry_when_posting_accounts_are_missing(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $this->mapPostingAccounts($business, ['accounts_receivable']);
        $customer = Counterparty::factory()->for($business)->customer()->create();
        $invoiceId = $this->createDraft($user, $business, ['kind' => 'sale', 'counterparty_id' => $customer->id], 'S18');

        $this->actingAs($user)
            ->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/issue")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['posting_accounts' => 'Map active ledger accounts for sales revenue, output vat payable before posting automatically.']);

        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertSame('draft', Invoice::query()->findOrFail($invoiceId)->status);
    }

    public function test_rejects_issue_with_422_when_no_open_period_contains_the_invoice_date(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $this->mapPostingAccounts($business);
        $customer = Counterparty::factory()->for($business)->customer()->create();
        $invoiceId = $this->createDraft($user, $business, ['kind' => 'sale', 'counterparty_id' => $customer->id], 'S18');
        $business->accountingPeriods()->update(['status' => 'closed']);

        $this->actingAs($user)
            ->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/issue")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['issued_on' => 'Open an accounting period that contains the invoice date before issuing it.']);

        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_rejects_reissuing_an_issued_invoice_with_422(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $this->mapPostingAccounts($business);
        $customer = Counterparty::factory()->for($business)->customer()->create();
        $invoiceId = $this->createDraft($user, $business, ['kind' => 'sale', 'counterparty_id' => $customer->id], 'S18');
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/issue")->assertOk();

        $this->actingAs($user)
            ->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/issue")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['invoice' => 'Only draft invoices can be issued.']);

        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_a_draft_can_be_deleted_and_its_number_is_kept_in_the_audit_log(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $customer = Counterparty::factory()->for($business)->customer()->create();
        $invoiceId = $this->createDraft($user, $business, ['kind' => 'sale', 'counterparty_id' => $customer->id], 'S18');
        $number = Invoice::query()->findOrFail($invoiceId)->invoice_number;

        $this->actingAs($user)->deleteJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}")->assertOk();

        $this->assertDatabaseMissing('invoices', ['id' => $invoiceId]);
        $this->assertSame($number, AuditLog::query()->where('action', 'invoice.draft_deleted')->sole()->old_values['invoice_number']);
    }

    public function test_issued_invoices_cannot_be_deleted(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $this->mapPostingAccounts($business);
        $customer = Counterparty::factory()->for($business)->customer()->create();
        $invoiceId = $this->createDraft($user, $business, ['kind' => 'sale', 'counterparty_id' => $customer->id], 'S18');
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/issue")->assertOk();

        $this->actingAs($user)->deleteJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['invoice' => 'Only draft invoices can be deleted. Cancel or credit an issued invoice instead.']);
    }

    public function test_a_supplied_journal_entry_still_uses_the_manual_issue_rules(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $this->mapPostingAccounts($business);
        $customer = Counterparty::factory()->for($business)->customer()->create();
        $invoiceId = $this->createDraft($user, $business, ['kind' => 'sale', 'counterparty_id' => $customer->id], 'S18');
        $draftEntry = JournalEntry::query()->create([
            'business_id' => $business->id,
            'accounting_period_id' => $business->accountingPeriods()->value('id'),
            'entry_date' => '2026-06-15',
            'description' => 'Unposted manual entry',
            'status' => 'draft',
        ]);

        $this->actingAs($user)
            ->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/issue", ['journal_entry_id' => $draftEntry->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['journal_entry_id' => 'Choose a posted journal entry dated on the invoice date.']);

        $this->assertDatabaseCount('journal_entries', 1);
    }
}
