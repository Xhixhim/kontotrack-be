<?php

namespace App\Console\Commands;

use App\Services\Backups\BackupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('kontotrack:backup')]
#[Description('Back up the database and business documents, verify the archive, and prune old backups')]
class BackupCreate extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(BackupService $backupService): int
    {
        try {
            $backup = $backupService->create();
        } catch (Throwable $exception) {
            Log::error('KontoTrack backup failed.', ['error' => $exception->getMessage()]);
            $this->error('Backup failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $verification = $backupService->verify($backup['path']);

        if (! $verification['ok']) {
            Log::error('KontoTrack backup failed verification.', ['path' => $backup['path'], 'problems' => $verification['problems']]);
            $this->error('Backup was written but failed verification: '.implode(' ', $verification['problems']));

            return self::FAILURE;
        }

        Log::info('KontoTrack backup created.', ['path' => $backup['path'], 'size_bytes' => $backup['size_bytes'], 'files' => $backup['file_count']]);
        $this->info("Backup created and verified: {$backup['path']}");
        $this->line("{$backup['file_count']} file(s), ".number_format($backup['size_bytes'] / 1024, 1).' KB, '.($backup['encrypted'] ? 'encrypted' : 'not encrypted').", {$backup['pruned']} old backup(s) removed.");

        if (! $backup['encrypted']) {
            $this->warn('Set KONTOTRACK_BACKUP_PASSWORD to encrypt backups.');
        }

        return self::SUCCESS;
    }
}
