<?php

namespace Tests\Feature\Api\V1;

use App\Models\Account;
use App\Models\Business;
use App\Models\CashAccount;
use App\Models\Counterparty;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesBookkeepingFixtures;
use Tests\TestCase;

class FinancialReportTest extends TestCase
{
    use CreatesBookkeepingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-07-20 10:00:00');
    }

    public function test_trial_balance_lists_every_posted_balance_and_balances(): void
    {
        [$user, $business, $accounts, $cash] = $this->booksWithActivity();

        $response = $this->report($user, $business, 'type=trial_balance&from=2026-01-01&to=2026-06-30')->assertOk();

        $rows = collect($response->json('data.rows'))->keyBy('code');
        $this->assertSame(['118.00', '0.00'], [$rows[$cash->account->code]['debit'], $rows[$cash->account->code]['credit']]);
        $this->assertSame(['0.00', '100.00'], [$rows[$accounts['sales_revenue']->code]['debit'], $rows[$accounts['sales_revenue']->code]['credit']]);
        $this->assertSame(['100.00', '0.00'], [$rows[$accounts['purchase_expense']->code]['debit'], $rows[$accounts['purchase_expense']->code]['credit']]);
        $response->assertJsonPath('data.totals.debit', '218.00')->assertJsonPath('data.totals.credit', '218.00');
    }

    public function test_profit_and_loss_covers_only_the_selected_dates(): void
    {
        [$user, $business] = $this->booksWithActivity();

        $this->report($user, $business, 'type=profit_loss&from=2026-06-01&to=2026-06-30')
            ->assertOk()
            ->assertJsonPath('data.totals.result', '100.00');
        $this->report($user, $business, 'type=profit_loss&from=2026-05-01&to=2026-06-30')
            ->assertOk()
            ->assertJsonPath('data.totals.result', '0.00');
    }

    public function test_account_card_shows_opening_balance_running_lines_and_closing_balance(): void
    {
        [$user, $business, $accounts] = $this->booksWithActivity();
        $receivables = $accounts['accounts_receivable'];

        $card = $this->report($user, $business, "type=general_ledger&account_id={$receivables->id}&from=2026-06-01&to=2026-06-30")
            ->assertOk()
            ->assertJsonCount(1, 'data.accounts')
            ->json('data.accounts.0');
        $this->assertSame('0.00', $card['opening_balance']);
        $this->assertSame([['118.00', '0.00', '118.00'], ['0.00', '118.00', '0.00']], array_map(fn (array $line): array => [$line['debit'], $line['credit'], $line['balance']], $card['lines']));
        $this->assertSame('0.00', $card['closing_balance']);

        $this->report($user, $business, "type=general_ledger&account_id={$receivables->id}&from=2026-06-16&to=2026-06-30")
            ->assertJsonPath('data.accounts.0.opening_balance', '118.00')
            ->assertJsonCount(1, 'data.accounts.0.lines');
    }

    public function test_general_ledger_without_an_account_lists_every_account_with_activity(): void
    {
        [$user, $business] = $this->booksWithActivity();

        $this->report($user, $business, 'type=general_ledger&from=2026-01-01&to=2026-06-30')
            ->assertOk()
            ->assertJsonCount(6, 'data.accounts');
    }

    public function test_cash_book_shows_money_in_and_out_with_the_closing_balance(): void
    {
        [$user, $business, , $cash] = $this->booksWithActivity();

        $this->report($user, $business, "type=cash_book&cash_account_id={$cash->id}&from=2026-06-01&to=2026-06-30")
            ->assertOk()
            ->assertJsonPath('data.opening_balance', '0.00')
            ->assertJsonPath('data.money_in', '118.00')
            ->assertJsonPath('data.money_out', '0.00')
            ->assertJsonPath('data.closing_balance', '118.00')
            ->assertJsonPath('data.lines.0.money_in', '118.00');
    }

    public function test_rejects_accounts_and_cash_accounts_of_another_business_with_422(): void
    {
        [$user, $business] = $this->booksWithActivity();
        $foreignAccount = Account::factory()->create();
        $foreignCash = CashAccount::factory()->create();

        $this->report($user, $business, "type=general_ledger&account_id={$foreignAccount->id}")
            ->assertUnprocessable()->assertJsonValidationErrors(['account_id' => 'Choose an account from this business.']);
        $this->report($user, $business, "type=cash_book&cash_account_id={$foreignCash->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('cash_account_id');
    }

    /** @return array{User, Business, array<string, Account>, CashAccount} */
    private function booksWithActivity(): array
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $accounts = $this->mapPostingAccounts($business);
        $cash = CashAccount::factory()->for($business)->create(['account_id' => Account::factory()->for($business)->ofType('asset')->create()->id]);
        $cash->load('account');
        $customer = Counterparty::factory()->for($business)->customer()->create();
        $supplier = Counterparty::factory()->for($business)->supplier()->create();

        $saleId = $this->createDraft($user, $business, ['kind' => 'sale', 'counterparty_id' => $customer->id], 'S18');
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$saleId}/issue")->assertOk();
        $billId = $this->createDraft($user, $business, [
            'kind' => 'purchase',
            'counterparty_id' => $supplier->id,
            'invoice_number' => 'B-1',
            'issued_on' => '2026-05-10',
            'document_id' => Document::factory()->for($business)->invoice()->create()->id,
        ], 'EX');
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$billId}/issue")->assertOk();
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/payments", [
            'cash_account_id' => $cash->id,
            'document_id' => Document::factory()->for($business)->create()->id,
            'paid_on' => '2026-06-20',
            'direction' => 'in',
            'amount' => '118.00',
            'allocations' => [['invoice_id' => $saleId, 'amount' => '118.00']],
        ])->assertCreated();

        return [$user, $business, $accounts, $cash];
    }

    private function report(User $user, Business $business, string $query): TestResponse
    {
        return $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/reports/financial?{$query}");
    }
}
