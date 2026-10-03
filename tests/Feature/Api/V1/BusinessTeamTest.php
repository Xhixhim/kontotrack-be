<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_rejects_inviting_an_email_without_an_account_with_422(): void
    {
        [$owner, $business] = $this->ownedBusiness();

        $this->actingAs($owner)->postJson("/api/v1/businesses/{$business->id}/team", ['email' => 'nobody@example.test', 'role' => 'accountant'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'No KontoTrack user has this email address yet.']);
    }

    public function test_only_the_owner_can_manage_the_team(): void
    {
        [, $business] = $this->ownedBusiness();
        $accountant = User::factory()->create();
        $business->users()->attach($accountant, ['role' => 'accountant', 'status' => 'active']);

        $this->actingAs($accountant)->postJson("/api/v1/businesses/{$business->id}/team", ['email' => User::factory()->create()->email, 'role' => 'auditor'])->assertForbidden();
    }

    public function test_business_list_shows_only_the_users_own_businesses(): void
    {
        [$owner] = $this->ownedBusiness();
        Business::factory()->count(2)->create();

        $this->actingAs($owner)->getJson('/api/v1/businesses')->assertOk()->assertJsonCount(1, 'data');
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
