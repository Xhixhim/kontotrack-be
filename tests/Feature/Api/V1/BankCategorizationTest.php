<?php

namespace Tests\Feature\Api\V1;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Business;
use App\Models\Counterparty;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesBookkeepingFixtures;
use Tests\TestCase;

class BankCategorizationTest extends TestCase
{
    use CreatesBookkeepingFixtures, RefreshDatabase;

    public function test_returns_403_to_a_user_outside_the_business(): void
    {
        [, $business] = $this->bookkeepingBusiness();
        $bank = $this->bankAccount($business);
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->getJson("/api/v1/businesses/{$business->id}/bank-rules")->assertForbidden();
        $this->actingAs($outsider)->putJson("/api/v1/businesses/{$business->id}/bank-accounts/{$bank->id}/transactions/1/category", ['category' => null])->assertForbidden();
        $this->actingAs($outsider)->getJson("/api/v1/businesses/{$business->id}/reports/financial?type=bank_reconciliation&bank_account_id={$bank->id}")->assertForbidden();
    }

    public function test_an_auditor_can_read_rules_but_not_categorize(): void
    {
        [, $business] = $this->bookkeepingBusiness();
        $bank = $this->bankAccount($business);
        $auditor = User::factory()->create();
        $business->users()->attach($auditor, ['role' => 'auditor', 'status' => 'active']);

        $this->actingAs($auditor)->getJson("/api/v1/businesses/{$business->id}/bank-rules")->assertOk();
        $this->actingAs($auditor)->postJson("/api/v1/businesses/{$business->id}/bank-accounts/{$bank->id}/apply-rules")->assertForbidden();
    }

    public function test_import_suggests_categories_from_rules_customer_names_and_bank_fee_wording(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $bank = $this->bankAccount($business);
        $customer = Counterparty::factory()->for($business)->customer()->create(['name' => 'Market Arbëri']);
        $rent = Account::factory()->for($business)->ofType('expense')->create();
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/bank-rules", ['match_text' => 'Qira', 'category' => 'other_expense', 'account_id' => $rent->id])
            ->assertCreated()
            ->assertJsonPath('data.match_text', 'qira')
            ->assertJsonPath('data.direction', 'out');

        $this->import($user, $business, $bank, "date,description,amount\n2026-06-20,Pagesa nga MARKET ARBERI,118\n2026-06-21,QIRAJA qershor,-300\n2026-06-22,Komision mirëmbajtje,-1.50\n2026-06-23,Diçka tjetër,10\n")
            ->assertCreated()
            ->assertJsonPath('meta.suggested_count', 3)
            ->assertJsonPath('data.0.category', 'customer_receipt')
            ->assertJsonPath('data.0.counterparty.id', $customer->id)
            ->assertJsonPath('data.0.category_is_suggestion', true)
            ->assertJsonPath('data.1.category', 'other_expense')
            ->assertJsonPath('data.1.account.id', $rent->id)
            ->assertJsonPath('data.2.category', 'bank_fee')
            ->assertJsonPath('data.2.account', null)
            ->assertJsonPath('data.3.category', null);
    }

