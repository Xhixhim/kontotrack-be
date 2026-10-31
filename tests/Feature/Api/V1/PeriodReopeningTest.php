<?php

namespace Tests\Feature\Api\V1;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PeriodReopeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_accountant_asks_owner_approves_and_the_period_accepts_entries_again(): void
    {
        [$business, $accountant, $owner, $period] = $this->closedPeriod();

        $requestId = $this->ask($accountant, $business, $period, 'Supplier bill INV-77 from June was booked twice.')
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.requested_by.id', $accountant->id)
            ->json('data.id');
        $this->actingAs($accountant)->getJson("/api/v1/businesses/{$business->id}/periods")
            ->assertJsonPath('data.0.pending_reopen_request.id', $requestId);

        $this->actingAs($owner)->postJson($this->url($business, $period, "{$requestId}/approve"), ['note' => 'OK, correct it this week.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.decided_by.id', $owner->id)
            ->assertJsonPath('data.period_closed_at', '2026-07-05T08:00:00.000000Z');

        $period->refresh();
        $this->assertSame('open', $period->status);
        $this->assertNull($period->closed_at);
        $this->assertSame($owner->id, $period->reopened_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'accounting_period.reopen_requested', 'subject_id' => $period->id, 'user_id' => $accountant->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'accounting_period.reopened', 'subject_id' => $period->id, 'user_id' => $owner->id]);

        $cash = Account::factory()->for($business)->ofType('asset')->create();
        $capital = Account::factory()->for($business)->ofType('equity')->create();
        $this->actingAs($accountant)->postJson("/api/v1/businesses/{$business->id}/journal-entries", [
            'accounting_period_id' => $period->id, 'entry_date' => '2026-06-30', 'description' => 'Correction',
            'lines' => [['account_id' => $cash->id, 'debit' => '10', 'credit' => '0'], ['account_id' => $capital->id, 'debit' => '0', 'credit' => '10']],
        ])->assertCreated();
    }

    public function test_only_an_owner_or_system_admin_other_than_the_requester_can_decide(): void
    {
        [$business, $accountant, $owner, $period] = $this->closedPeriod();
        $auditor = $this->member($business, 'auditor');

        $this->ask($auditor, $business, $period, 'I would like to change something here.')->assertForbidden();
        $ownRequest = $this->ask($owner, $business, $period, 'Owner wants to correct the June payroll.')->assertCreated()->json('data.id');

        $this->actingAs($accountant)->postJson($this->url($business, $period, "{$ownRequest}/approve"))->assertForbidden();
        $this->actingAs($owner)->postJson($this->url($business, $period, "{$ownRequest}/approve"))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['request' => 'Someone other than the person who asked must approve or reject this request.']);

        $admin = User::factory()->create(['is_system_admin' => true]);
        $this->actingAs($admin)->postJson($this->url($business, $period, "{$ownRequest}/approve"))->assertOk();
        $this->assertSame('open', $period->refresh()->status);
    }

    public function test_rejecting_needs_a_note_keeps_the_period_closed_and_only_the_requester_can_withdraw(): void
    {
        [$business, $accountant, $owner, $period] = $this->closedPeriod();
        $requestId = $this->ask($accountant, $business, $period, 'Need to add a missing June sales invoice.')->json('data.id');

        $this->actingAs($owner)->postJson($this->url($business, $period, "{$requestId}/reject"))->assertUnprocessable()->assertJsonValidationErrors(['note']);
        $this->actingAs($owner)->postJson($this->url($business, $period, "{$requestId}/reject"), ['note' => 'Book it in July with a correcting entry.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');
        $this->assertSame('closed', $period->refresh()->status);

        $second = $this->ask($accountant, $business, $period, 'Second try: the June VAT return was wrong.')->assertCreated()->json('data.id');
        $this->actingAs($owner)->postJson($this->url($business, $period, "{$second}/cancel"))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['request' => 'Only the person who asked can withdraw this request.']);
        $this->actingAs($accountant)->postJson($this->url($business, $period, "{$second}/cancel"))->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->actingAs($accountant)->getJson($this->url($business, $period, ''))->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_only_the_latest_closed_period_can_be_reopened_once_at_a_time(): void
    {
        [$business, $accountant, , $period] = $this->closedPeriod();

        $this->ask($accountant, $business, $period, 'short')->assertUnprocessable()->assertJsonValidationErrors(['reason']);

        $july = AccountingPeriod::factory()->for($business)->create(['name' => 'July 2026', 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-31', 'status' => 'closed', 'closed_at' => now()]);
        $this->ask($accountant, $business, $period, 'Correct the June depreciation run.')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['accounting_period' => 'Reopen the later closed period "July 2026" first; it carries this period\'s balances forward.']);

        $this->ask($accountant, $business, $july, 'Correct the July depreciation run.')->assertCreated();
        $this->ask($accountant, $business, $july, 'Correct the July depreciation run again.')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['accounting_period' => 'A request to reopen this period is already waiting for approval.']);

        $open = AccountingPeriod::factory()->for($business)->create(['starts_on' => '2026-08-01', 'ends_on' => '2026-08-31']);
        $this->ask($accountant, $business, $open, 'This period is not closed at all.')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['accounting_period' => 'Only a closed period can be reopened.']);
    }

    public function test_the_owner_is_notified_about_a_pending_request(): void
    {
        [$business, $accountant, $owner, $period] = $this->closedPeriod();
        $this->ask($accountant, $business, $period, 'Supplier bill INV-77 from June was booked twice.')->assertCreated();

        $notification = collect($this->actingAs($owner)->getJson('/api/v1/me/notifications')->assertOk()->json('data'))->firstWhere('type', 'period_reopen_requested');

        $this->assertNotNull($notification);
        $this->assertStringContainsString('June 2026', $notification['title']);
        $this->assertNull(collect($this->actingAs($accountant)->getJson('/api/v1/me/notifications')->json('data'))->firstWhere('type', 'period_reopen_requested'));
    }

    public function test_a_user_outside_the_business_gets_403(): void
    {
        [$business, , , $period] = $this->closedPeriod();
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->getJson($this->url($business, $period, ''))->assertForbidden();
        $this->ask($outsider, $business, $period, 'Let me in to change the books.')->assertForbidden();
        $this->actingAs($outsider)->postJson($this->url($business, $period, '1/approve'))->assertForbidden();
    }

    /** @return array{Business, User, User, AccountingPeriod} */
    private function closedPeriod(): array
    {
        $business = Business::factory()->create();
        $accountant = $this->member($business, 'accountant');
        $owner = $this->member($business, 'owner');
        $period = AccountingPeriod::factory()->for($business)->create([
            'name' => 'June 2026', 'starts_on' => '2026-06-01', 'ends_on' => '2026-06-30',
            'status' => 'closed', 'closed_by' => $accountant->id, 'closed_at' => '2026-07-05 08:00:00',
        ]);

        return [$business, $accountant, $owner, $period];
    }

    private function member(Business $business, string $role): User
    {
        $user = User::factory()->create();
        $business->users()->attach($user, ['role' => $role, 'status' => 'active']);

        return $user;
    }

    private function ask(User $user, Business $business, AccountingPeriod $period, string $reason): TestResponse
    {
        return $this->actingAs($user)->postJson($this->url($business, $period, ''), ['reason' => $reason]);
    }

    private function url(Business $business, AccountingPeriod $period, string $suffix): string
    {
        return rtrim("/api/v1/businesses/{$business->id}/periods/{$period->id}/reopen-requests/{$suffix}", '/');
    }
}
