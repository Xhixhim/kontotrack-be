<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Services\Security\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-07-20 10:00:00');
        $this->withHeader('Referer', 'http://localhost:5173');
    }

    public function test_user_turns_on_two_factor_and_then_needs_a_code_to_sign_in(): void
    {
        $user = User::factory()->create(['email' => 'arta@example.test', 'password' => 'secret-password']);
        $secret = $this->actingAs($user)->postJson('/api/v1/me/two-factor')->assertOk()->json('data.secret');

        $recoveryCodes = $this->actingAs($user)->postJson('/api/v1/me/two-factor/confirm', ['code' => $this->codeFor($secret)])
            ->assertOk()
            ->json('data.recovery_codes');
        $this->assertCount(8, $recoveryCodes);
        $this->actingAs($user)->getJson('/api/v1/me')->assertJsonPath('data.two_factor_enabled', true);
        $this->assertDatabaseHas('audit_logs', ['action' => 'two_factor.enabled', 'user_id' => $user->id]);

        $this->postJson('/api/v1/login', ['email' => 'arta@example.test', 'password' => 'secret-password'])
            ->assertUnprocessable()
            ->assertJsonPath('two_factor_required', true);
        $this->travel(31)->seconds();
        $this->postJson('/api/v1/login', ['email' => 'arta@example.test', 'password' => 'secret-password', 'code' => $this->codeFor($secret)])->assertOk();
    }

    public function test_a_code_cannot_be_used_twice(): void
    {
        [$user, $secret] = $this->userWithTwoFactor();
        $this->travel(31)->seconds();
        $code = $this->codeFor($secret);

        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'secret-password', 'code' => $code])->assertOk();
        $this->postJson('/api/v1/logout')->assertOk();

        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'secret-password', 'code' => $code])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code' => 'That code is not valid.']);
    }

    public function test_a_recovery_code_works_once(): void
    {
        [$user, , $recoveryCodes] = $this->userWithTwoFactor();

        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'secret-password', 'recovery_code' => strtolower($recoveryCodes[0])])->assertOk();
        $this->postJson('/api/v1/logout')->assertOk();

        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'secret-password', 'recovery_code' => $recoveryCodes[0]])->assertUnprocessable();
        $this->assertCount(7, $user->refresh()->two_factor_recovery_codes);
    }

    public function test_turning_off_needs_the_password_and_a_valid_code(): void
    {
        [$user, $secret] = $this->userWithTwoFactor();
        $this->travel(31)->seconds();

        $this->actingAs($user)->deleteJson('/api/v1/me/two-factor', ['password' => 'wrong', 'code' => $this->codeFor($secret)])
            ->assertUnprocessable()->assertJsonValidationErrors(['password' => 'The password is not correct.']);
        $this->actingAs($user)->deleteJson('/api/v1/me/two-factor', ['password' => 'secret-password', 'code' => $this->codeFor($secret)])->assertOk();

        $this->assertFalse($user->refresh()->hasTwoFactorEnabled());
    }

    public function test_confirmation_rejects_a_wrong_code(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/v1/me/two-factor')->assertOk();

        $this->actingAs($user)->postJson('/api/v1/me/two-factor/confirm', ['code' => '000000'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
        $this->assertFalse($user->refresh()->hasTwoFactorEnabled());
    }

    public function test_mobile_sign_in_also_asks_for_the_code(): void
    {
        [$user, $secret] = $this->userWithTwoFactor();
        $this->travel(31)->seconds();

        $this->postJson('/api/v1/mobile/token', ['email' => $user->email, 'password' => 'secret-password', 'device_name' => 'Pixel'])
            ->assertUnprocessable()->assertJsonPath('two_factor_required', true);
        $this->postJson('/api/v1/mobile/token', ['email' => $user->email, 'password' => 'secret-password', 'device_name' => 'Pixel', 'code' => $this->codeFor($secret)])
            ->assertOk()->assertJsonStructure(['token']);
    }

    /** @return array{User, string, list<string>} */
    private function userWithTwoFactor(): array
    {
        $user = User::factory()->create(['password' => 'secret-password']);
        $secret = $this->actingAs($user)->postJson('/api/v1/me/two-factor')->json('data.secret');
        $recoveryCodes = $this->actingAs($user)->postJson('/api/v1/me/two-factor/confirm', ['code' => $this->codeFor($secret)])->json('data.recovery_codes');

        return [$user, $secret, $recoveryCodes];
    }

    private function codeFor(string $secret): string
    {
        return (new TotpService)->codeAt($secret, intdiv(now()->getTimestamp(), 30));
    }
}
