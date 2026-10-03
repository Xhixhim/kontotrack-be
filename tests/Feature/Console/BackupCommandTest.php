<?php

namespace Tests\Feature\Console;

use App\Models\Business;
use App\Services\Backups\BackupService;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use PDO;
use Tests\TestCase;
use ZipArchive;

class BackupCommandTest extends TestCase
{
    private string $backupDirectory;

    /**
     * SQLite cannot run VACUUM INTO inside a transaction, so each test migrates its own database file instead of using RefreshDatabase.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->backupDirectory = sys_get_temp_dir().'/kontotrack-backup-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->backupDirectory);
        File::put($this->backupDirectory.'/source.sqlite', '');
        config(['database.connections.sqlite.database' => $this->backupDirectory.'/source.sqlite']);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        config(['kontotrack.backups.path' => $this->backupDirectory, 'kontotrack.backups.password' => null, 'kontotrack.backups.keep' => 14]);
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        File::deleteDirectory($this->backupDirectory);

        parent::tearDown();
    }

    public function test_backup_archives_the_database_and_documents_and_the_database_can_be_restored(): void
    {
        $business = Business::factory()->create(['legal_name' => 'Restorable SH.P.K.']);
        Storage::disk('local')->put("businesses/{$business->id}/documents/bill.pdf", '%PDF-1.4 test bill');

        $this->artisan('kontotrack:backup')->assertSuccessful();

        $archive = File::glob($this->backupDirectory.'/kontotrack-*.zip')[0];
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($archive));
        $this->assertSame('%PDF-1.4 test bill', $zip->getFromName("documents/businesses/{$business->id}/documents/bill.pdf"));
        $restoredDatabase = $this->backupDirectory.'/restored.sqlite';
        File::put($restoredDatabase, $zip->getFromName('database.sqlite'));
        $zip->close();
        $restored = new PDO('sqlite:'.$restoredDatabase);
        $this->assertSame('Restorable SH.P.K.', $restored->query("SELECT legal_name FROM businesses WHERE id = {$business->id}")->fetchColumn());
    }

    public function test_verify_reports_an_archive_whose_file_was_changed(): void
    {
        Storage::disk('local')->put('businesses/1/documents/contract.pdf', 'original contract');
        $this->artisan('kontotrack:backup')->assertSuccessful();
        $archive = File::glob($this->backupDirectory.'/kontotrack-*.zip')[0];
        $zip = new ZipArchive;
        $zip->open($archive);
        $zip->addFromString('documents/businesses/1/documents/contract.pdf', 'tampered contract');
        $zip->close();

        $this->artisan('kontotrack:backup:verify', ['path' => $archive])
            ->expectsOutputToContain('documents/businesses/1/documents/contract.pdf does not match its checksum.')
            ->assertFailed();
    }

    public function test_verify_fails_when_there_is_no_backup(): void
    {
        $this->artisan('kontotrack:backup:verify')
            ->expectsOutputToContain('No backup archive was found.')
            ->assertFailed();
    }

    public function test_password_encrypts_every_entry_and_verify_needs_the_password(): void
    {
        config(['kontotrack.backups.password' => 'correct horse battery staple']);
        Storage::disk('local')->put('businesses/1/documents/payroll.pdf', 'salary data');

        $this->artisan('kontotrack:backup')->assertSuccessful();

        $archive = File::glob($this->backupDirectory.'/kontotrack-*.zip')[0];
        $zip = new ZipArchive;
        $zip->open($archive);

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $this->assertSame(ZipArchive::EM_AES_256, $zip->statIndex($index)['encryption_method'], $zip->getNameIndex($index).' is not encrypted.');
        }

        $zip->close();
        config(['kontotrack.backups.password' => 'wrong password']);
        $this->artisan('kontotrack:backup:verify', ['path' => $archive])->assertFailed();
    }

    public function test_keeps_only_the_configured_number_of_backups(): void
    {
        config(['kontotrack.backups.keep' => 2]);
        File::ensureDirectoryExists($this->backupDirectory);

        foreach (['20260101-010000', '20260102-010000', '20260103-010000'] as $index => $stamp) {
            File::put($this->backupDirectory."/kontotrack-{$stamp}-aaaaaa.zip", 'old');
            touch($this->backupDirectory."/kontotrack-{$stamp}-aaaaaa.zip", strtotime('2026-01-01') + $index * 86400);
        }

        $this->artisan('kontotrack:backup')->assertSuccessful();

        $remaining = array_map('basename', app(BackupService::class)->archives());
        $this->assertCount(2, $remaining);
        $this->assertContains('kontotrack-20260103-010000-aaaaaa.zip', $remaining);
        $this->assertNotContains('kontotrack-20260101-010000-aaaaaa.zip', $remaining);
    }

    public function test_mysql_dump_receives_credentials_through_a_file_not_the_command_line(): void
    {
        config(['database.connections.backup_mysql' => [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'kontotrack',
            'username' => 'backup_user',
            'password' => 's3cret-pass',
        ]]);
        DB::shouldReceive('purge');
        DB::shouldReceive('connection')->andReturn(new class
        {
            public function getDriverName(): string
            {
                return 'mysql';
            }

            /** @return array<string, mixed> */
            public function getConfig(): array
            {
                return config('database.connections.backup_mysql');
            }
        });
        $credentials = null;
        Process::fake(function (PendingProcess $process) use (&$credentials) {
            $command = implode(' ', (array) $process->command);
            preg_match('/--defaults-extra-file=(\S+)/', $command, $credentialsMatch);
            preg_match('/--result-file=(\S+)/', $command, $resultMatch);
            $credentials = File::get($credentialsMatch[1]);
            File::put($resultMatch[1], "-- MySQL dump\n-- Dump completed on 2026-07-20\n");

            return Process::result();
        });

        app(BackupService::class)->create();

        Process::assertRan(function (PendingProcess $process): bool {
            $command = implode(' ', (array) $process->command);

            return str_contains($command, 'mysqldump') && str_contains($command, '--single-transaction') && ! str_contains($command, 's3cret-pass');
        });
        $this->assertStringContainsString('password="s3cret-pass"', (string) $credentials);
    }
}
