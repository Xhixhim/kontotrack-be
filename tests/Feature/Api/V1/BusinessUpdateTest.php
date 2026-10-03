<?php

namespace Tests\Feature\Api\V1;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class BusinessUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_401_without_authentication(): void
    {
        $business = Business::factory()->create();

        $this->putJson("/api/v1/businesses/{$business->id}", ['legal_name' => 'New name'])->assertUnauthorized();
    }

    public function test_owner_updates_business_details_and_only_changed_fields_are_audited(): void
    {
        [$user, $business] = $this->memberOfBusiness('owner');
        $business->update(['legal_name' => 'Old Name SH.P.K.', 'vat_status' => 'unknown']);

        $response = $this->actingAs($user)->putJson("/api/v1/businesses/{$business->id}", [
            'legal_name' => 'New Name SH.P.K.',
            'owner_name' => 'Arta Krasniqi',
            'email' => 'finance@example.test',
            'phone' => '+383 44 000 000',
            'vat_status' => 'registered',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.legal_name', 'New Name SH.P.K.')
            ->assertJsonPath('data.owner_name', 'Arta Krasniqi')
            ->assertJsonPath('data.email', 'finance@example.test')
            ->assertJsonPath('data.vat_status', 'registered');
        $this->assertDatabaseHas('businesses', ['id' => $business->id, 'legal_name' => 'New Name SH.P.K.', 'phone' => '+383 44 000 000']);
        $auditLog = AuditLog::query()->where('action', 'business.updated')->sole();
        $this->assertSame($user->id, $auditLog->user_id);
        $this->assertSame(array_keys($auditLog->new_values), array_keys($auditLog->old_values));
        $this->assertEquals(['legal_name' => 'Old Name SH.P.K.', 'owner_name' => null, 'vat_status' => 'unknown', 'email' => null, 'phone' => null], $auditLog->old_values);
        $this->assertEquals(['legal_name' => 'New Name SH.P.K.', 'owner_name' => 'Arta Krasniqi', 'vat_status' => 'registered', 'email' => 'finance@example.test', 'phone' => '+383 44 000 000'], $auditLog->new_values);
    }

    public function test_submitting_unchanged_values_writes_no_audit_entry(): void
    {
        [$user, $business] = $this->memberOfBusiness('accountant');

        $this->actingAs($user)
            ->putJson("/api/v1/businesses/{$business->id}", ['legal_name' => $business->legal_name, 'nui' => $business->nui])
            ->assertOk();

        $this->assertDatabaseMissing('audit_logs', ['action' => 'business.updated']);
    }

    public function test_rejects_a_nui_used_by_another_business_with_422(): void
    {
        [$user, $business] = $this->memberOfBusiness('owner');
        $otherBusiness = Business::factory()->create();

        $this->actingAs($user)
            ->putJson("/api/v1/businesses/{$business->id}", ['nui' => $otherBusiness->nui])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nui' => 'The nui has already been taken.']);
    }

    public function test_rejects_an_unknown_vat_status_with_422(): void
    {
        [$user, $business] = $this->memberOfBusiness('owner');

        $this->actingAs($user)
            ->putJson("/api/v1/businesses/{$business->id}", ['vat_status' => 'sometimes'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('vat_status');
    }

    #[TestWith(['assistant_accountant'])]
    #[TestWith(['auditor'])]
    public function test_forbids_roles_without_edit_rights(string $role): void
    {
        [$user, $business] = $this->memberOfBusiness($role);

        $this->actingAs($user)
            ->putJson("/api/v1/businesses/{$business->id}", ['legal_name' => 'Changed'])
            ->assertForbidden();

        $this->assertDatabaseMissing('businesses', ['legal_name' => 'Changed']);
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
