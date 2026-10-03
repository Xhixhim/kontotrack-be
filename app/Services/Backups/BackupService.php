<?php

namespace App\Services\Backups;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

class BackupService
{
    private const DATABASE_ENTRY_SQL = 'database.sql';

    private const DATABASE_ENTRY_SQLITE = 'database.sqlite';

    private const MANIFEST_ENTRY = 'manifest.json';

    /**
     * Create a backup archive of the database and the business documents, then prune old archives.
     *
     * @return array{path: string, size_bytes: int, file_count: int, encrypted: bool, pruned: int}
     */
    public function create(): array
    {
        $directory = $this->backupDirectory();
        $workingDirectory = $directory.'/.tmp-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($workingDirectory, 0700);

        try {
            $databaseEntry = $this->dumpDatabase($workingDirectory);
            $archivePath = $directory.'/kontotrack-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(3)).'.zip';
            $password = $this->password();
            $zip = new ZipArchive;

            if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                throw new RuntimeException("Could not create the backup archive at {$archivePath}.");
            }

            if ($password !== null) {
                $zip->setPassword($password);
            }

            $checksums = [];
            $this->addFile($zip, $workingDirectory.'/'.$databaseEntry, $databaseEntry, $password, $checksums);
            $disk = Storage::disk(config('kontotrack.backups.documents_disk'));

            foreach ($disk->allFiles(config('kontotrack.backups.documents_directory')) as $documentPath) {
                $this->addFile($zip, $disk->path($documentPath), 'documents/'.$documentPath, $password, $checksums);
            }

            $manifest = json_encode([
                'created_at' => now()->toIso8601String(),
                'app' => config('app.name'),
                'database_driver' => DB::connection()->getDriverName(),
                'database_entry' => $databaseEntry,
                'files' => $checksums,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $zip->addFromString(self::MANIFEST_ENTRY, $manifest);

            if ($password !== null) {
                $zip->setEncryptionName(self::MANIFEST_ENTRY, ZipArchive::EM_AES_256);
            }

            if (! $zip->close()) {
                throw new RuntimeException('Could not finish writing the backup archive.');
            }

            chmod($archivePath, 0600);

            return [
                'path' => $archivePath,
                'size_bytes' => (int) filesize($archivePath),
                'file_count' => count($checksums),
                'encrypted' => $password !== null,
                'pruned' => $this->prune(),
            ];
        } finally {
            File::deleteDirectory($workingDirectory);
        }
    }

    /**
     * Check that an archive opens, lists every file in its manifest, and that each file matches its checksum.
     *
     * @return array{path: string, ok: bool, problems: list<string>, file_count: int, created_at: string|null}
     */
    public function verify(?string $path = null): array
    {
        $path ??= $this->latestBackup();

        if ($path === null || ! is_file($path)) {
            return ['path' => (string) $path, 'ok' => false, 'problems' => ['No backup archive was found.'], 'file_count' => 0, 'created_at' => null];
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return ['path' => $path, 'ok' => false, 'problems' => ['The archive cannot be opened.'], 'file_count' => 0, 'created_at' => null];
        }

        $password = $this->password();

        if ($password !== null) {
            $zip->setPassword($password);
        }

        $manifestJson = $zip->getFromName(self::MANIFEST_ENTRY);
        $manifest = $manifestJson === false ? null : json_decode($manifestJson, true);

        if (! is_array($manifest) || ! isset($manifest['files'], $manifest['database_entry']) || ! is_array($manifest['files'])) {
            $zip->close();

            return ['path' => $path, 'ok' => false, 'problems' => ['The manifest is missing or unreadable. Check the backup password.'], 'file_count' => 0, 'created_at' => null];
        }

        $problems = [];

        foreach ($manifest['files'] as $entry => $expectedChecksum) {
            $stream = $zip->getStream($entry);

            if ($stream === false) {
                $problems[] = "{$entry} is missing from the archive.";

                continue;
            }

            $context = hash_init('sha256');
            hash_update_stream($context, $stream);
            fclose($stream);

            if (! hash_equals((string) $expectedChecksum, hash_final($context))) {
                $problems[] = "{$entry} does not match its checksum.";
            }
        }

        if (! isset($manifest['files'][$manifest['database_entry']])) {
            $problems[] = 'The database dump is not listed in the manifest.';
        } elseif (! $this->databaseDumpLooksComplete($zip, (string) $manifest['database_entry'])) {
            $problems[] = 'The database dump looks incomplete.';
        }

        $zip->close();

        return [
            'path' => $path,
            'ok' => $problems === [],
            'problems' => $problems,
            'file_count' => count($manifest['files']),
            'created_at' => isset($manifest['created_at']) ? (string) $manifest['created_at'] : null,
        ];
    }

    /**
     * Delete the oldest archives beyond the configured number to keep.
     */
    public function prune(): int
    {
        $keep = max(1, (int) config('kontotrack.backups.keep'));
        $archives = $this->archives();
        $expired = array_slice($archives, $keep);

        foreach ($expired as $archive) {
            File::delete($archive);
        }

        return count($expired);
    }

    /** @return list<string> Archive paths, newest first. */
    public function archives(): array
    {
        $directory = $this->backupDirectory();
        $archives = File::glob($directory.'/kontotrack-*.zip');
        usort($archives, fn (string $left, string $right): int => [filemtime($right), $right] <=> [filemtime($left), $left]);

        return array_values($archives);
    }

    private function latestBackup(): ?string
    {
        return $this->archives()[0] ?? null;
    }

    private function dumpDatabase(string $workingDirectory): string
    {
        $connection = DB::connection();

        return match ($connection->getDriverName()) {
            'mysql', 'mariadb' => $this->dumpMysql($connection->getConfig(), $workingDirectory),
            'sqlite' => $this->dumpSqlite($workingDirectory),
            default => throw new RuntimeException("Backups are not supported for the {$connection->getDriverName()} database driver."),
        };
    }

    /** @param array<string, mixed> $config */
    private function dumpMysql(array $config, string $workingDirectory): string
    {
        $credentialsFile = $workingDirectory.'/mysql-credentials.cnf';
        $dumpFile = $workingDirectory.'/'.self::DATABASE_ENTRY_SQL;
        File::put($credentialsFile, implode("\n", [
            '[client]',
            'user='.$this->quoteOption((string) $config['username']),
            'password='.$this->quoteOption((string) $config['password']),
            'host='.$this->quoteOption((string) $config['host']),
            'port='.(int) $config['port'],
        ])."\n");
        chmod($credentialsFile, 0600);

        $result = Process::timeout(3600)->run([
            'mysqldump',
            '--defaults-extra-file='.$credentialsFile,
            '--single-transaction',
            '--routines',
            '--triggers',
            '--no-tablespaces',
            '--default-character-set=utf8mb4',
            '--result-file='.$dumpFile,
            (string) $config['database'],
        ]);

        if ($result->failed() || ! is_file($dumpFile)) {
            throw new RuntimeException('The database dump failed: '.trim($result->errorOutput()));
        }

        return self::DATABASE_ENTRY_SQL;
    }

    private function dumpSqlite(string $workingDirectory): string
    {
        $dumpFile = $workingDirectory.'/'.self::DATABASE_ENTRY_SQLITE;
        DB::statement('VACUUM INTO ?', [$dumpFile]);

        return self::DATABASE_ENTRY_SQLITE;
    }

    private function databaseDumpLooksComplete(ZipArchive $zip, string $entry): bool
    {
        $stream = $zip->getStream($entry);

        if ($stream === false) {
            return false;
        }

        $head = (string) fread($stream, 16);
        $tail = '';

        while (! feof($stream)) {
            $tail = substr($tail.(string) fread($stream, 8192), -512);
        }

        fclose($stream);

        return $entry === self::DATABASE_ENTRY_SQLITE
            ? str_starts_with($head, "SQLite format 3\0")
            : str_contains($head.$tail, '-- Dump completed');
    }

    /** @param array<string, string> $checksums */
    private function addFile(ZipArchive $zip, string $sourcePath, string $entry, ?string $password, array &$checksums): void
    {
        if (! $zip->addFile($sourcePath, $entry)) {
            throw new RuntimeException("Could not add {$entry} to the backup archive.");
        }

        if ($password !== null) {
            $zip->setEncryptionName($entry, ZipArchive::EM_AES_256);
        }

        $checksums[$entry] = (string) hash_file('sha256', $sourcePath);
    }

    private function backupDirectory(): string
    {
        $directory = rtrim((string) config('kontotrack.backups.path'), '/');
        File::ensureDirectoryExists($directory, 0700);

        return $directory;
    }

    private function password(): ?string
    {
        $password = config('kontotrack.backups.password');

        return is_string($password) && $password !== '' ? $password : null;
    }

    private function quoteOption(string $value): string
    {
        return '"'.addcslashes($value, '\\"').'"';
    }
}
