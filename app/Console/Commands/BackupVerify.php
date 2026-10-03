<?php

namespace App\Console\Commands;

use App\Services\Backups\BackupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('kontotrack:backup:verify {path? : Archive to check; defaults to the newest backup}')]
#[Description('Check that a backup archive is complete and every file matches its checksum')]
class BackupVerify extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(BackupService $backupService): int
    {
        $path = $this->argument('path');
        $result = $backupService->verify(is_string($path) ? $path : null);

        if (! $result['ok']) {
            $this->error("Backup check failed for {$result['path']}:");

            foreach ($result['problems'] as $problem) {
                $this->line(" - {$problem}");
            }

            return self::FAILURE;
        }

        $this->info("Backup is complete: {$result['path']}");
        $this->line("Created {$result['created_at']}, {$result['file_count']} file(s) verified.");

        return self::SUCCESS;
    }
}
