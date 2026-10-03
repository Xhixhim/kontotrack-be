<?php

namespace Tests\Feature\Api\V1;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_web_login_and_logout_are_logged_with_ip_and_browser(): void
    {
        $user = User::factory()->create(['email' => 'arta@example.test', 'password' => 'correct-password']);

        $this->withHeaders(['User-Agent' => 'TestBrowser/1.0', 'Referer' => 'http://localhost:5173'])
            ->postJson('/api/v1/login', ['email' => 'arta@example.test', 'password' => 'correct-password'])
            ->assertOk();
        $this->postJson('/api/v1/logout')->assertOk();

        $this->assertSame(['auth.login', 'auth.logout'], AuditLog::query()->orderBy('id')->pluck('action')->all());
        $login = AuditLog::query()->where('action', 'auth.login')->sole();
        $this->assertSame($user->id, $login->user_id);
        $this->assertSame('TestBrowser/1.0', $login->user_agent);
        $this->assertNotNull($login->ip_address);
        $this->assertSame(['channel' => 'web'], $login->new_values);
    }

    public function test_failed_login_is_logged_without_the_password(): void
    {
        $user = User::factory()->create(['email' => 'arta@example.test', 'password' => 'correct-password']);

        $this->postJson('/api/v1/login', ['email' => 'arta@example.test', 'password' => 'wrong-password'])->assertUnprocessable();

        $failed = AuditLog::query()->where('action', 'auth.login_failed')->sole();
        $this->assertSame($user->id, $failed->user_id);
        $this->assertSame(['channel' => 'web', 'email' => 'arta@example.test'], $failed->new_values);
        $this->assertStringNotContainsString('wrong-password', json_encode($failed->toArray()));
    }

    public function test_mobile_token_login_is_logged(): void
    {
        User::factory()->create(['email' => 'arta@example.test', 'password' => 'correct-password']);

        $this->postJson('/api/v1/mobile/token', ['email' => 'arta@example.test', 'password' => 'correct-password', 'device_name' => 'Pixel'])->assertOk();
        $this->postJson('/api/v1/mobile/token', ['email' => 'nobody@example.test', 'password' => 'x', 'device_name' => 'Pixel'])->assertUnprocessable();

        $this->assertSame([['auth.login', 'mobile'], ['auth.login_failed', 'mobile']], AuditLog::query()->orderBy('id')->get()->map(fn (AuditLog $log): array => [$log->action, $log->new_values['channel']])->all());
        $this->assertNull(AuditLog::query()->where('action', 'auth.login_failed')->sole()->user_id);
    }
}
