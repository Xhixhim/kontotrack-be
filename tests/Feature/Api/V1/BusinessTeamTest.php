<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\User;
use App\Notifications\AccountInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class BusinessTeamTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_business_makes_the_creator_its_owner(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/businesses', ['legal_name' => 'Kafe Drini B.I.', 'nui' => '811999000', 'vat_status' => 'not_registered', 'legal_form' => 'B.I.'])
            ->assertCreated()
            ->assertJsonPath('data.role', 'owner');

        $this->actingAs($user)->getJson('/api/v1/businesses')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_rejects_a_nui_that_is_already_registered_with_422(): void
    {
        Business::factory()->create(['nui' => '811999000']);

        $this->actingAs(User::factory()->create())->postJson('/api/v1/businesses', ['legal_name' => 'Copy', 'nui' => '811999000', 'vat_status' => 'unknown'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('nui');
    }

    public function test_owner_invites_an_accountant_who_accepts_and_gains_access(): void
    {
        [$owner, $business] = $this->ownedBusiness();
        $accountant = User::factory()->create(['email' => 'kontabilist@example.test']);

        $this->actingAs($owner)->postJson("/api/v1/businesses/{$business->id}/team", ['email' => 'kontabilist@example.test', 'role' => 'accountant'])->assertSuccessful();
        $this->actingAs($accountant)->getJson("/api/v1/businesses/{$business->id}/dashboard")->assertForbidden();
        $this->actingAs($accountant)->getJson('/api/v1/me/business-invitations')->assertOk()->assertJsonCount(1, 'data');

        $this->actingAs($accountant)->postJson("/api/v1/businesses/{$business->id}/invitations/accept")->assertSuccessful();

        $this->actingAs($accountant)->getJson("/api/v1/businesses/{$business->id}/dashboard")->assertOk();
    }

    public function test_inviting_an_email_without_an_account_creates_one_and_emails_a_password_link(): void
    {
        Notification::fake();
        [$owner, $business] = $this->ownedBusiness();

        $this->actingAs($owner)->postJson("/api/v1/businesses/{$business->id}/team", ['email' => 'New.Person@example.test', 'name' => 'Arta Krasniqi', 'role' => 'assistant_accountant'])
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'invited')
            ->assertJsonPath('data.name', 'Arta Krasniqi');

        $member = User::query()->where('email', 'new.person@example.test')->firstOrFail();
        Notification::assertSentTo($member, AccountInvitation::class);
        $this->assertFalse(Hash::check('', $member->password));
        $this->assertDatabaseHas('audit_logs', ['business_id' => $business->id, 'action' => 'business.member_invited', 'subject_id' => $member->id]);
    }

    public function test_inviting_an_existing_user_does_not_email_a_password_link(): void
    {
        Notification::fake();
        [$owner, $business] = $this->ownedBusiness();
        $existing = User::factory()->create();

        $this->actingAs($owner)->postJson("/api/v1/businesses/{$business->id}/team", ['email' => $existing->email, 'role' => 'auditor'])->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_owner_changes_a_members_role_and_the_change_is_audited(): void
    {
        [$owner, $business] = $this->ownedBusiness();
        $member = $this->member($business, 'assistant_accountant');

        $this->actingAs($member)->postJson("/api/v1/businesses/{$business->id}/counterparties", ['type' => 'customer', 'name' => 'Klient'])->assertForbidden();

        $this->actingAs($owner)->putJson("/api/v1/businesses/{$business->id}/team/{$member->id}", ['role' => 'accountant'])
            ->assertOk()
            ->assertJsonPath('data.role', 'accountant');

        $this->actingAs($member)->postJson("/api/v1/businesses/{$business->id}/counterparties", ['type' => 'customer', 'name' => 'Klient'])->assertCreated();
        $this->assertDatabaseHas('audit_logs', ['business_id' => $business->id, 'action' => 'business.member_updated', 'subject_id' => $member->id, 'user_id' => $owner->id]);
    }

    public function test_restrictions_take_single_abilities_away_from_a_member(): void
    {
        [$owner, $business] = $this->ownedBusiness();
        $accountant = $this->member($business, 'accountant');

        $this->actingAs($accountant)->getJson("/api/v1/businesses/{$business->id}/employees")->assertOk();

        $this->actingAs($owner)->putJson("/api/v1/businesses/{$business->id}/team/{$accountant->id}", ['restrictions' => ['payroll', 'bookkeeping']])
            ->assertOk()
            ->assertJsonPath('data.restrictions', ['bookkeeping', 'payroll']);

        $this->actingAs($accountant)->getJson("/api/v1/businesses/{$business->id}/employees")->assertForbidden();
        $this->actingAs($accountant)->postJson("/api/v1/businesses/{$business->id}/counterparties", ['type' => 'customer', 'name' => 'Klient'])->assertForbidden();
        $this->actingAs($accountant)->getJson("/api/v1/businesses/{$business->id}/audit-logs")->assertOk();
        $this->actingAs($accountant)->getJson('/api/v1/businesses')
            ->assertJsonPath('data.0.abilities.view_payroll', false)
            ->assertJsonPath('data.0.abilities.manage_bookkeeping', false)
            ->assertJsonPath('data.0.abilities.view_audit_trail', true);

        $this->actingAs($owner)->putJson("/api/v1/businesses/{$business->id}/team/{$accountant->id}", ['restrictions' => []])->assertOk();
        $this->actingAs($accountant)->getJson("/api/v1/businesses/{$business->id}/employees")->assertOk();
    }

    public function test_rejects_an_unknown_restriction_with_422(): void
    {
        [$owner, $business] = $this->ownedBusiness();
        $accountant = $this->member($business, 'accountant');

        $this->actingAs($owner)->putJson("/api/v1/businesses/{$business->id}/team/{$accountant->id}", ['restrictions' => ['everything']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('restrictions.0');
    }

    public function test_the_owners_access_cannot_be_changed_or_removed(): void
    {
        [$owner, $business] = $this->ownedBusiness();

        $this->actingAs($owner)->putJson("/api/v1/businesses/{$business->id}/team/{$owner->id}", ['role' => 'auditor'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('member');
        $this->actingAs($owner)->deleteJson("/api/v1/businesses/{$business->id}/team/{$owner->id}")->assertUnprocessable();
        $this->actingAs($owner)->getJson("/api/v1/businesses/{$business->id}/dashboard")->assertOk();
    }

    public function test_a_removed_member_loses_access_and_can_be_invited_again(): void
    {
        [$owner, $business] = $this->ownedBusiness();
        $accountant = $this->member($business, 'accountant');

        $this->actingAs($owner)->deleteJson("/api/v1/businesses/{$business->id}/team/{$accountant->id}")->assertNoContent();

        $this->actingAs($accountant)->getJson("/api/v1/businesses/{$business->id}/dashboard")->assertForbidden();
        $this->actingAs($accountant)->getJson('/api/v1/businesses')->assertJsonCount(0, 'data');
        $this->actingAs($owner)->getJson("/api/v1/businesses/{$business->id}/team")->assertJsonCount(1, 'data');
        $this->assertDatabaseHas('audit_logs', ['business_id' => $business->id, 'action' => 'business.member_removed', 'subject_id' => $accountant->id]);

        $this->actingAs($owner)->postJson("/api/v1/businesses/{$business->id}/team", ['email' => $accountant->email, 'role' => 'auditor'])
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'invited');
    }

    public function test_changing_someone_who_is_not_a_member_returns_404(): void
    {
        [$owner, $business] = $this->ownedBusiness();
        $stranger = User::factory()->create();

        $this->actingAs($owner)->putJson("/api/v1/businesses/{$business->id}/team/{$stranger->id}", ['role' => 'auditor'])->assertNotFound();
        $this->actingAs($owner)->deleteJson("/api/v1/businesses/{$business->id}/team/{$stranger->id}")->assertNotFound();
    }

    public function test_only_the_owner_can_manage_the_team(): void
    {
        [, $business] = $this->ownedBusiness();
        $accountant = User::factory()->create();
        $business->users()->attach($accountant, ['role' => 'accountant', 'status' => 'active']);

        $this->actingAs($accountant)->postJson("/api/v1/businesses/{$business->id}/team", ['email' => User::factory()->create()->email, 'role' => 'auditor'])->assertForbidden();
        $this->actingAs($accountant)->putJson("/api/v1/businesses/{$business->id}/team/{$accountant->id}", ['restrictions' => []])->assertForbidden();
        $this->actingAs($accountant)->deleteJson("/api/v1/businesses/{$business->id}/team/{$accountant->id}")->assertForbidden();
    }

    public function test_business_list_shows_only_the_users_own_businesses(): void
    {
        [$owner] = $this->ownedBusiness();
        Business::factory()->count(2)->create();

        $this->actingAs($owner)->getJson('/api/v1/businesses')->assertOk()->assertJsonCount(1, 'data');
    }

    private function member(Business $business, string $role): User
    {
        $member = User::factory()->create();
        $business->users()->attach($member, ['role' => $role, 'status' => 'active']);

        return $member;
    }

    /** @return array{User, Business} */
    private function ownedBusiness(): array
    {
        $owner = User::factory()->create();
        $business = Business::factory()->create();
        $business->users()->attach($owner, ['role' => 'owner', 'status' => 'active']);

        return [$owner, $business];
    }
}
