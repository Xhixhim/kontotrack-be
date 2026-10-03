<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Counterparty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_history_lists_only_changes_to_the_business_with_the_author(): void
    {
        [$owner, $business] = $this->memberOfBusiness('owner', 'Arta Owner');
        $customer = Counterparty::factory()->for($business)->customer()->create();
        $this->actingAs($owner)->putJson("/api/v1/businesses/{$business->id}", ['owner_name' => 'Arta Krasniqi'])->assertOk();
        $this->actingAs($owner)->putJson("/api/v1/businesses/{$business->id}/counterparties/{$customer->id}", ['name' => 'Renamed'])->assertOk();

        $this->actingAs($owner)
            ->getJson("/api/v1/businesses/{$business->id}/audit-logs?subject=business&subject_id=0")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'business.updated')
            ->assertJsonPath('data.0.subject', 'business')
            ->assertJsonPath('data.0.new_values.owner_name', 'Arta Krasniqi')
            ->assertJsonPath('data.0.user.name', 'Arta Owner');
    }

    public function test_contact_history_is_filtered_to_that_contact(): void
    {
        [$owner, $business] = $this->memberOfBusiness('owner');
        $first = Counterparty::factory()->for($business)->customer()->create();
        $second = Counterparty::factory()->for($business)->customer()->create();
        $this->actingAs($owner)->putJson("/api/v1/businesses/{$business->id}/counterparties/{$first->id}", ['name' => 'First renamed'])->assertOk();
        $this->actingAs($owner)->putJson("/api/v1/businesses/{$business->id}/counterparties/{$second->id}", ['name' => 'Second renamed'])->assertOk();

        $this->actingAs($owner)
            ->getJson("/api/v1/businesses/{$business->id}/audit-logs?subject=counterparty&subject_id={$second->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.new_values.name', 'Second renamed');
    }

    public function test_does_not_show_entries_from_another_business(): void
    {
        [$owner, $business] = $this->memberOfBusiness('owner');
        [$otherOwner, $otherBusiness] = $this->memberOfBusiness('owner');
        $this->actingAs($otherOwner)->putJson("/api/v1/businesses/{$otherBusiness->id}", ['owner_name' => 'Someone else'])->assertOk();

        $this->actingAs($owner)
            ->getJson("/api/v1/businesses/{$business->id}/audit-logs")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_auditor_can_read_the_history(): void
    {
        [$auditor, $business] = $this->memberOfBusiness('auditor');

        $this->actingAs($auditor)->getJson("/api/v1/businesses/{$business->id}/audit-logs")->assertOk();
    }

    public function test_assistant_accountant_cannot_read_the_history(): void
    {
        [$assistant, $business] = $this->memberOfBusiness('assistant_accountant');

        $this->actingAs($assistant)->getJson("/api/v1/businesses/{$business->id}/audit-logs")->assertForbidden();
    }

    public function test_rejects_a_subject_filter_without_an_id_with_422(): void
    {
        [$owner, $business] = $this->memberOfBusiness('owner');

        $this->actingAs($owner)
            ->getJson("/api/v1/businesses/{$business->id}/audit-logs?subject=counterparty")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('subject_id');
    }

    /** @return array{User, Business} */
    private function memberOfBusiness(string $role, ?string $name = null): array
    {
        $user = User::factory()->create($name === null ? [] : ['name' => $name]);
        $business = Business::factory()->create();
        $business->users()->attach($user, ['role' => $role, 'status' => 'active']);

        return [$user, $business];
    }
}