    public function test_books_a_customer_receipt_against_the_oldest_open_invoices_and_reconciles_the_line(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $accounts = $this->mapPostingAccounts($business);
        $bank = $this->bankAccount($business);
        $customer = Counterparty::factory()->for($business)->customer()->create(['name' => 'Market Arbëri']);
        $first = $this->issuedInvoice($user, $business, $customer);
        $second = $this->issuedInvoice($user, $business, $customer);
        $this->import($user, $business, $bank, "date,description,amount\n2026-06-20,Pagesa Market Arbëri,150\n2026-06-21,Pagesa Market Arbëri avans,1000\n")->assertCreated();
        [$receipt, $tooLarge] = BankTransaction::query()->orderBy('id')->get();

        $this->actingAs($user)->postJson($this->lineUrl($business, $bank, $receipt, 'book'))
            ->assertOk()
            ->assertJsonPath('data.is_reconciled', true);

        $payment = Payment::query()->sole();
        $this->assertSame($receipt->id, $payment->bank_transaction_id);
        $this->assertSame([[$first, '118.00'], [$second, '32.00']], $payment->invoices()->orderBy('invoices.id')->get()->map(fn (Invoice $invoice): array => [$invoice->id, number_format((float) $invoice->pivot->amount, 2, '.', '')])->all());
        $this->assertSame(['paid', 'partially_paid'], [Invoice::query()->findOrFail($first)->status, Invoice::query()->findOrFail($second)->status]);
        $this->assertSame([
            [$bank->account_id, null, '150.00', '0.00'],
            [$accounts['accounts_receivable']->id, $customer->id, '0.00', '150.00'],
        ], $this->lineSummary(JournalEntry::query()->with('lines')->findOrFail($payment->journal_entry_id)));
        $this->assertSame($payment->journal_entry_id, $receipt->refresh()->journal_entry_id);
        $this->assertSame(2, BankTransaction::query()->count());

        $this->actingAs($user)->postJson($this->lineUrl($business, $bank, $tooLarge, 'book'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['allocations' => "Only 86.00 is open on this party's customer invoices up to this date, which is less than the bank amount. Issue or record the missing invoice first, or choose another category."]);
        $this->actingAs($user)->postJson($this->lineUrl($business, $bank, $receipt, 'book'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['transaction']);
    }

    public function test_categorizing_checks_direction_and_account_and_can_remember_a_rule(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $bank = $this->bankAccount($business);
        $rent = Account::factory()->for($business)->ofType('expense')->create();
        $this->import($user, $business, $bank, "date,description,amount\n2026-06-21,Qiraja e zyrës qershor,-300\n")->assertCreated();
        $line = BankTransaction::query()->sole();
        $url = $this->lineUrl($business, $bank, $line, 'category');

        $this->actingAs($user)->putJson($url, ['category' => 'customer_receipt'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category' => 'This category is for money received, but this line is money paid out.']);
        $this->actingAs($user)->putJson($url, ['category' => 'other_expense'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['account_id']);
        $this->actingAs($user)->putJson($url, ['category' => 'other_expense', 'account_id' => $bank->account_id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['account_id' => 'Choose the account this money is for, not the bank account itself.']);
        $this->actingAs($user)->putJson($url, ['category' => 'other_expense', 'account_id' => $rent->id, 'remember_as' => 'qiraja e zyres'])
            ->assertOk()
            ->assertJsonPath('data.category', 'other_expense')
            ->assertJsonPath('data.category_is_suggestion', false);
        $this->assertDatabaseHas('bank_categorization_rules', ['business_id' => $business->id, 'match_text' => 'qiraja e zyres', 'direction' => 'out', 'account_id' => $rent->id]);
        $this->assertDatabaseHas('audit_logs', ['business_id' => $business->id, 'action' => 'bank_transaction.categorized', 'subject_id' => $line->id]);

        $this->import($user, $business, $bank, "date,description,amount\n2026-07-21,QIRAJA E ZYRËS korrik,-300\n")
            ->assertCreated()
            ->assertJsonPath('data.0.category', 'other_expense')
            ->assertJsonPath('data.0.account.id', $rent->id);

        $this->actingAs($user)->postJson($this->lineUrl($business, $bank, $line, 'book'))->assertOk();
        $entry = JournalEntry::query()->with('lines')->findOrFail($line->refresh()->journal_entry_id);
        $this->assertSame([[$rent->id, null, '300.00', '0.00'], [$bank->account_id, null, '0.00', '300.00']], $this->lineSummary($entry));
        $this->assertSame('posted', $entry->status);
    }

    public function test_import_reconciles_a_payment_that_was_already_recorded_in_the_books(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $this->mapPostingAccounts($business);
        $bank = $this->bankAccount($business);
        $supplier = Counterparty::factory()->for($business)->supplier()->create();
        $billId = $this->issuedInvoice($user, $business, $supplier, ['kind' => 'purchase', 'invoice_number' => 'SUP-1', 'document_id' => Document::factory()->for($business)->invoice()->create()->id]);
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/payments", [
            'bank_account_id' => $bank->id, 'direction' => 'out', 'amount' => '50.00', 'paid_on' => '2026-06-20',
            'document_id' => Document::factory()->for($business)->create()->id,
            'allocations' => [['invoice_id' => $billId, 'amount' => '50.00']],
        ])->assertCreated();
        $payment = Payment::query()->sole();
        $this->assertSame('payment', BankTransaction::query()->sole()->source);

        $this->import($user, $business, $bank, "Data;Përshkrimi;Debi;Kredi\n20.06.2026;Pagesë furnitori;50,00;\n")
            ->assertCreated()
            ->assertJsonPath('meta.matched_count', 1)
            ->assertJsonPath('data.0.is_reconciled', true);

        $line = BankTransaction::query()->sole();
        $this->assertSame('import', $line->source);
        $this->assertSame($payment->journal_entry_id, $line->journal_entry_id);
        $this->assertSame($line->id, $payment->refresh()->bank_transaction_id);
    }

    public function test_bank_reconciliation_report_explains_the_difference_and_exports(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $bank = $this->bankAccount($business);
        $equity = Account::factory()->for($business)->ofType('equity')->create();
        $expense = Account::factory()->for($business)->ofType('expense')->create();
        // Before the first statement line, so already part of the statement's opening balance.
        $this->postedEntry($business, '2026-05-31', [[$bank->account_id, '1000.00', '0.00'], [$equity->id, '0.00', '1000.00']]);
        // Paid in the books on 25 June, but not on the bank statement yet.
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/payments", [
            'bank_account_id' => $bank->id, 'direction' => 'out', 'amount' => '200.00', 'paid_on' => '2026-06-25',
            'document_id' => Document::factory()->for($business)->create()->id, 'offset_account_id' => $expense->id, 'reference' => 'CHQ-7',
        ])->assertCreated();
        $this->import($user, $business, $bank, "Data,Përshkrimi,Shuma,Saldo\n10.06.2026,Arkëtim,500,1500\n12.06.2026,Komision,-2,1498\n")->assertCreated();

        $report = $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/reports/financial?type=bank_reconciliation&bank_account_id={$bank->id}&to=2026-06-30")
            ->assertOk()
            ->json('data');

        $this->assertSame([
            'statement_from' => '2026-06-10', 'ledger_balance' => '800.00', 'unrecorded_in' => '500.00', 'unrecorded_out' => '2.00',
            'not_on_statement_in' => '0.00', 'not_on_statement_out' => '200.00', 'expected_statement_balance' => '1498.00',
            'statement_balance' => '1498.00', 'statement_balance_source' => 'statement', 'difference' => '0.00', 'status' => 'open_items',
        ], array_intersect_key($report, array_flip(['statement_from', 'ledger_balance', 'unrecorded_in', 'unrecorded_out', 'not_on_statement_in', 'not_on_statement_out', 'expected_statement_balance', 'statement_balance', 'statement_balance_source', 'difference', 'status'])));
        $this->assertSame(['Arkëtim', 'Komision'], array_column($report['unrecorded'], 'description'));
        $this->assertSame([['CHQ-7', '-200.00']], array_map(fn (array $line): array => [$line['reference'], $line['amount']], $report['not_on_statement']));

        $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/reports/financial?type=bank_reconciliation&bank_account_id={$bank->id}&to=2026-06-30&statement_balance=1490")
            ->assertOk()
            ->assertJsonPath('data.difference', '-8.00')
            ->assertJsonPath('data.statement_balance_source', 'entered')
            ->assertJsonPath('data.status', 'difference');
        $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/reports/financial?type=bank_reconciliation")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['bank_account_id']);

        $xlsx = $this->actingAs($user)->get("/api/v1/businesses/{$business->id}/reports/financial/export?type=bank_reconciliation&format=xlsx&bank_account_id={$bank->id}&to=2026-06-30")->assertOk();
        $this->assertStringContainsString('Rakordimi bankar', $this->sheetXml($xlsx->getContent()));
        $pdf = $this->actingAs($user)->get("/api/v1/businesses/{$business->id}/reports/financial/export?type=bank_reconciliation&format=pdf&bank_account_id={$bank->id}&to=2026-06-30")->assertOk();
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
    }

    public function test_controls_warn_when_the_books_do_not_agree_with_the_statement_balance(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $bank = $this->bankAccount($business);
        $this->import($user, $business, $bank, "Data,Përshkrimi,Shuma,Saldo\n10.06.2026,Arkëtim,500,1500\n")->assertCreated();

        $check = collect($this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/controls")->assertOk()->json('data.checks'))->firstWhere('key', 'bank_differences');

        // The books show 0 + 500 unrecorded = 500 expected, but the bank says 1,500: the opening balance was never booked.
        $this->assertSame('warning', $check['severity']);
        $this->assertSame('1000.00', $check['items'][0]['difference']);
    }

    private function bankAccount(Business $business): BankAccount
    {
        return BankAccount::factory()->for($business)->create(['account_id' => Account::factory()->for($business)->ofType('asset')->create()->id]);
    }

    /** @param array<string, mixed> $attributes */
    private function issuedInvoice(User $user, Business $business, Counterparty $counterparty, array $attributes = []): int
    {
        $invoiceId = $this->createDraft($user, $business, $attributes + ['kind' => 'sale', 'counterparty_id' => $counterparty->id], 'S18');
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/issue")->assertOk();

        return $invoiceId;
    }

    private function import(User $user, Business $business, BankAccount $bank, string $csv): TestResponse
    {
        return $this->actingAs($user)->post(
            "/api/v1/businesses/{$business->id}/bank-accounts/{$bank->id}/statement-import",
            ['file' => UploadedFile::fake()->createWithContent('statement.csv', $csv)],
            ['Accept' => 'application/json'],
        );
    }

    private function lineUrl(Business $business, BankAccount $bank, BankTransaction $line, string $action): string
    {
        return "/api/v1/businesses/{$business->id}/bank-accounts/{$bank->id}/transactions/{$line->id}/{$action}";
    }

    /** @param list<array{int, string, string}> $lines */
    private function postedEntry(Business $business, string $date, array $lines): void
    {
        $entry = JournalEntry::query()->create(['business_id' => $business->id, 'accounting_period_id' => $business->accountingPeriods()->value('id'), 'entry_date' => $date, 'description' => 'Opening balance', 'status' => 'posted']);
        $entry->lines()->createMany(array_map(fn (array $line): array => ['business_id' => $business->id, 'account_id' => $line[0], 'debit' => $line[1], 'credit' => $line[2]], $lines));
    }

    private function sheetXml(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rep');
        file_put_contents($path, $contents);
        $zip = new \ZipArchive;
        $zip->open($path);
        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($path);

        return $xml;
    }
}
