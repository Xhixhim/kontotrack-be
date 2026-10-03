<?php

namespace Tests\Feature\Api\V1;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Business;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

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
        return $this->actingAs($user)->post(
            "/api/v1/businesses/{$business->id}/bank-accounts/{$bank->id}/statement-import",
            ['file' => UploadedFile::fake()->createWithContent('statement.csv', $csv)],
            ['Accept' => 'application/json'],
        );
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
