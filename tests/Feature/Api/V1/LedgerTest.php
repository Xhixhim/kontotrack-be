<?php

namespace Tests\Feature\Api\V1;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Business;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class LedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_balanced_draft_can_be_posted_and_then_reversed(): void
    {
        [$user, $business, $period, $cash, $capital] = $this->ledger();

        $entryId = $this->draft($user, $business, $period, $cash, $capital, '500.00', '500.00')->assertCreated()->json('data.id');
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/journal-entries/{$entryId}/post")->assertOk()->assertJsonPath('data.status', 'posted');

        $reversalId = $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/journal-entries/{$entryId}/reverse", [
            'accounting_period_id' => $period->id,
            'entry_date' => '2026-03-01',
        ])->assertCreated()->json('data.id');

        $reversal = JournalEntry::query()->with('lines')->findOrFail($reversalId);
        $this->assertSame($entryId, $reversal->reversal_of_id);
        $this->assertSame(['0.00', '500.00'], [(string) $reversal->lines->firstWhere('account_id', $cash->id)->debit, (string) $reversal->lines->firstWhere('account_id', $cash->id)->credit]);
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/journal-entries/{$entryId}/reverse", ['accounting_period_id' => $period->id, 'entry_date' => '2026-03-02'])
            ->assertUnprocessable()->assertJsonValidationErrors(['journal_entry' => 'This journal entry already has a reversal.']);
    }

    public function test_rejects_an_unbalanced_entry_with_422(): void
    {
        [$user, $business, $period, $cash, $capital] = $this->ledger();

        $this->draft($user, $business, $period, $cash, $capital, '500.00', '400.00')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines' => 'Total debits must equal total credits.']);
    }

    public function test_posted_entries_cannot_be_deleted_but_drafts_can(): void
    {
        [$user, $business, $period, $cash, $capital] = $this->ledger();
        $postedId = $this->draft($user, $business, $period, $cash, $capital, '10.00', '10.00')->json('data.id');
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/journal-entries/{$postedId}/post")->assertOk();
        $draftId = $this->draft($user, $business, $period, $cash, $capital, '20.00', '20.00')->json('data.id');

        $this->actingAs($user)->deleteJson("/api/v1/businesses/{$business->id}/journal-entries/{$postedId}")
            ->assertUnprocessable()->assertJsonValidationErrors(['journal_entry' => 'Only draft entries can be removed.']);
        $this->actingAs($user)->deleteJson("/api/v1/businesses/{$business->id}/journal-entries/{$draftId}")->assertSuccessful();

        $this->assertDatabaseMissing('journal_entries', ['id' => $draftId]);
    }

    public function test_overlapping_periods_are_rejected_with_422(): void
    {
        [$user, $business] = $this->ledger();

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/periods", ['name' => 'Overlap', 'starts_on' => '2026-06-01', 'ends_on' => '2027-05-31'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_on' => 'This period overlaps an existing accounting period.']);
    }

    public function test_draft_journal_blocks_closing_the_period(): void
    {
        [$user, $business, $period, $cash, $capital] = $this->ledger();
        $this->draft($user, $business, $period, $cash, $capital, '10.00', '10.00')->assertCreated();

        $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/periods/{$period->id}/closing-checks")
            ->assertOk()
            ->assertJsonPath('data.can_close', false);
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/periods/{$period->id}/close")->assertUnprocessable();

        $this->assertSame('open', $period->refresh()->status);
    }

    public function test_closed_period_rejects_new_entries_and_posting(): void
    {
        [$user, $business, $period, $cash, $capital] = $this->ledger();
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/periods/{$period->id}/close")->assertOk()->assertJsonPath('data.status', 'closed');

        $this->draft($user, $business, $period, $cash, $capital, '10.00', '10.00')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['accounting_period_id' => 'Choose an open period that contains the journal date.']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'accounting_period.closed', 'subject_id' => $period->id]);
    }

    public function test_auditor_can_read_but_not_write_the_ledger(): void
    {
        [, $business, $period, $cash, $capital] = $this->ledger();
        $auditor = User::factory()->create();
        $business->users()->attach($auditor, ['role' => 'auditor', 'status' => 'active']);

        $this->actingAs($auditor)->getJson("/api/v1/businesses/{$business->id}/journal-entries")->assertOk();
        $this->draft($auditor, $business, $period, $cash, $capital, '10.00', '10.00')->assertForbidden();
    }

    /** @return array{User, Business, AccountingPeriod, Account, Account} */
    private function ledger(): array
    {
        $user = User::factory()->create();
        $business = Business::factory()->create();
        $business->users()->attach($user, ['role' => 'accountant', 'status' => 'active']);
        $period = AccountingPeriod::factory()->for($business)->create(['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);

        return [$user, $business, $period, Account::factory()->for($business)->ofType('asset')->create(), Account::factory()->for($business)->ofType('equity')->create()];
    }

    private function draft(User $user, Business $business, AccountingPeriod $period, Account $debitAccount, Account $creditAccount, string $debit, string $credit): TestResponse
    {
        return $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/journal-entries", [
            'accounting_period_id' => $period->id,
            'entry_date' => '2026-02-01',
            'description' => 'Owner capital contribution',
            'lines' => [
                ['account_id' => $debitAccount->id, 'debit' => $debit, 'credit' => '0'],
                ['account_id' => $creditAccount->id, 'debit' => '0', 'credit' => $credit],
            ],
        ]);
    }
}
