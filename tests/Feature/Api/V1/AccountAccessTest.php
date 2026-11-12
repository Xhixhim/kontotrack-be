<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Notifications\AccountInvitation;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AccountAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_emails_a_link_to_the_web_app(): void
    {
        Notification::fake();
        config(['kontotrack.frontend_url' => 'https://app.kontotrack.test/']);
        $user = User::factory()->create(['email' => 'pronar@example.test']);

        $this->postJson('/api/v1/forgot-password', ['email' => 'pronar@example.test'])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $mail = $notification->toMail($user);

            return str_starts_with($mail->actionUrl, 'https://app.kontotrack.test/reset-password?token=')
                && str_contains($mail->actionUrl, 'email=pronar%40example.test');
        });
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.password_reset_requested', 'subject_id' => $user->id]);
    }

    public function test_forgot_password_answers_the_same_for_unknown_and_deactivated_accounts(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'off@example.test', 'deactivated_at' => now()]);
        $known = $this->postJson('/api/v1/forgot-password', ['email' => User::factory()->create()->email])->assertOk()->json('message');

        $this->postJson('/api/v1/forgot-password', ['email' => 'nobody@example.test'])->assertOk()->assertJsonPath('message', $known);
        $this->postJson('/api/v1/forgot-password', ['email' => 'off@example.test'])->assertOk()->assertJsonPath('message', $known);

        Notification::assertSentTimes(ResetPassword::class, 1);
    }

    public function test_reset_password_sets_the_new_password_and_signs_out_devices(): void
    {
        $user = User::factory()->create(['email' => 'pronar@example.test', 'email_verified_at' => null]);
        $user->createToken('phone');
        $token = Password::createToken($user);

        $this->postJson('/api/v1/reset-password', ['email' => 'pronar@example.test', 'token' => $token, 'password' => 'Fjalekalim2026', 'password_confirmation' => 'Fjalekalim2026'])->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('Fjalekalim2026', $user->password));
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(0, $user->tokens()->count());
        $this->withHeaders(['Referer' => 'http://localhost:5173'])->postJson('/api/v1/login', ['email' => 'pronar@example.test', 'password' => 'Fjalekalim2026'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.password_reset', 'subject_id' => $user->id]);
    }

    public function test_reset_password_rejects_a_wrong_or_reused_token_with_422(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);
        $payload = ['email' => $user->email, 'password' => 'Fjalekalim2026', 'password_confirmation' => 'Fjalekalim2026'];

        $this->postJson('/api/v1/reset-password', [...$payload, 'token' => 'not-the-token'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/v1/reset-password', [...$payload, 'token' => $token])->assertOk();
        $this->postJson('/api/v1/reset-password', [...$payload, 'token' => $token])->assertUnprocessable();
    }

    public function test_reset_password_rejects_a_weak_password_with_422(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/reset-password', ['email' => $user->email, 'token' => Password::createToken($user), 'password' => 'short1', 'password_confirmation' => 'short1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_user_changes_their_password_with_the_current_one(): void
    {
        $user = User::factory()->create(['password' => 'OldPassword2026']);

        $this->actingAs($user)->putJson('/api/v1/me/password', ['current_password' => 'wrong', 'password' => 'NewPassword2026', 'password_confirmation' => 'NewPassword2026'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');
        $this->actingAs($user)->putJson('/api/v1/me/password', ['current_password' => 'OldPassword2026', 'password' => 'NewPassword2026', 'password_confirmation' => 'NewPassword2026'])->assertOk();

        $this->assertTrue(Hash::check('NewPassword2026', $user->refresh()->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.password_changed', 'subject_id' => $user->id]);
    }

    public function test_a_deactivated_account_cannot_sign_in_or_keep_using_a_session(): void
    {
        $user = User::factory()->create(['email' => 'off@example.test', 'deactivated_at' => now()]);

        $this->postJson('/api/v1/login', ['email' => 'off@example.test', 'password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'This account has been deactivated. Contact your administrator.']);
        $this->postJson('/api/v1/mobile/token', ['email' => 'off@example.test', 'password' => 'password', 'device_name' => 'phone'])->assertUnprocessable();
        $this->actingAs($user)->getJson('/api/v1/businesses')->assertUnauthorized();
    }

    public function test_only_system_administrators_manage_users(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/v1/admin/users')->assertForbidden();
        $this->actingAs($user)->postJson('/api/v1/admin/users', ['name' => 'X', 'email' => 'x@example.test'])->assertForbidden();
        $this->actingAs($user)->putJson("/api/v1/admin/users/{$user->id}", ['is_system_admin' => true])->assertForbidden();
        $this->assertFalse($user->refresh()->is_system_admin);
    }

    public function test_administrator_creates_a_user_who_receives_a_password_link(): void
    {
        Notification::fake();
        $administrator = $this->administrator();

        $this->actingAs($administrator)->postJson('/api/v1/admin/users', ['name' => 'Besa Gashi', 'email' => 'besa@example.test'])
            ->assertCreated()
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.has_signed_in', false)
            ->assertJsonPath('data.is_system_admin', false);

        $created = User::query()->where('email', 'besa@example.test')->firstOrFail();
        Notification::assertSentTo($created, AccountInvitation::class, function (AccountInvitation $notification) use ($created, $administrator): bool {
            $mail = $notification->toMail($created);

            return str_contains($mail->actionUrl, '/reset-password?token=')
                && str_contains($mail->actionUrl, 'email=besa%40example.test&invited=1')
                && str_contains(implode(' ', $mail->introLines), $administrator->name);
        });
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.created', 'subject_id' => $created->id, 'user_id' => $administrator->id]);
        $this->actingAs($administrator)->getJson('/api/v1/admin/users')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_administrator_deactivates_a_user_and_their_tokens_stop_working(): void
    {
        $administrator = $this->administrator();
        $user = User::factory()->create();
        $user->createToken('phone');

        $this->actingAs($administrator)->putJson("/api/v1/admin/users/{$user->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertNotNull($user->refresh()->deactivated_at);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.updated', 'subject_id' => $user->id, 'user_id' => $administrator->id]);

        $this->actingAs($administrator)->putJson("/api/v1/admin/users/{$user->id}", ['is_active' => true])->assertJsonPath('data.is_active', true);
    }

    public function test_administrator_cannot_lock_themselves_out(): void
    {
        $administrator = $this->administrator();

        $this->actingAs($administrator)->putJson("/api/v1/admin/users/{$administrator->id}", ['is_active' => false])->assertUnprocessable();
        $this->actingAs($administrator)->putJson("/api/v1/admin/users/{$administrator->id}", ['is_system_admin' => false])->assertUnprocessable();

        $this->assertTrue($administrator->refresh()->is_system_admin);
        $this->assertNull($administrator->deactivated_at);
    }

    public function test_rejects_creating_a_user_with_an_email_that_is_taken_with_422(): void
    {
        $administrator = $this->administrator();

        $this->actingAs($administrator)->postJson('/api/v1/admin/users', ['name' => 'Copy', 'email' => $administrator->email])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    private function administrator(): User
    {
        $administrator = User::factory()->create();
        $administrator->forceFill(['is_system_admin' => true])->save();

        return $administrator;
    }
}
