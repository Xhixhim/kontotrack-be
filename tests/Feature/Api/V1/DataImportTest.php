<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Counterparty;
use App\Models\InventoryItem;
use App\Models\User;
use App\Support\SimpleXlsxWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class DataImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_customers_and_suppliers_from_a_csv_with_albanian_headers(): void
    {
        [$user, $business] = $this->bookkeeper();
        Counterparty::factory()->for($business)->customer()->create(['name' => 'Already here', 'nui' => '811000111']);
        $file = $this->csv("Emri;Lloji;NUI;Email;Telefoni;Komuna;Shteti\nDrini SH.P.K.;klient;811 000 222;info@drini.test;044111222;Prizren;\nFurnitori GmbH;Furnitor;DE123456789;;;;de\nArta Krasniqi;;1234567890;;;Pejë;\nAlready here;klient;811000111;;;;\n");

        $this->actingAs($user)->post("/api/v1/businesses/{$business->id}/imports/counterparties", ['file' => $file, 'default_type' => 'customer'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.created', 3)
            ->assertJsonCount(1, 'data.skipped')
            ->assertJsonPath('data.skipped.0.line', 5);

        $this->assertDatabaseHas('counterparties', ['business_id' => $business->id, 'name' => 'Drini SH.P.K.', 'type' => 'customer', 'nui' => '811000222', 'municipality' => 'Prizren', 'country_code' => 'XK']);
        $this->assertDatabaseHas('counterparties', ['business_id' => $business->id, 'name' => 'Furnitori GmbH', 'type' => 'supplier', 'nui' => 'DE123456789', 'country_code' => 'DE']);
        $this->assertDatabaseHas('counterparties', ['business_id' => $business->id, 'name' => 'Arta Krasniqi', 'type' => 'customer', 'nui' => '1234567890']);
        $this->assertSame(4, $business->counterparties()->count());
        $this->assertDatabaseHas('audit_logs', ['business_id' => $business->id, 'action' => 'import.completed', 'user_id' => $user->id]);
        $this->assertSame(3, $business->auditLogs()->where('action', 'counterparty.created')->count());
    }

    public function test_one_bad_row_means_nothing_is_imported_and_the_lines_are_named(): void
    {
        [$user, $business] = $this->bookkeeper();
        $file = $this->csv("Name,NUI,Email\nGood Shop,811000222,\nBad Number,12345,\n,811000333,\nBad Email,,not-an-email\nTwin,811000222,\n");

        $response = $this->actingAs($user)->post("/api/v1/businesses/{$business->id}/imports/counterparties", ['file' => $file], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rows');

        $rows = $response->json('errors.rows');
        $this->assertCount(4, $rows);
        $this->assertStringStartsWith('Line 3:', $rows[0]);
        $this->assertStringStartsWith('Line 4:', $rows[1]);
        $this->assertStringStartsWith('Line 5:', $rows[2]);
        $this->assertSame('Line 6: The NUI 811000222 is also on line 2.', $rows[3]);
        $this->assertSame(0, $business->counterparties()->count());
    }

    public function test_imports_stock_items_from_an_xlsx_file(): void
    {
        [$user, $business] = $this->bookkeeper();
        InventoryItem::factory()->for($business)->create(['sku' => 'KAFE-1']);
        $path = tempnam(sys_get_temp_dir(), 'kt-import').'.xlsx';
        file_put_contents($path, app(SimpleXlsxWriter::class)->build('Artikujt', [
            ['Kodi', 'Emri', 'Njësia', 'Çmimi i blerjes', 'Çmimi i shitjes', 'Minimumi', 'Barkodi'],
            ['KAFE-1', 'Kafe 1 kg', 'copë', 8.5, 12, 10, null],
            ['SHEQER-1', 'Sheqer 1 kg', 'kg', '0,95', '1,40', 25, '3850000000017'],
            ['UJE-05', 'Ujë 0.5 l', null, 0.2, 0.5, null, null],
        ]));

        $this->actingAs($user)->post("/api/v1/businesses/{$business->id}/imports/inventory-items", ['file' => new UploadedFile($path, 'artikujt.xlsx', null, null, true)], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.created', 2)
            ->assertJsonCount(1, 'data.skipped');

        $this->assertDatabaseHas('inventory_items', ['business_id' => $business->id, 'sku' => 'SHEQER-1', 'unit' => 'kg', 'barcode' => '3850000000017', 'purchase_price' => '0.9500', 'sale_price' => '1.4000', 'minimum_quantity' => '25.0000']);
        $this->assertDatabaseHas('inventory_items', ['business_id' => $business->id, 'sku' => 'UJE-05', 'unit' => 'copë', 'minimum_quantity' => '0.0000']);
        @unlink($path);
    }

    public function test_rejects_a_file_without_the_required_columns_with_422(): void
    {
        [$user, $business] = $this->bookkeeper();

        $this->actingAs($user)->post("/api/v1/businesses/{$business->id}/imports/inventory-items", ['file' => $this->csv("Emri;Çmimi\nKafe;8,50\n")], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
        $this->actingAs($user)->post("/api/v1/businesses/{$business->id}/imports/counterparties", ['file' => $this->csv("Emri;NUI\n")], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'The file has a header row but no data rows.']);
    }

    public function test_rejects_a_barcode_that_is_already_used_with_422(): void
    {
        [$user, $business] = $this->bookkeeper();
        InventoryItem::factory()->for($business)->create(['sku' => 'OLD', 'barcode' => '111']);

        $this->actingAs($user)->post("/api/v1/businesses/{$business->id}/imports/inventory-items", ['file' => $this->csv("Kodi;Emri;Barkodi\nNEW-1;Artikull;111\n")], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.rows.0', 'Line 2: The barcode 111 already belongs to another item.');
    }

    public function test_only_bookkeepers_import_and_unknown_types_do_not_exist(): void
    {
        [$user, $business] = $this->bookkeeper();
        $auditor = User::factory()->create();
        $business->users()->attach($auditor, ['role' => 'auditor', 'status' => 'active']);

        $this->actingAs($auditor)->post("/api/v1/businesses/{$business->id}/imports/counterparties", ['file' => $this->csv("Emri\nX\n")], ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($user)->post("/api/v1/businesses/{$business->id}/imports/invoices", ['file' => $this->csv("Emri\nX\n")], ['Accept' => 'application/json'])->assertNotFound();
        $this->actingAs($auditor)->get("/api/v1/businesses/{$business->id}/imports/counterparties/template")
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="klientet-furnitoret-shabllon.csv"');
    }

    public function test_the_template_can_be_imported_as_it_is(): void
    {
        [$user, $business] = $this->bookkeeper();

        foreach (['counterparties', 'inventory-items'] as $type) {
            $template = $this->actingAs($user)->get("/api/v1/businesses/{$business->id}/imports/{$type}/template")->assertOk()->getContent();
            $this->actingAs($user)->post("/api/v1/businesses/{$business->id}/imports/{$type}", ['file' => $this->csv($template)], ['Accept' => 'application/json'])
                ->assertOk()
                ->assertJsonPath('data.created', 1);
        }
    }

    private function csv(string $contents): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('import.csv', $contents);
    }

    /** @return array{User, Business} */
    private function bookkeeper(): array
    {
        $user = User::factory()->create();
        $business = Business::factory()->create();
        $business->users()->attach($user, ['role' => 'accountant', 'status' => 'active']);

        return [$user, $business];
    }
}
