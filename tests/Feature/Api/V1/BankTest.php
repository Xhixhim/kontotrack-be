<?php

namespace Tests\Feature\Api\V1;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Business;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\SimpleXlsxWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use ZipArchive;

class BankTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_a_csv_statement_in_local_number_and_date_formats(): void
    {
        [$user, $business, $bank] = $this->bankSetup();

        $this->import($user, $business, $bank, "date,description,amount,external_id\n15.06.2026,Pagesa nga Market Arbëri,\"1.250,50\",TX-1\n2026-06-16,Qiraja,-300,TX-2\n")
            ->assertSuccessful();

        $this->assertSame(['1250.50', '-300.00'], BankTransaction::query()->orderBy('id')->pluck('amount')->map(fn ($amount): string => (string) $amount)->all());
        $this->assertSame('2026-06-15', BankTransaction::query()->orderBy('id')->first()->transaction_date->toDateString());
    }

    public function test_rejects_reimporting_the_same_external_ids_with_422(): void
    {
        [$user, $business, $bank] = $this->bankSetup();
        $csv = "date,description,amount,external_id\n2026-06-15,Pagesa,100,TX-1\n";
        $this->import($user, $business, $bank, $csv)->assertSuccessful();

        $this->import($user, $business, $bank, $csv)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'Some external IDs have already been imported for this bank account.']);
        $this->assertSame(1, BankTransaction::query()->count());
    }

    public function test_rejects_a_statement_without_the_required_columns_with_422(): void
    {
        [$user, $business, $bank] = $this->bankSetup();

        $this->import($user, $business, $bank, "date,text,amount\n2026-06-15,Pagesa,100\n")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => "The statement must contain one 'description' column."]);
    }

    public function test_imports_a_bank_xlsx_export_with_title_rows_and_debit_credit_columns(): void
    {
        [$user, $business, $bank] = $this->bankSetup();
        // Laid out like a Kosovo bank export: account details above the header, Albanian column
        // names, shared strings, real Excel dates, separate Debi/Kredi columns and a totals row.
        $workbook = $this->bankWorkbook([
            1 => ['Pasqyra e llogarisë', null, null, null, null],
            2 => ['IBAN: XK051212012345678906', null, null, null, null],
            4 => ['Data e transaksionit', 'Përshkrimi', 'Debi', 'Kredi', 'Saldo'],
            5 => [46188.0, 'Pagesa nga Market Arbëri', null, 1250.5, 1250.5],
            6 => [46189.0, 'Qiraja qershor', -300.0, 0.0, 950.5],
            7 => ['17.06.2026', 'Komisioni bankar', 1.2, null, 949.3],
            9 => ['Totali', null, 301.2, 1250.5, null],
        ]);

        $this->importFile($user, $business, $bank, $workbook, 'pasqyra.xlsx')
            ->assertCreated()
            ->assertJsonPath('meta.imported_count', 3);

        $transactions = BankTransaction::query()->orderBy('id')->get();
        $this->assertSame(['1250.50', '-300.00', '-1.20'], $transactions->map(fn ($transaction): string => (string) $transaction->amount)->all());
        $this->assertSame(['2026-06-15', '2026-06-16', '2026-06-17'], $transactions->map(fn ($transaction): string => $transaction->transaction_date->toDateString())->all());
        $this->assertSame('Pagesa nga Market Arbëri', $transactions->first()->description);
        $this->assertSame('pasqyra.xlsx', $transactions->first()->document->original_name);
    }

    public function test_imports_an_xlsx_with_one_amount_column_and_text_dates(): void
    {
        [$user, $business, $bank] = $this->bankSetup();
        $workbook = (new SimpleXlsxWriter)->build('Statement', [
            ['Data', 'Përshkrimi', 'Shuma (EUR)', 'External ID'],
            ['15.06.2026', 'Arkëtim', '1.250,50', 'TX-1'],
            ['2026-06-16', 'Pagesë furnitori', -45.99, 'TX-2'],
        ]);

        $this->importFile($user, $business, $bank, $workbook, 'statement.xlsx')->assertCreated();

        $this->assertSame(['1250.50', '-45.99'], BankTransaction::query()->orderBy('id')->pluck('amount')->map(fn ($amount): string => (string) $amount)->all());
        $this->assertSame(['TX-1', 'TX-2'], BankTransaction::query()->orderBy('id')->pluck('external_id')->all());
    }

    public function test_imports_a_semicolon_csv_in_windows_1252_with_debit_and_credit_columns(): void
    {
        [$user, $business, $bank] = $this->bankSetup();
        $csv = mb_convert_encoding("Data;Përshkrimi;Debi;Kredi\n15.06.2026;Pagesë nga Çlirimi;;100,00\n16.06.2026;Qiraja;300,00;\n", 'Windows-1252', 'UTF-8');

        $this->import($user, $business, $bank, $csv)->assertCreated();

        $this->assertSame(['100.00', '-300.00'], BankTransaction::query()->orderBy('id')->pluck('amount')->map(fn ($amount): string => (string) $amount)->all());
        $this->assertSame('Pagesë nga Çlirimi', BankTransaction::query()->orderBy('id')->first()->description);
    }

    public function test_rejects_a_row_with_both_debit_and_credit_and_files_that_are_not_workbooks(): void
    {
        [$user, $business, $bank] = $this->bankSetup();

        $this->import($user, $business, $bank, "Data,Përshkrimi,Debi,Kredi\n15.06.2026,Gabim,10,20\n")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'Statement row 2 has an invalid date, description, amount, or external ID.']);
        $this->importFile($user, $business, $bank, 'not a zip file', 'pasqyra.xlsx')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);
        $this->importFile($user, $business, $bank, "\xD0\xCF\x11\xE0old excel", 'pasqyra.xls')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'Upload the bank statement as .xlsx or .csv. Old .xls files must be saved as .xlsx first.']);
        $this->assertSame(0, BankTransaction::query()->count());
    }

    public function test_reconciles_a_transaction_with_a_matching_posted_entry_only(): void
    {
        [$user, $business, $bank, $period] = $this->bankSetup();
        $this->import($user, $business, $bank, "date,description,amount,external_id\n2026-06-15,Pagesa,100,TX-1\n")->assertSuccessful();
        $transaction = BankTransaction::query()->sole();
        $matching = $this->postedEntry($business, $period, $bank->account_id, '100.00', '2026-06-15');
        $wrongAmount = $this->postedEntry($business, $period, $bank->account_id, '90.00', '2026-06-15');

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/bank-accounts/{$bank->id}/transactions/{$transaction->id}/reconcile", ['journal_entry_id' => $wrongAmount->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['journal_entry_id' => 'The journal entry movement does not match this bank transaction amount and direction.']);
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/bank-accounts/{$bank->id}/transactions/{$transaction->id}/reconcile", ['journal_entry_id' => $matching->id])
            ->assertOk();

        $this->assertTrue($transaction->refresh()->is_reconciled);
    }

    /** @return array{User, Business, BankAccount, AccountingPeriod} */
    private function bankSetup(): array
    {
        $user = User::factory()->create();
        $business = Business::factory()->create();
        $business->users()->attach($user, ['role' => 'accountant', 'status' => 'active']);
        $period = AccountingPeriod::factory()->for($business)->create(['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $bank = BankAccount::factory()->for($business)->create(['account_id' => Account::factory()->for($business)->ofType('asset')->create()->id]);

        return [$user, $business, $bank, $period];
    }

    private function import(User $user, Business $business, BankAccount $bank, string $csv): TestResponse
    {
        return $this->importFile($user, $business, $bank, $csv, 'statement.csv');
    }

    private function importFile(User $user, Business $business, BankAccount $bank, string $contents, string $filename): TestResponse
    {
        return $this->actingAs($user)->post(
            "/api/v1/businesses/{$business->id}/bank-accounts/{$bank->id}/statement-import",
            ['file' => UploadedFile::fake()->createWithContent($filename, $contents)],
            ['Accept' => 'application/json'],
        );
    }

    /**
     * A minimal workbook written the way Excel does it: text in sharedStrings.xml, numbers in <v>.
     *
     * @param  array<int, list<string|float|null>>  $rows  keyed by spreadsheet row number
     */
    private function bankWorkbook(array $rows): string
    {
        $strings = [];
        $sheetRows = '';
        foreach ($rows as $number => $values) {
            $cells = '';
            foreach ($values as $index => $value) {
                $reference = chr(65 + $index).$number;
                if (is_float($value)) {
                    $cells .= "<c r=\"{$reference}\"><v>{$value}</v></c>";
                } elseif ($value !== null) {
                    $strings[] = htmlspecialchars($value, ENT_XML1);
                    $cells .= "<c r=\"{$reference}\" t=\"s\"><v>".(count($strings) - 1).'</v></c>';
                }
            }
            $sheetRows .= "<row r=\"{$number}\">{$cells}</row>";
        }

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Pasqyra" sheetId="1" r:id="rId7"/></sheets></workbook>');
        // The sheet is deliberately not sheet1.xml, so the reader has to follow the relationship.
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId7" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/></Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.implode('', array_map(fn (string $text): string => "<si><t>{$text}</t></si>", $strings)).'</sst>');
        $zip->addFromString('xl/worksheets/sheet2.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$sheetRows.'</sheetData></worksheet>');
        $zip->close();
        $contents = (string) file_get_contents($path);
        unlink($path);

        return $contents;
    }

    private function postedEntry(Business $business, AccountingPeriod $period, int $bankLedgerId, string $amount, string $date): JournalEntry
    {
        $entry = JournalEntry::query()->create(['business_id' => $business->id, 'accounting_period_id' => $period->id, 'entry_date' => $date, 'description' => 'Receipt', 'status' => 'posted']);
        $entry->lines()->createMany([
            ['business_id' => $business->id, 'account_id' => $bankLedgerId, 'debit' => $amount, 'credit' => '0.00'],
            ['business_id' => $business->id, 'account_id' => Account::factory()->for($business)->ofType('revenue')->create()->id, 'debit' => '0.00', 'credit' => $amount],
        ]);

        return $entry;
    }
}
