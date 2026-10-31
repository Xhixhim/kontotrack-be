<?php

namespace Tests\Feature\Api\V1;

use App\Models\Account;
use App\Models\Business;
use App\Models\CashAccount;
use App\Models\Counterparty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBookkeepingFixtures;
use Tests\TestCase;
use ZipArchive;

class DocumentExportTest extends TestCase
{
    use CreatesBookkeepingFixtures, RefreshDatabase;

    public function test_returns_403_to_a_user_outside_the_business(): void
    {
        $business = Business::factory()->create();
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->get("/api/v1/businesses/{$business->id}/reports/financial/export?type=trial_balance&format=xlsx")->assertForbidden();
        $this->actingAs($outsider)->get("/api/v1/businesses/{$business->id}/invoices/1/pdf")->assertForbidden();
    }

    public function test_downloads_an_issued_invoice_as_pdf(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $this->mapPostingAccounts($business);
        $customer = Counterparty::factory()->for($business)->customer()->create(['nui' => '811234567']);
        $invoiceId = $this->createDraft($user, $business, ['kind' => 'sale', 'counterparty_id' => $customer->id], 'S18');
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/issue")->assertOk();
        $number = $business->invoices()->findOrFail($invoiceId)->invoice_number;

        $response = $this->actingAs($user)->get("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/pdf")->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('filename="Fature-'.preg_replace('/[^A-Za-z0-9._-]+/', '-', $number).'.pdf"', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_the_invoice_pdf_shows_payment_details_and_the_credited_invoice(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $this->mapPostingAccounts($business);
        $business->bankAccounts()->create(['bank_name' => 'Banka Test', 'iban' => 'XK051212012345678906', 'currency' => 'EUR', 'is_active' => true,
            'account_id' => Account::factory()->for($business)->ofType('asset')->create()->id]);
        $customer = Counterparty::factory()->for($business)->customer()->create();
        $invoiceId = $this->createDraft($user, $business, ['kind' => 'sale', 'counterparty_id' => $customer->id], 'S18');
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/issue")->assertOk();
        $creditNoteId = $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/credit-notes", [
            'issued_on' => '2026-06-20',
            'notes' => 'Price correction',
            'lines' => [['description' => 'Correction', 'quantity' => '1', 'unit_price' => '10.00', 'vat_code' => 'S18']],
        ])->assertCreated()->json('data.id');
        $invoice = $business->invoices()->findOrFail($invoiceId);
        $creditNote = $business->invoices()->with(['counterparty', 'lines', 'creditedInvoice'])->findOrFail($creditNoteId);

        $html = view('pdf.invoice', ['business' => $business, 'invoice' => $creditNote, 'vatBreakdown' => [], 'bankAccounts' => $business->bankAccounts()->get()])->render();
        $saleHtml = view('pdf.invoice', ['business' => $business, 'invoice' => $invoice->load(['counterparty', 'lines']), 'vatBreakdown' => [], 'bankAccounts' => $business->bankAccounts()->get()])->render();

        $this->assertStringContainsString('Notë krediti · Credit note', $html);
        $this->assertStringContainsString("{$invoice->invoice_number} · 15.06.2026", $html);
        $this->assertStringContainsString('IBAN XK051212012345678906', $saleHtml);
        $this->assertStringContainsString('118,00 EUR', $saleHtml);
    }

    public function test_exports_every_report_type_to_excel(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $this->mapPostingAccounts($business);
        $customer = Counterparty::factory()->for($business)->customer()->create(['name' => 'Klienti Ë']);
        $invoiceId = $this->createDraft($user, $business, ['kind' => 'sale', 'counterparty_id' => $customer->id], 'S18');
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/issue")->assertOk();
        $cashAccount = CashAccount::factory()->for($business)->create(['account_id' => Account::factory()->for($business)->ofType('asset')->create()->id]);
        $titles = [
            'trial_balance' => 'Bilanci provues', 'profit_loss' => 'Pasqyra e të ardhurave', 'balance_sheet' => 'Bilanci i gjendjes',
            'receivable_aging' => 'Mosha e borxheve', 'payable_aging' => 'Mosha e detyrimeve', 'vat_summary' => 'Përmbledhja e TVSH-së',
            'general_ledger' => 'Kartelat e llogarive', 'cash_book' => 'Libri i arkës',
        ];

        foreach ($titles as $type => $title) {
            $query = http_build_query(['type' => $type, 'format' => 'xlsx', 'from' => '2026-06-01', 'to' => '2026-06-30', 'cash_account_id' => $cashAccount->id]);
            $response = $this->actingAs($user)->get("/api/v1/businesses/{$business->id}/reports/financial/export?{$query}")->assertOk();

            $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('Content-Type'), $type);
            $this->assertStringContainsString($title, $this->sheetXml($response->getContent()), $type);
        }

        $vatSheet = $this->sheetXml($this->actingAs($user)->get("/api/v1/businesses/{$business->id}/reports/financial/export?type=vat_summary&format=xlsx&from=2026-06-01&to=2026-06-30")->getContent());
        $this->assertStringContainsString('Klienti Ë', $vatSheet);
        $this->assertStringContainsString('<v>18</v>', $vatSheet);
        $this->assertStringContainsString('15.06.2026', $vatSheet);
    }

    public function test_exports_a_report_to_pdf_and_rejects_unknown_formats(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();

        $response = $this->actingAs($user)->get("/api/v1/businesses/{$business->id}/reports/financial/export?type=trial_balance&format=pdf")->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());

        $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/reports/financial/export?type=trial_balance&format=docx")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['format']);
    }

    private function sheetXml(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rep');
        file_put_contents($path, $contents);
        $zip = new ZipArchive;
        $zip->open($path);
        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($path);

        return $xml;
    }
}
