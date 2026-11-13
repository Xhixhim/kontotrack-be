<?php

namespace Tests\Feature\Api\V1;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Business;
use App\Models\BusinessPostingAccount;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class YearEndCloseTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Business $business;

    /** @var array<string, Account> */
    private array $accounts;

    private AccountingPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-01-20 10:00:00');
        $this->user = User::factory()->create();
        $this->business = Business::factory()->create();
        $this->business->users()->attach($this->user, ['role' => 'accountant', 'status' => 'active']);
        $this->period = AccountingPeriod::factory()->for($this->business)->create(['name' => 'FY 2025', 'starts_on' => '2025-01-01', 'ends_on' => '2025-12-31', 'status' => 'open']);
        AccountingPeriod::factory()->for($this->business)->create(['name' => 'FY 2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open']);

        foreach (['bank' => 'asset', 'sales' => 'revenue', 'rent' => 'expense', 'salaries' => 'expense', 'retained' => 'equity'] as $key => $type) {
            $this->accounts[$key] = Account::factory()->for($this->business)->ofType($type)->create(['name' => ucfirst($key)]);
        }

        BusinessPostingAccount::factory()->create(['business_id' => $this->business->id, 'purpose' => 'retained_earnings', 'account_id' => $this->accounts['retained']->id]);
    }

    public function test_preview_shows_the_result_of_the_year(): void
    {
        $this->post2025('bank', 'sales', '1000.00');
        $this->post2025('rent', 'bank', '300.00');
        $this->post2025('salaries', 'bank', '450.00');

        $this->actingAs($this->user)->getJson("/api/v1/businesses/{$this->business->id}/year-end/2025")
            ->assertOk()
            ->assertJsonPath('data.revenue', '1000.00')
            ->assertJsonPath('data.expenses', '750.00')
            ->assertJsonPath('data.result', '250.00')
            ->assertJsonPath('data.closed', false)
            ->assertJsonPath('data.blockers', [])
            ->assertJsonCount(3, 'data.accounts');
    }

    public function test_closing_moves_the_profit_to_retained_earnings_and_keeps_the_profit_and_loss_report(): void
    {
        $this->post2025('bank', 'sales', '1000.00');
        $this->post2025('rent', 'bank', '300.00');

        $entryId = $this->actingAs($this->user)->postJson("/api/v1/businesses/{$this->business->id}/year-end/2025/close")
            ->assertCreated()
            ->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.entry_type', 'year_end_closing')
            ->assertJsonPath('data.entry_date', '2025-12-31')
            ->json('data.id');

        $lines = JournalEntry::query()->findOrFail($entryId)->lines->mapWithKeys(fn ($line): array => [$line->account_id => [$line->debit, $line->credit]]);
        $this->assertEquals(['1000.00', '0.00'], $lines[$this->accounts['sales']->id]);
        $this->assertEquals(['0.00', '300.00'], $lines[$this->accounts['rent']->id]);
        $this->assertEquals(['0.00', '700.00'], $lines[$this->accounts['retained']->id]);

        $base = "/api/v1/businesses/{$this->business->id}/reports/financial";
        $this->actingAs($this->user)->getJson("{$base}?type=profit_loss&from=2025-01-01&to=2025-12-31")->assertOk()->assertJsonPath('data.totals.result', '700.00');
        $balanceSheet = $this->actingAs($this->user)->getJson("{$base}?type=balance_sheet&to=2026-01-20")->assertOk()->json('data.rows');
        $this->assertEqualsCanonicalizing(['Bank', 'Retained'], array_column($balanceSheet, 'name'));
        $this->assertSame('700.00', collect($balanceSheet)->firstWhere('name', 'Retained')['credit']);
        $trialBalance = $this->actingAs($this->user)->getJson("{$base}?type=trial_balance&to=2026-01-20")->assertOk()->json('data.rows');
        $this->assertEqualsCanonicalizing(['Bank', 'Retained'], array_column($trialBalance, 'name'));

        $this->actingAs($this->user)->getJson("/api/v1/businesses/{$this->business->id}/year-end/2025")
            ->assertJsonPath('data.closed', true)
            ->assertJsonPath('data.closing_entry_id', $entryId)
            ->assertJsonPath('data.result', '700.00');
        $this->assertDatabaseHas('audit_logs', ['business_id' => $this->business->id, 'action' => 'year_end.closed', 'subject_id' => $entryId, 'user_id' => $this->user->id]);
    }

    public function test_entries_on_the_last_day_of_the_year_are_included(): void
    {
        $this->post2025('bank', 'sales', '1000.00');
        $entryId = $this->actingAs($this->user)->postJson("/api/v1/businesses/{$this->business->id}/journal-entries", [
            'accounting_period_id' => $this->period->id,
            'entry_date' => '2025-12-31',
            'description' => 'Last-day sale',
            'lines' => [
                ['account_id' => $this->accounts['bank']->id, 'debit' => '200.00', 'credit' => '0.00'],
                ['account_id' => $this->accounts['sales']->id, 'debit' => '0.00', 'credit' => '200.00'],
            ],
        ])->assertCreated()->json('data.id');
        $this->actingAs($this->user)->postJson("/api/v1/businesses/{$this->business->id}/journal-entries/{$entryId}/post")->assertOk();

        $this->actingAs($this->user)->getJson("/api/v1/businesses/{$this->business->id}/year-end/2025")->assertJsonPath('data.result', '1200.00');
    }

    public function test_a_loss_is_debited_to_retained_earnings(): void
    {
        $this->post2025('bank', 'sales', '100.00');
        $this->post2025('rent', 'bank', '400.00');

        $entryId = $this->actingAs($this->user)->postJson("/api/v1/businesses/{$this->business->id}/year-end/2025/close")->assertCreated()->json('data.id');

        $retained = JournalEntry::query()->findOrFail($entryId)->lines->firstWhere('account_id', $this->accounts['retained']->id);
        $this->assertSame('300.00', $retained->debit);
    }

    public function test_a_year_cannot_be_closed_twice_but_can_be_closed_again_after_a_reversal(): void
    {
        $this->post2025('bank', 'sales', '1000.00');
        $entryId = $this->actingAs($this->user)->postJson("/api/v1/businesses/{$this->business->id}/year-end/2025/close")->assertCreated()->json('data.id');

        $this->actingAs($this->user)->postJson("/api/v1/businesses/{$this->business->id}/year-end/2025/close")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('year');

        $reversalId = $this->actingAs($this->user)->postJson("/api/v1/businesses/{$this->business->id}/journal-entries/{$entryId}/reverse", ['accounting_period_id' => $this->period->id, 'entry_date' => '2025-12-31'])
            ->assertCreated()
            ->json('data.id');
        $this->actingAs($this->user)->postJson("/api/v1/businesses/{$this->business->id}/journal-entries/{$reversalId}/post")->assertOk();

        $this->actingAs($this->user)->getJson("/api/v1/businesses/{$this->business->id}/year-end/2025")
            ->assertJsonPath('data.closed', false)
            ->assertJsonPath('data.result', '1000.00')
            ->assertJsonPath('data.blockers', []);
        $this->actingAs($this->user)->postJson("/api/v1/businesses/{$this->business->id}/year-end/2025/close")->assertCreated();
    }

    public function test_blockers_stop_the_close_and_are_listed(): void
    {
        $this->post2025('bank', 'sales', '1000.00');
        $this->draft2025('rent', 'bank', '50.00');
        BusinessPostingAccount::query()->where('business_id', $this->business->id)->delete();

        $response = $this->actingAs($this->user)->postJson("/api/v1/businesses/{$this->business->id}/year-end/2025/close")->assertUnprocessable();

        $this->assertCount(2, $response->json('errors.year'));
        $this->assertStringContainsString('1 draft journal entry is dated in 2025', $response->json('errors.year.0'));
        $this->assertStringContainsString('retained earnings', $response->json('errors.year.1'));
        $this->assertSame(0, JournalEntry::query()->where('entry_type', 'year_end_closing')->count());
    }

    public function test_the_current_year_and_a_year_without_an_open_period_cannot_be_closed(): void
    {
        $this->post2025('bank', 'sales', '1000.00');
        $this->period->update(['status' => 'closed']);

        $this->actingAs($this->user)->postJson("/api/v1/businesses/{$this->business->id}/year-end/2025/close")->assertUnprocessable();
        $this->actingAs($this->user)->getJson("/api/v1/businesses/{$this->business->id}/year-end/2026")
            ->assertOk()
            ->assertJsonPath('data.blockers.0', 'The year 2026 has not ended yet.');
    }

    public function test_only_bookkeepers_close_a_year(): void
    {
        $this->post2025('bank', 'sales', '1000.00');
        $auditor = User::factory()->create();
        $this->business->users()->attach($auditor, ['role' => 'auditor', 'status' => 'active']);

        $this->actingAs($auditor)->getJson("/api/v1/businesses/{$this->business->id}/year-end/2025")->assertOk();
        $this->actingAs($auditor)->postJson("/api/v1/businesses/{$this->business->id}/year-end/2025/close")->assertForbidden();
    }

    private function post2025(string $debit, string $credit, string $amount): void
    {
        $entryId = $this->draft2025($debit, $credit, $amount);
        $this->actingAs($this->user)->postJson("/api/v1/businesses/{$this->business->id}/journal-entries/{$entryId}/post")->assertOk();
    }

    private function draft2025(string $debit, string $credit, string $amount): int
    {
        return $this->actingAs($this->user)->postJson("/api/v1/businesses/{$this->business->id}/journal-entries", [
            'accounting_period_id' => $this->period->id,
            'entry_date' => '2025-06-30',
            'description' => "{$debit} / {$credit}",
            'lines' => [
                ['account_id' => $this->accounts[$debit]->id, 'debit' => $amount, 'credit' => '0.00'],
                ['account_id' => $this->accounts[$credit]->id, 'debit' => '0.00', 'credit' => $amount],
            ],
        ])->assertCreated()->json('data.id');
    }
}
