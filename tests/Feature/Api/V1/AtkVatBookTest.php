<?php

namespace Tests\Feature\Api\V1;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\ComplianceRule;
use App\Models\Counterparty;
use App\Models\Document;
use App\Models\User;
use App\Services\Tax\AtkVatBoxes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesBookkeepingFixtures;
use Tests\TestCase;
use ZipArchive;

class AtkVatBookTest extends TestCase
{
    use CreatesBookkeepingFixtures, RefreshDatabase;

    public function test_returns_403_to_a_user_outside_the_business(): void
    {
        $business = Business::factory()->vatRegistered()->create();

        $this->actingAs(User::factory()->create())
            ->getJson("/api/v1/businesses/{$business->id}/atk/vat-books?period=2026-06")
            ->assertForbidden();
    }

    public function test_refuses_a_business_that_is_not_vat_registered(): void
    {
        $user = User::factory()->create();
        $business = Business::factory()->create(['vat_status' => 'not_registered']);
        $business->users()->attach($user, ['role' => 'accountant', 'status' => 'active']);

        $this->actingAs($user)
            ->getJson("/api/v1/businesses/{$business->id}/atk/vat-books?period=2026-06")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['period' => 'The VAT sales and purchase books apply only to VAT-registered businesses.']);
    }

    public function test_builds_both_books_and_the_vat_difference_for_a_month(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $this->mapPostingAccounts($business);
        $customer = Counterparty::factory()->for($business)->customer()->create(['name' => 'Klienti SH.P.K.', 'nui' => '811234567']);
        $supplier = Counterparty::factory()->for($business)->supplier()->create(['nui' => '600000001']);
        $this->issued($user, $business, ['kind' => 'sale', 'counterparty_id' => $customer->id], 'S18');
        $this->issued($user, $business, $this->purchase($business, $supplier, 'B-1'), 'S18');

        $data = $this->books($user, $business, '2026-06')->json('data');

        $this->assertSame([], $data['problems']);
        $this->assertSame('15.06.2026', $data['sales']['rows'][0]['date']);
        $this->assertSame('Klienti SH.P.K.', $data['sales']['rows'][0]['name']);
        $this->assertSame('811234567', $data['sales']['rows'][0]['identifier']);
        $this->assertSame(['100.00', '18.00', '18.00'], [$data['sales']['totals']['12'], $data['sales']['totals']['K1'], $data['sales']['totals']['30']]);
        $this->assertSame('600000001', $data['purchases']['rows'][0]['identifier']);
        $this->assertSame(['100.00', '18.00', '18.00'], [$data['purchases']['totals']['43'], $data['purchases']['totals']['K1'], $data['purchases']['totals']['67']]);
        $this->assertSame(['output_vat' => '18.00', 'input_vat' => '18.00', 'difference' => '0.00'], $data['vat_return']);
    }

    public function test_credit_notes_and_later_cancellations_go_to_the_opposite_book_and_same_month_cancellations_drop_out(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $this->mapPostingAccounts($business);
        $customer = Counterparty::factory()->for($business)->customer()->create(['nui' => '811234567']);
        $creditedSale = $this->issued($user, $business, ['kind' => 'sale', 'counterparty_id' => $customer->id], 'S18');
        $creditNoteId = $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$creditedSale}/credit-notes", [
            'issued_on' => '2026-06-20',
            'notes' => 'Price correction',
            'lines' => [['description' => 'Price correction', 'quantity' => '1', 'unit_price' => '40.00', 'vat_code' => 'S18']],
        ])->assertCreated()->json('data.id');
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$creditNoteId}/issue")->assertOk();
        $maySale = $this->issued($user, $business, ['kind' => 'sale', 'counterparty_id' => $customer->id, 'issued_on' => '2026-05-10'], 'S18');
        $this->cancel($user, $business, $maySale, '2026-06-05');
        $juneSale = $this->issued($user, $business, ['kind' => 'sale', 'counterparty_id' => $customer->id, 'issued_on' => '2026-06-02'], 'S18');
        $this->cancel($user, $business, $juneSale, '2026-06-03');

        $data = $this->books($user, $business, '2026-06')->json('data');

        $this->assertCount(1, $data['sales']['rows']);
        $this->assertSame('100.00', $data['sales']['totals']['12']);
        $this->assertCount(2, $data['purchases']['rows']);
        $this->assertSame(['140.00', '25.20'], [$data['purchases']['totals']['53'], $data['purchases']['totals']['67']]);
        $this->assertSame('-7.20', $data['vat_return']['difference']);
    }

    public function test_a_reverse_charge_purchase_from_abroad_appears_in_both_books(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $this->mapPostingAccounts($business);
        $rule = ComplianceRule::query()->where('rule_key', 'vat_codes')->sole();
        $rule->update(['configuration' => ['codes' => [
            ...$rule->configuration['codes'],
            ['code' => 'RC', 'label' => 'Reverse charge (test fixture)', 'rate_percent' => '0', 'treatment' => 'exempt', 'atk_sales_box' => '10b', 'atk_purchase_box' => '65'],
        ]]]);
        $supplier = Counterparty::factory()->for($business)->supplier()->create(['nui' => null, 'country_code' => 'DE']);
        $this->issued($user, $business, $this->purchase($business, $supplier, 'INV-77'), 'RC');

        $data = $this->books($user, $business, '2026-06')->json('data');

        $this->assertSame([], $data['problems']);
        $this->assertSame(['11', '100.00', '18.00'], [$data['purchases']['rows'][0]['identifier'], $data['purchases']['totals']['65'], $data['purchases']['totals']['67']]);
        $this->assertSame(['6', '100.00', '18.00'], [$data['sales']['rows'][0]['identifier'], $data['sales']['totals']['28'], $data['sales']['totals']['30']]);
        $this->assertSame('0.00', $data['vat_return']['difference']);
    }

    public function test_an_empty_month_is_declared_with_zero(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();

        $data = $this->books($user, $business, '2026-03')->json('data');

        $this->assertTrue($data['sales']['declared_with_zero']);
        $this->assertSame(['31.03.2026', '0'], [$data['sales']['rows'][0]['date'], $data['sales']['rows'][0]['identifier']]);
        $this->assertTrue($data['purchases']['declared_with_zero']);
    }

    public function test_reports_rows_that_atk_would_reject_for_a_missing_identifier(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $this->mapPostingAccounts($business);
        $customer = Counterparty::factory()->for($business)->customer()->create(['name' => 'Pa NUI', 'nui' => null]);
        $this->issued($user, $business, ['kind' => 'sale', 'counterparty_id' => $customer->id], 'S18');

        $problems = $this->books($user, $business, '2026-06')->json('data.problems');

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('Pa NUI needs a NUI', $problems[0]);
    }

    public function test_downloads_the_sales_book_in_the_atk_template_layout(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $this->mapPostingAccounts($business);
        $customer = Counterparty::factory()->for($business)->customer()->create(['nui' => '811234567']);
        $this->issued($user, $business, ['kind' => 'sale', 'counterparty_id' => $customer->id], 'S18');

        $response = $this->actingAs($user)->get("/api/v1/businesses/{$business->id}/atk/vat-books/sales.xlsx?period=2026-06")->assertOk();

        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('Content-Type'));
        $this->assertStringContainsString("Libri-i-Shitjes-{$business->nui}-2026-06.xlsx", (string) $response->headers->get('Content-Disposition'));
        $sheet = $this->sheetXml($response->getContent());
        $this->assertStringContainsString('<c r="M3" t="inlineStr" s="1"><is><t xml:space="preserve">[12]</t>', $sheet);
        $this->assertStringContainsString('<c r="E4" t="inlineStr"><is><t xml:space="preserve">811234567</t>', $sheet);
        $this->assertStringContainsString('<c r="M4" s="2"><v>100</v></c>', $sheet);
        $this->assertStringContainsString('<c r="R4" s="2"><f>(M4+N4+O4+P4+Q4)*18%</f><v>18</v></c>', $sheet);
        $this->assertTrue(AuditLog::query()->where('business_id', $business->id)->where('action', 'atk.vat_book_exported')->exists());
    }

    public function test_vat_code_boxes_must_fit_the_code_treatment_and_rate(): void
    {
        $this->assertSame([], AtkVatBoxes::problems(['code' => 'S18', 'treatment' => 'taxable', 'rate_percent' => '18', 'atk_sales_box' => '12', 'atk_purchase_box' => '47']));
        $this->assertSame(
            ['VAT code S18: box 11 does not fit a taxable code at 18%.'],
            AtkVatBoxes::problems(['code' => 'S18', 'treatment' => 'taxable', 'rate_percent' => '18', 'atk_sales_box' => '11']),
        );
        $this->assertSame(
            ['VAT code X: atk_purchase_box must be one of 31, 32, 33, 34, 35, 39, 43, 47, 37, 41, 45, 49, 51, 65.'],
            AtkVatBoxes::problems(['code' => 'X', 'treatment' => 'exempt', 'rate_percent' => '0', 'atk_purchase_box' => '99']),
        );
    }

    /** @param array<string, mixed> $attributes */
    private function issued(User $user, Business $business, array $attributes, string $vatCode): int
    {
        $invoiceId = $this->createDraft($user, $business, $attributes, $vatCode);
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/issue")->assertOk();

        return $invoiceId;
    }

    /** @return array<string, mixed> */
    private function purchase(Business $business, Counterparty $supplier, string $number): array
    {
        return [
            'kind' => 'purchase',
            'counterparty_id' => $supplier->id,
            'invoice_number' => $number,
            'document_id' => Document::factory()->for($business)->invoice()->create()->id,
        ];
    }

    private function cancel(User $user, Business $business, int $invoiceId, string $date): void
    {
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/cancel", [
            'cancelled_on' => $date,
            'cancellation_reason' => 'Issued in error',
        ])->assertOk();
    }

    private function books(User $user, Business $business, string $period): TestResponse
    {
        return $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/atk/vat-books?period={$period}")->assertOk();
    }

    private function sheetXml(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'atk');
        file_put_contents($path, $contents);
        $zip = new ZipArchive;
        $zip->open($path);
        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($path);

        return $xml;
    }
}
