<?php

namespace Tests\Feature\Console;

use App\Models\ComplianceRuleSet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_fails_with_development_settings_and_names_what_to_fix(): void
    {
        config(['app.debug' => true, 'kontotrack.backups.path' => sys_get_temp_dir().'/kontotrack-empty-'.bin2hex(random_bytes(4))]);

        $this->artisan('kontotrack:production-check')
            ->expectsOutputToContain('APP_DEBUG must be false')
            ->expectsOutputToContain('No approved vat rule set is effective today')
            ->expectsOutputToContain('No backup exists yet')
            ->assertFailed();
    }

    public function test_production_settings_pass_those_checks(): void
    {
        config([
            'app.debug' => false,
            'app.url' => 'https://app.kontotrack.example',
            'session.secure' => true,
            'cors.allowed_origins' => ['https://app.kontotrack.example'],
            'kontotrack.backups.password' => 'long-passphrase',
            'kontotrack.backups.path' => sys_get_temp_dir().'/kontotrack-empty-'.bin2hex(random_bytes(4)),
        ]);
        User::factory()->create()->forceFill(['is_system_admin' => true])->save();
        ComplianceRuleSet::factory()->approvedVat()->create(['valid_from' => now()->startOfYear()->toDateString()]);

        $this->artisan('kontotrack:production-check')
            ->doesntExpectOutputToContain('APP_DEBUG must be false')
            ->doesntExpectOutputToContain('Set SESSION_SECURE_COOKIE=true')
            ->doesntExpectOutputToContain('No approved vat rule set')
            ->doesntExpectOutputToContain('No system admin exists')
            ->expectsOutputToContain('No backup exists yet')
            ->assertFailed();
    }
}
