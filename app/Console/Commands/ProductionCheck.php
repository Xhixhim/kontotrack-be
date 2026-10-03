<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Repositories\Contracts\ComplianceRuleSetRepository;
use App\Services\Backups\BackupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('kontotrack:production-check')]
#[Description('Check configuration, security, backups, and tax rules before going live')]
class ProductionCheck extends Command
{
    /** @var list<array{string, string, string}> */
    private array $results = [];

    /**
     * Execute the console command.
     */
    public function handle(BackupService $backupService, ComplianceRuleSetRepository $ruleSets): int
    {
        $this->checkApplication();
        $this->checkSessionsAndOrigins();
        $this->checkDatabase();
        $this->checkMail();
        $this->checkBackups($backupService);
        $this->checkRuleSets($ruleSets);

        $this->table(['Status', 'Check', 'Detail'], array_map(fn (array $result): array => [strtoupper($result[0]), $result[1], $result[2]], $this->results));
        $failures = count(array_filter($this->results, fn (array $result): bool => $result[0] === 'fail'));
        $warnings = count(array_filter($this->results, fn (array $result): bool => $result[0] === 'warn'));

        if ($failures > 0) {
            $this->error("{$failures} check(s) failed and {$warnings} need attention. Fix the failures before going live.");

            return self::FAILURE;
        }

        $this->info($warnings > 0 ? "No failures; {$warnings} warning(s) to review." : 'Ready for production.');

        return self::SUCCESS;
    }

    private function checkApplication(): void
    {
        $this->record(app()->environment('production'), 'Environment', 'APP_ENV is '.app()->environment().'; set it to production.');
        $this->record(! config('app.debug'), 'Debug mode', 'APP_DEBUG must be false so errors do not reveal internals.');
        $this->record(! empty(config('app.key')), 'Application key', 'APP_KEY is missing; run php artisan key:generate.');
        $this->record(str_starts_with((string) config('app.url'), 'https://'), 'HTTPS address', 'APP_URL should start with https://.');
    }

    private function checkSessionsAndOrigins(): void
    {
        $this->record((bool) config('session.secure'), 'Secure cookies', 'Set SESSION_SECURE_COOKIE=true so session cookies only travel over HTTPS.');
        $origins = (array) config('cors.allowed_origins');
        $insecure = array_filter($origins, fn (string $origin): bool => str_starts_with($origin, 'http://'));
        $this->record($origins !== [] && $insecure === [], 'Allowed web-app origins', 'CORS_ALLOWED_ORIGINS should list only the https address of the web app (found: '.implode(', ', $origins).').');
        $stateful = (array) config('sanctum.stateful');
        $this->record(! in_array('localhost:5173', $stateful, true), 'Sign-in domains', 'SANCTUM_STATEFUL_DOMAINS still contains the local development address.', 'warn');
    }

    private function checkDatabase(): void
    {
        try {
            DB::connection()->getPdo();
            $this->record(true, 'Database connection', '');
        } catch (Throwable $exception) {
            $this->record(false, 'Database connection', 'Cannot connect: '.$exception->getMessage());

            return;
        }

        Artisan::call('migrate:status', ['--pending' => true]);
        $pending = substr_count(Artisan::output(), 'Pending');
        $this->record($pending === 0, 'Migrations', "{$pending} migration(s) have not run; run php artisan migrate --force.");
        $this->record(User::query()->where('is_system_admin', true)->exists(), 'System administrator', 'No system admin exists; grant one with php artisan kontotrack:system-admin {email}.');
    }

    private function checkMail(): void
    {
        $this->record(! in_array(config('mail.default'), ['log', 'array'], true), 'Mail', 'MAIL_MAILER is '.config('mail.default').'; configure SMTP (or a mail service) so emails are delivered.', 'warn');
    }

    private function checkBackups(BackupService $backupService): void
    {
        $this->record(! empty(config('kontotrack.backups.password')), 'Backup encryption', 'Set KONTOTRACK_BACKUP_PASSWORD so backups are encrypted.');
        $latest = $backupService->archives()[0] ?? null;

        if ($latest === null) {
            $this->record(false, 'Latest backup', 'No backup exists yet; run php artisan kontotrack:backup and schedule schedule:run in cron.');

            return;
        }

        $ageHours = (time() - (int) filemtime($latest)) / 3600;
        $this->record($ageHours <= 26, 'Latest backup', sprintf('The newest backup is %.0f hours old; check that the nightly schedule runs.', $ageHours));
        $verification = $backupService->verify($latest);
        $this->record($verification['ok'], 'Backup integrity', implode(' ', $verification['problems']));
    }

    private function checkRuleSets(ComplianceRuleSetRepository $ruleSets): void
    {
        $today = today()->toDateString();

        foreach (['vat' => 'invoicing', 'payroll' => 'payroll', 'tax_calendar' => 'filing deadlines'] as $domain => $feature) {
            $this->record($ruleSets->findEffective('XK', $domain, $today) !== null, "Approved {$domain} rules", "No approved {$domain} rule set is effective today, so {$feature} cannot run.", $domain === 'vat' ? 'fail' : 'warn');
        }
    }

    private function record(bool $passed, string $check, string $detailWhenNotPassed, string $levelWhenNotPassed = 'fail'): void
    {
        $this->results[] = [$passed ? 'pass' : $levelWhenNotPassed, $check, $passed ? '' : $detailWhenNotPassed];
    }
}
