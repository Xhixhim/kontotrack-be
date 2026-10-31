<?php

namespace Tests\Feature\Api\V1;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Business;
use App\Models\CashAccount;
use App\Models\Counterparty;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBookkeepingFixtures;
use Tests\TestCase;

class PaymentStoreTest extends TestCase
{
    use CreatesBookkeepingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-06-20 10:00:00');
    }

    public function test_fully_allocated_cash_receipt_posts_cash_against_receivable_and_marks_invoice_paid(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $accounts = $this->mapPostingAccounts($business);
        $cashAccount = $this->cashAccount($business);
        $customer = Counterparty::factory()->for($business)->customer()->create();
        $invoiceId = $this->issuedInvoice($user, $business, ['kind' => 'sale', 'counterparty_id' => $customer->id]);

        $response = $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/payments", $this->paymentPayload($business, [
            'cash_account_id' => $cashAccount->id,
            'direction' => 'in',
            'amount' => '118.00',
            'allocations' => [['invoice_id' => $invoiceId, 'amount' => '118.00']],
        ]));

        $response->assertCreated();
        $payment = Payment::query()->sole();
        $entry = JournalEntry::query()->with('lines')->findOrFail($payment->journal_entry_id);
        $this->assertSame('posted', $entry->status);
        $this->assertSame('2026-06-20', $entry->entry_date->toDateString());
        $this->assertSame([
            [$cashAccount->account_id, null, '118.00', '0.00'],
            [$accounts['accounts_receivable']->id, $customer->id, '0.00', '118.00'],
        ], $this->lineSummary($entry));
        $this->assertSame('paid', Invoice::query()->findOrFail($invoiceId)->status);
    }

    public function test_partially_allocated_bank_payment_posts_payable_against_bank_and_records_bank_transaction(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $accounts = $this->mapPostingAccounts($business);
        $bankAccount = $this->bankAccount($business);
        $supplier = Counterparty::factory()->for($business)->supplier()->create();
        $invoiceId = $this->issuedInvoice($user, $business, [
            'kind' => 'purchase',
            'counterparty_id' => $supplier->id,
            'invoice_number' => 'SUP-1',
            'document_id' => Document::factory()->for($business)->invoice()->create()->id,
        ]);

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/payments", $this->paymentPayload($business, [
            'bank_account_id' => $bankAccount->id,
            'direction' => 'out',
            'amount' => '50.00',
            'allocations' => [['invoice_id' => $invoiceId, 'amount' => '50.00']],
        ]))->assertCreated();

        $payment = Payment::query()->sole();
        $this->assertSame([
            [$accounts['accounts_payable']->id, $supplier->id, '50.00', '0.00'],
            [$bankAccount->account_id, null, '0.00', '50.00'],
        ], $this->lineSummary(JournalEntry::query()->with('lines')->findOrFail($payment->journal_entry_id)));
        $this->assertDatabaseHas('bank_transactions', ['bank_account_id' => $bankAccount->id, 'journal_entry_id' => $payment->journal_entry_id, 'amount' => '-50.00']);
        $this->assertSame('partially_paid', Invoice::query()->findOrFail($invoiceId)->status);
    }

    public function test_receipt_allocated_to_two_customers_credits_each_customer_separately(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $accounts = $this->mapPostingAccounts($business);
        $cashAccount = $this->cashAccount($business);
        $firstCustomer = Counterparty::factory()->for($business)->customer()->create();
        $secondCustomer = Counterparty::factory()->for($business)->customer()->create();
        $firstInvoiceId = $this->issuedInvoice($user, $business, ['kind' => 'sale', 'counterparty_id' => $firstCustomer->id]);
        $secondInvoiceId = $this->issuedInvoice($user, $business, ['kind' => 'sale', 'counterparty_id' => $secondCustomer->id]);

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/payments", $this->paymentPayload($business, [
            'cash_account_id' => $cashAccount->id,
            'direction' => 'in',
            'amount' => '150.00',
            'allocations' => [['invoice_id' => $firstInvoiceId, 'amount' => '100.00'], ['invoice_id' => $secondInvoiceId, 'amount' => '50.00']],
        ]))->assertCreated();

        $this->assertSame([
            [$cashAccount->account_id, null, '150.00', '0.00'],
            [$accounts['accounts_receivable']->id, $firstCustomer->id, '0.00', '100.00'],
            [$accounts['accounts_receivable']->id, $secondCustomer->id, '0.00', '50.00'],
        ], $this->lineSummary(JournalEntry::query()->with('lines')->findOrFail(Payment::query()->sole()->journal_entry_id)));
    }

    public function test_rejects_automatic_posting_with_422_when_allocations_do_not_cover_the_amount(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $this->mapPostingAccounts($business);
        $cashAccount = $this->cashAccount($business);
        $customer = Counterparty::factory()->for($business)->customer()->create();
        $invoiceId = $this->issuedInvoice($user, $business, ['kind' => 'sale', 'counterparty_id' => $customer->id]);
        $journalCountBefore = JournalEntry::query()->count();

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/payments", $this->paymentPayload($business, [
            'cash_account_id' => $cashAccount->id,
            'direction' => 'in',
            'amount' => '118.00',
            'allocations' => [['invoice_id' => $invoiceId, 'amount' => '100.00']],
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['allocations' => 'To post automatically, allocate the full payment amount to invoices, choose the account the money is for, or choose a posted journal entry.']);

        $this->assertSame($journalCountBefore, JournalEntry::query()->count());
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_rejects_automatic_posting_with_422_when_there_are_no_allocations(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $this->mapPostingAccounts($business);
        $cashAccount = $this->cashAccount($business);

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/payments", $this->paymentPayload($business, [
            'cash_account_id' => $cashAccount->id,
            'direction' => 'in',
            'amount' => '20.00',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['allocations' => 'To post automatically, allocate the full payment amount to invoices, choose the account the money is for, or choose a posted journal entry.']);

        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_rejects_automatic_posting_with_422_when_the_control_account_is_not_mapped(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $accounts = $this->mapPostingAccounts($business);
        $cashAccount = $this->cashAccount($business);
        $customer = Counterparty::factory()->for($business)->customer()->create();
        $invoiceId = $this->issuedInvoice($user, $business, ['kind' => 'sale', 'counterparty_id' => $customer->id]);
        $accounts['accounts_receivable']->update(['is_active' => false]);

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/payments", $this->paymentPayload($business, [
            'cash_account_id' => $cashAccount->id,
            'direction' => 'in',
            'amount' => '118.00',
            'allocations' => [['invoice_id' => $invoiceId, 'amount' => '118.00']],
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['posting_accounts' => 'Map active ledger accounts for customer receivables before posting automatically.']);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_rejects_automatic_posting_with_422_when_no_open_period_contains_the_payment_date(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $this->mapPostingAccounts($business);
        $cashAccount = $this->cashAccount($business);
        $customer = Counterparty::factory()->for($business)->customer()->create();
        $invoiceId = $this->issuedInvoice($user, $business, ['kind' => 'sale', 'counterparty_id' => $customer->id]);
        $business->accountingPeriods()->update(['status' => 'closed']);

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/payments", $this->paymentPayload($business, [
            'cash_account_id' => $cashAccount->id,
            'direction' => 'in',
            'amount' => '118.00',
            'allocations' => [['invoice_id' => $invoiceId, 'amount' => '118.00']],
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['paid_on' => 'Open an accounting period that contains the payment date before recording it.']);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_a_supplied_journal_entry_still_uses_the_manual_payment_rules(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $cashAccount = $this->cashAccount($business);
        $draftEntry = JournalEntry::query()->create([
            'business_id' => $business->id,
            'accounting_period_id' => $business->accountingPeriods()->value('id'),
            'entry_date' => '2026-06-20',
            'description' => 'Unposted manual entry',
            'status' => 'draft',
        ]);

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/payments", $this->paymentPayload($business, [
            'cash_account_id' => $cashAccount->id,
            'journal_entry_id' => $draftEntry->id,
            'direction' => 'in',
            'amount' => '20.00',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['journal_entry_id' => 'Choose a posted journal entry dated on the payment date.']);
    }

    public function test_cash_expense_without_an_invoice_posts_against_the_chosen_account(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $cashAccount = $this->cashAccount($business);
        $this->fundCash($user, $business, $cashAccount, '100.00');
        $fuel = Account::factory()->for($business)->ofType('expense')->create();

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/payments", $this->paymentPayload($business, [
            'cash_account_id' => $cashAccount->id,
            'direction' => 'out',
            'amount' => '45.50',
            'offset_account_id' => $fuel->id,
            'notes' => 'Fuel',
        ]))->assertCreated();

        $this->assertSame([
            [$fuel->id, null, '45.50', '0.00'],
            [$cashAccount->account_id, null, '0.00', '45.50'],
        ], $this->lineSummary(JournalEntry::query()->with('lines')->findOrFail(Payment::query()->where('direction', 'out')->sole()->journal_entry_id)));
    }

    public function test_rejects_a_cash_payment_that_would_make_cash_negative_with_422(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $cashAccount = $this->cashAccount($business);
        $this->fundCash($user, $business, $cashAccount, '30.00');
        $fuel = Account::factory()->for($business)->ofType('expense')->create();

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/payments", $this->paymentPayload($business, [
            'cash_account_id' => $cashAccount->id,
            'direction' => 'out',
            'amount' => '45.50',
            'offset_account_id' => $fuel->id,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines' => "This would leave the cash account \"{$cashAccount->name}\" at -15.50 on 2026-06-20. Cash on hand cannot be negative, so record the missing cash receipts first."]);

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseMissing('journal_entries', ['business_id' => $business->id, 'status' => 'posted', 'entry_date' => '2026-06-20', 'description' => 'Fuel']);
    }

    public function test_rejects_using_the_cash_account_itself_as_the_offset_with_422(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $cashAccount = $this->cashAccount($business);

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/payments", $this->paymentPayload($business, [
            'cash_account_id' => $cashAccount->id,
            'direction' => 'out',
            'amount' => '10.00',
            'offset_account_id' => $cashAccount->account_id,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['offset_account_id' => 'Choose another active account from this business for what the money is for.']);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_rejects_an_offset_account_together_with_invoice_allocations_with_422(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $cashAccount = $this->cashAccount($business);

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/payments", $this->paymentPayload($business, [
            'cash_account_id' => $cashAccount->id,
            'direction' => 'in',
            'amount' => '10.00',
            'offset_account_id' => Account::factory()->for($business)->ofType('revenue')->create()->id,
            'allocations' => [['invoice_id' => 1, 'amount' => '10.00']],
        ]))->assertUnprocessable()->assertJsonValidationErrors('offset_account_id');
    }

    private function cashAccount(Business $business): CashAccount
    {
        return CashAccount::factory()->for($business)->create([
            'account_id' => Account::factory()->for($business)->ofType('asset')->create()->id,
        ]);
    }

    private function fundCash(User $user, Business $business, CashAccount $cashAccount, string $amount): void
    {
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/payments", $this->paymentPayload($business, [
            'cash_account_id' => $cashAccount->id,
            'direction' => 'in',
            'amount' => $amount,
            'offset_account_id' => Account::factory()->for($business)->ofType('equity')->create()->id,
        ]))->assertCreated();
    }

    private function bankAccount(Business $business): BankAccount
    {
        return BankAccount::factory()->for($business)->create([
            'account_id' => Account::factory()->for($business)->ofType('asset')->create()->id,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function issuedInvoice(User $user, Business $business, array $attributes): int
    {
        $invoiceId = $this->createDraft($user, $business, $attributes, 'S18');
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/issue")->assertOk();

        return $invoiceId;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function paymentPayload(Business $business, array $attributes): array
    {
        return $attributes + [
            'document_id' => Document::factory()->for($business)->create()->id,
            'paid_on' => '2026-06-20',
        ];
    }
}
