<?php

namespace Tests\Feature\Api\V1;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\BusinessPostingAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostingAccountControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_401_without_authentication(): void
    {
        $business = Business::factory()->create();

        $this->getJson("/api/v1/businesses/{$business->id}/posting-accounts")->assertUnauthorized();
    }

    public function test_returns_403_to_a_user_outside_the_business(): void
    {
        $business = Business::factory()->create();

        $this->actingAs(User::factory()->create())
            ->getJson("/api/v1/businesses/{$business->id}/posting-accounts")
            ->assertForbidden();
    }

    public function test_lists_every_posting_purpose_with_its_mapped_account(): void
    {
        [$user, $business] = $this->memberOfBusiness('auditor');
        $receivables = Account::factory()->for($business)->ofType('asset')->create(['code' => '1200']);
        BusinessPostingAccount::factory()->create([
            'business_id' => $business->id,
            'purpose' => 'accounts_receivable',
            'account_id' => $receivables->id,
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/posting-accounts");

        $response->assertOk()
            ->assertJsonCount(16, 'data')
            ->assertJsonPath('data.0.purpose', 'accounts_receivable')
            ->assertJsonPath('data.0.account.code', '1200')
            ->assertJsonPath('data.1.purpose', 'accounts_payable')
            ->assertJsonPath('data.1.account', null);
    }

    public function test_owner_maps_accounts_and_the_change_is_audited(): void
    {
        [$user, $business] = $this->memberOfBusiness('owner');
        $receivables = Account::factory()->for($business)->ofType('asset')->create();
        $revenue = Account::factory()->for($business)->ofType('revenue')->create();

        $response = $this->actingAs($user)->putJson("/api/v1/businesses/{$business->id}/posting-accounts", [
            'accounts' => ['accounts_receivable' => $receivables->id, 'sales_revenue' => $revenue->id],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.0.account.id', $receivables->id)
            ->assertJsonPath('data.2.account.id', $revenue->id);
        $this->assertDatabaseHas('business_posting_accounts', ['business_id' => $business->id, 'purpose' => 'accounts_receivable', 'account_id' => $receivables->id]);
        $this->assertDatabaseHas('business_posting_accounts', ['business_id' => $business->id, 'purpose' => 'sales_revenue', 'account_id' => $revenue->id]);
        $auditLog = AuditLog::query()->where('action', 'posting_accounts.updated')->sole();
        $this->assertSame($user->id, $auditLog->user_id);
        $this->assertSame([], $auditLog->old_values);
        $this->assertEquals(['accounts_receivable' => $receivables->id, 'sales_revenue' => $revenue->id], $auditLog->new_values);
    }

    public function test_null_account_removes_the_mapping_for_that_purpose(): void
    {
        [$user, $business] = $this->memberOfBusiness('accountant');
        $receivables = Account::factory()->for($business)->ofType('asset')->create();
        BusinessPostingAccount::factory()->create([
            'business_id' => $business->id,
            'purpose' => 'accounts_receivable',
            'account_id' => $receivables->id,
        ]);

        $this->actingAs($user)
            ->putJson("/api/v1/businesses/{$business->id}/posting-accounts", ['accounts' => ['accounts_receivable' => null]])
            ->assertOk()
            ->assertJsonPath('data.0.account', null);

        $this->assertDatabaseMissing('business_posting_accounts', ['business_id' => $business->id, 'purpose' => 'accounts_receivable']);
    }

    public function test_rejects_an_account_from_another_business_with_422(): void
    {
        [$user, $business] = $this->memberOfBusiness('owner');
        $foreignAccount = Account::factory()->ofType('asset')->create();

        $this->actingAs($user)
            ->putJson("/api/v1/businesses/{$business->id}/posting-accounts", ['accounts' => ['accounts_receivable' => $foreignAccount->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['accounts.accounts_receivable' => 'Choose an active account from this business.']);

        $this->assertDatabaseCount('business_posting_accounts', 0);
    }

    public function test_rejects_an_account_type_not_allowed_for_the_purpose_with_422(): void
    {
        [$user, $business] = $this->memberOfBusiness('owner');
        $expense = Account::factory()->for($business)->ofType('expense')->create();

        $this->actingAs($user)
            ->putJson("/api/v1/businesses/{$business->id}/posting-accounts", ['accounts' => ['vat_output' => $expense->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['accounts.vat_output' => 'This account type cannot be used for output vat payable.']);
    }

    public function test_rejects_an_unknown_purpose_with_422(): void
    {
        [$user, $business] = $this->memberOfBusiness('owner');
        $asset = Account::factory()->for($business)->ofType('asset')->create();

        $this->actingAs($user)
            ->putJson("/api/v1/businesses/{$business->id}/posting-accounts", ['accounts' => ['petty_cash' => $asset->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('accounts');
    }

    public function test_auditor_cannot_change_posting_accounts(): void
    {
        [$user, $business] = $this->memberOfBusiness('auditor');
        $asset = Account::factory()->for($business)->ofType('asset')->create();

        $this->actingAs($user)
            ->putJson("/api/v1/businesses/{$business->id}/posting-accounts", ['accounts' => ['accounts_receivable' => $asset->id]])
            ->assertForbidden();

        $this->assertDatabaseCount('business_posting_accounts', 0);
    }

    /** @return array{User, Business} */
    private function memberOfBusiness(string $role): array
    {
        $user = User::factory()->create();
        $business = Business::factory()->create();
        $business->users()->attach($user, ['role' => $role, 'status' => 'active']);

        return [$user, $business];
    }
}
