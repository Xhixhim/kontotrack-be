<?php

namespace Tests\Feature\Api\V1;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\BusinessPostingAccount;
use App\Models\Counterparty;
use App\Models\User;
use App\Services\Accounting\ChartTemplateService;
use App\Services\Accounting\PostingAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBookkeepingFixtures;
use Tests\TestCase;

class ChartTemplateTest extends TestCase
{
    use CreatesBookkeepingFixtures, RefreshDatabase;

    public function test_returns_403_to_an_auditor(): void
    {
        $user = User::factory()->create();
        $business = Business::factory()->create();
        $business->users()->attach($user, ['role' => 'auditor', 'status' => 'active']);

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/accounts/kosovo-template")->assertForbidden();
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_creates_the_kosovo_chart_and_maps_every_posting_purpose_on_an_empty_business(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/accounts/kosovo-template")
            ->assertOk()
            ->assertJsonPath('data.skipped', [])
            ->assertJsonCount(count(ChartTemplateService::KOSOVO_STANDARD), 'data.created');

        $this->assertSame(count(ChartTemplateService::KOSOVO_STANDARD), $business->accounts()->count());
        $this->assertSame(
            $business->accounts()->where('code', '1100')->value('id'),
            $business->accounts()->where('code', '1110')->value('parent_id'),
        );
        $this->assertEqualsCanonicalizing(array_keys(PostingAccountService::PURPOSES), $business->postingAccounts()->pluck('purpose')->all());
        $this->assertSame('2200', $business->postingAccounts()->where('purpose', 'accounts_receivable')->first()->account->code);
        $this->assertTrue(AuditLog::query()->where('business_id', $business->id)->where('action', 'account.template_applied')->exists());
    }

    public function test_keeps_existing_accounts_and_mappings_and_is_safe_to_repeat(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $ownReceivables = Account::factory()->for($business)->ofType('asset')->create(['code' => '2200', 'name' => 'Klientët vendorë']);
        $otherReceivables = Account::factory()->for($business)->ofType('asset')->create(['code' => '2290']);
        BusinessPostingAccount::factory()->create(['business_id' => $business->id, 'purpose' => 'accounts_receivable', 'account_id' => $otherReceivables->id]);

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/accounts/kosovo-template")
            ->assertOk()
            ->assertJsonPath('data.skipped', ['2200']);

        $this->assertSame('Klientët vendorë', $ownReceivables->refresh()->name);
        $this->assertSame($otherReceivables->id, $business->postingAccounts()->where('purpose', 'accounts_receivable')->value('account_id'));

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/accounts/kosovo-template")
            ->assertOk()
            ->assertJsonPath('data.created', [])
            ->assertJsonPath('data.mapped', []);
        $this->assertSame(count(ChartTemplateService::KOSOVO_STANDARD) + 1, $business->accounts()->count());
    }

    public function test_a_sales_invoice_posts_automatically_after_applying_the_template(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $customer = Counterparty::factory()->for($business)->customer()->create();
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/accounts/kosovo-template")->assertOk();

        $invoiceId = $this->createDraft($user, $business, ['kind' => 'sale', 'counterparty_id' => $customer->id], 'S18');

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/issue")
            ->assertOk()
            ->assertJsonPath('data.status', 'issued');
    }
}
