<?php

namespace App\Console\Commands;

use App\Services\Backups\BackupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('kontotrack:backup:restore
    {path? : Archive to restore; defaults to the newest backup}
    {--drill : Restore into a scratch database, compare it with the backup and discard it; nothing live is changed}
    {--force : Do not ask for confirmation}
    {--no-safety-backup : Do not back up the current data before it is replaced}')]
#[Description('Restore the database and business documents from a backup archive, or rehearse a restore with --drill')]
class BackupRestore extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(BackupService $backupService): int
    {
        $path = $this->argument('path');
        $path = is_string($path) ? $path : ($backupService->archives()[0] ?? null);

        if ($path === null) {
            $this->error('No backup archive was found.');

            return self::FAILURE;
        }

        return $this->option('drill') ? $this->drill($backupService, $path) : $this->restore($backupService, $path);
    }

    private function drill(BackupService $backupService, string $path): int
    {
        try {
            $drill = $backupService->drill($path);
        } catch (Throwable $exception) {
            Log::error('KontoTrack restore drill failed.', ['path' => $path, 'error' => $exception->getMessage()]);
            $this->error('Restore drill failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        foreach ($drill['warnings'] as $warning) {
            $this->warn($warning);
        }

        if (! $drill['ok']) {
            Log::error('KontoTrack restore drill failed.', ['path' => $path, 'problems' => $drill['problems']]);
            $this->error("Restore drill failed for {$path}:");

            foreach ($drill['problems'] as $problem) {
                $this->line(" - {$problem}");
            }

            return self::FAILURE;
        }

        Log::info('KontoTrack restore drill passed.', ['path' => $path, 'tables' => $drill['table_count'], 'rows' => $drill['row_count']]);
        $this->info("Restore drill passed: {$path}");
        $this->line("Backup from {$drill['created_at']} restored into a scratch database: {$drill['table_count']} table(s), {$drill['row_count']} row(s), {$drill['document_count']} document file(s) in the archive. The live data was not changed.");

        return self::SUCCESS;
    }

    private function restore(BackupService $backupService, string $path): int
    {
        if (! $this->option('force') && ! $this->confirm("This replaces the live database and business documents with the backup {$path}. Continue?")) {
            $this->line('Nothing was restored.');

            return self::FAILURE;
        }

        try {
            $restore = $backupService->restore($path, ! $this->option('no-safety-backup'));
        } catch (Throwable $exception) {
            Log::error('KontoTrack restore failed.', ['path' => $path, 'error' => $exception->getMessage()]);
            $this->error('Restore failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        Log::warning('KontoTrack was restored from a backup.', ['path' => $path, 'backup_created_at' => $restore['created_at'], 'safety_backup' => $restore['safety_backup']]);
        $this->info("Restored the backup from {$restore['created_at']}: {$path}");
        $this->line("{$restore['documents_restored']} document file(s) written back.");

        if ($restore['safety_backup'] !== null) {
            $this->line("The data as it was before the restore is kept in {$restore['safety_backup']}.");
        }

        $this->line('Run php artisan migrate --force if the code is newer than the backup, then php artisan up.');

        return self::SUCCESS;
    }
}
