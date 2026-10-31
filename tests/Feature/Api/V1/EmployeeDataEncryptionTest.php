<?php

namespace Tests\Feature\Api\V1;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Employee;
use App\Models\User;
use App\Support\BlindIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmployeeDataEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_personal_number_and_iban_are_encrypted_at_rest_but_returned_to_payroll_users(): void
    {
        [$user, $business] = $this->payrollBusiness();

        $id = $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/employees", $this->employee(['personal_number' => '1234567890', 'iban' => 'XK051212012345678906']))
            ->assertCreated()
            ->assertJsonPath('data.personal_number', '1234567890')
            ->assertJsonPath('data.iban', 'XK051212012345678906')
            ->assertJsonMissingPath('data.personal_number_hash')
            ->json('data.id');

        $row = DB::table('employees')->where('id', $id)->first();
        $this->assertStringNotContainsString('1234567890', (string) $row->personal_number);
        $this->assertStringNotContainsString('XK0512', (string) $row->iban);
        $this->assertSame('1234567890', Crypt::decryptString($row->personal_number));
        $this->assertSame(BlindIndex::personalNumber($business->id, '1234567890'), $row->personal_number_hash);
        $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/employees")->assertJsonPath('data.0.personal_number', '1234567890');
    }

    public function test_duplicate_personal_numbers_are_still_found_even_with_spaces(): void
    {
        [$user, $business] = $this->payrollBusiness();
        Employee::factory()->for($business)->create(['personal_number' => '1234567890']);
        // The same person may work for another business.
        Employee::factory()->for(Business::factory())->create(['personal_number' => '9999999999']);

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/employees", $this->employee(['personal_number' => '123 456 7890']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['personal_number' => 'Another employee of this business already has this personal number.']);
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/employees", $this->employee(['personal_number' => '9999999999']))->assertCreated();
        $this->assertNotSame(...Employee::query()->get()->filter(fn (Employee $employee): bool => $employee->personal_number === '9999999999')->pluck('personal_number_hash')->all());
    }

    public function test_the_audit_log_keeps_only_the_last_four_characters(): void
    {
        [$user, $business] = $this->payrollBusiness();
        $employee = Employee::factory()->for($business)->create(['personal_number' => '1234567890', 'iban' => 'XK051212012345678906']);

        $this->actingAs($user)->putJson("/api/v1/businesses/{$business->id}/employees/{$employee->id}", ['personal_number' => '1112223334', 'iban' => 'XK051212012345670000'])->assertOk();

        $log = AuditLog::query()->where('action', 'employee.updated')->sole();
        $this->assertSame(['personal_number' => '••••7890', 'iban' => '••••8906'], array_intersect_key($log->old_values, array_flip(['personal_number', 'iban'])));
        $this->assertSame(['personal_number' => '••••3334', 'iban' => '••••0000'], array_intersect_key($log->new_values, array_flip(['personal_number', 'iban'])));
    }

    public function test_reencrypt_command_moves_data_to_a_new_key_and_rebuilds_hashes(): void
    {
        [$user, $business] = $this->payrollBusiness();
        $employee = Employee::factory()->for($business)->create(['personal_number' => '1234567890', 'iban' => 'XK051212012345678906']);
        $oldKey = config('app.key');
        $newKey = 'base64:'.base64_encode(random_bytes(32));

        config(['app.key' => $newKey, 'app.previous_keys' => [$oldKey]]);
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
        Employee::encryptUsing(app('encrypter'));

        $this->artisan('kontotrack:reencrypt-employee-data')->expectsOutputToContain('Re-encrypted 1 employee record(s).')->assertSuccessful();

        config(['app.previous_keys' => []]);
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
        Employee::encryptUsing(app('encrypter'));
        $fresh = Employee::query()->findOrFail($employee->id);
        $this->assertSame(['1234567890', 'XK051212012345678906'], [$fresh->personal_number, $fresh->iban]);
        $this->assertSame(BlindIndex::personalNumber($business->id, '1234567890'), $fresh->personal_number_hash);
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/employees", $this->employee(['personal_number' => '1234567890']))->assertUnprocessable();
    }

    /** @return array{User, Business} */
    private function payrollBusiness(): array
    {
        $user = User::factory()->create();
        $business = Business::factory()->create();
        $business->users()->attach($user, ['role' => 'accountant', 'status' => 'active']);

        return [$user, $business];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function employee(array $overrides): array
    {
        return $overrides + [
            'first_name' => 'Arbër', 'last_name' => 'Hoxha', 'position' => 'Kamarier', 'contract_type' => 'indefinite',
            'employment_start' => '2026-03-01', 'gross_salary' => '500.00', 'is_primary_employment' => true,
        ];
    }
}
