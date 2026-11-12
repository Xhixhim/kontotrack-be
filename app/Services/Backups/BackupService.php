<?php

namespace App\Services\Backups;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

class BackupService
{
    private const DATABASE_ENTRY_SQL = 'database.sql';

    private const DATABASE_ENTRY_SQLITE = 'database.sqlite';

    private const MANIFEST_ENTRY = 'manifest.json';

    private const DOCUMENTS_PREFIX = 'documents/';

    private const DRILL_CONNECTION = 'kontotrack_restore_drill';

    /**
     * Create a backup archive of the database and the business documents, then prune old archives.
     *
     * @return array{path: string, size_bytes: int, file_count: int, encrypted: bool, pruned: int}
     */
    public function create(bool $prune = true): array
    {
        $directory = $this->backupDirectory();
        $workingDirectory = $this->workingDirectory();

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
                'row_counts' => $this->rowCounts(null),
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
                'pruned' => $prune ? $this->prune() : 0,
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

        $zip = $this->openArchive($path);

        if ($zip === null) {
            return ['path' => $path, 'ok' => false, 'problems' => ['The archive cannot be opened.'], 'file_count' => 0, 'created_at' => null];
        }

        $manifest = $this->readManifest($zip);

        if ($manifest === null) {
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
     * Replace the live database and business documents with the contents of an archive.
     *
     * The archive is verified first, and the current data is backed up before anything is overwritten.
     * Documents uploaded after the backup was taken are left on disk.
     *
     * @return array{path: string, created_at: string|null, safety_backup: string|null, documents_restored: int}
     */
    public function restore(?string $path = null, bool $safetyBackup = true): array
    {
        [$path, $manifest] = $this->verifiedArchive($path);
        $safetyBackupPath = $safetyBackup ? $this->create(prune: false)['path'] : null;
        $workingDirectory = $this->workingDirectory();
        $zip = $this->openArchive($path);

        try {
            $dumpFile = $this->extract($zip, $manifest['database_entry'], $workingDirectory.'/'.$manifest['database_entry']);
            $connection = DB::connection();
            $config = $connection->getConfig();

            if ($connection->getDriverName() === 'sqlite') {
                $this->replaceSqliteDatabase($dumpFile, (string) $config['database']);
            } else {
                $this->importMysql($config, (string) $config['database'], $dumpFile, $workingDirectory);
            }

            $documentsRestored = $this->restoreDocuments($zip, $manifest['files']);
        } finally {
            $zip?->close();
            File::deleteDirectory($workingDirectory);
        }

        return [
            'path' => $path,
            'created_at' => $manifest['created_at'],
            'safety_backup' => $safetyBackupPath,
            'documents_restored' => $documentsRestored,
        ];
    }

    /**
     * Restore an archive into a scratch database, compare it with what the backup recorded, then discard it.
     * Nothing live is changed.
     *
     * @return array{path: string, ok: bool, problems: list<string>, warnings: list<string>, table_count: int, row_count: int, document_count: int, created_at: string|null}
     */
    public function drill(?string $path = null): array
    {
        [$path, $manifest] = $this->verifiedArchive($path);
        $workingDirectory = $this->workingDirectory();
        $zip = $this->openArchive($path);
        $connection = DB::connection();
        $config = $connection->getConfig();
        $scratchDatabase = null;

        try {
            $dumpFile = $this->extract($zip, $manifest['database_entry'], $workingDirectory.'/'.$manifest['database_entry']);

            if ($connection->getDriverName() === 'sqlite') {
                config(['database.connections.'.self::DRILL_CONNECTION => [...$config, 'database' => $dumpFile]]);
            } else {
                $scratchDatabase = substr($config['database'].'_restore_drill', 0, 64);
                $quotedScratch = '`'.str_replace('`', '``', $scratchDatabase).'`';
                DB::statement("DROP DATABASE IF EXISTS {$quotedScratch}");
                DB::statement("CREATE DATABASE {$quotedScratch} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $this->importMysql($config, $scratchDatabase, $dumpFile, $workingDirectory);
                config(['database.connections.'.self::DRILL_CONNECTION => [...$config, 'database' => $scratchDatabase]]);
            }

            $restoredCounts = $this->rowCounts(self::DRILL_CONNECTION, failSilently: false);
            $problems = [];
            $warnings = [];

            if (($restoredCounts['migrations'] ?? 0) === 0) {
                $problems[] = 'The restored database has no migration history, so it is not a usable KontoTrack database.';
            }

            foreach ($manifest['row_counts'] as $table => $expected) {
                if (! array_key_exists($table, $restoredCounts)) {
                    $problems[] = "Table {$table} is missing from the restored database.";
                } elseif ($restoredCounts[$table] !== (int) $expected) {
                    $warnings[] = "Table {$table} has {$restoredCounts[$table]} row(s); the backup recorded {$expected}. Small differences happen when data was written while the backup ran.";
                }
            }

            if ($manifest['row_counts'] === []) {
                $warnings[] = 'This archive was made before row counts were recorded, so table contents were not compared.';
            }

            return [
                'path' => $path,
                'ok' => $problems === [],
                'problems' => $problems,
                'warnings' => $warnings,
                'table_count' => count($restoredCounts),
                'row_count' => array_sum($restoredCounts),
                'document_count' => count($this->documentEntries($manifest['files'])),
                'created_at' => $manifest['created_at'],
            ];
        } finally {
            $zip?->close();
            DB::purge(self::DRILL_CONNECTION);

            if ($scratchDatabase !== null) {
                DB::statement('DROP DATABASE IF EXISTS `'.str_replace('`', '``', $scratchDatabase).'`');
            }

            File::deleteDirectory($workingDirectory);
        }
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
        $credentialsFile = $this->writeMysqlCredentials($config, $workingDirectory);
        $dumpFile = $workingDirectory.'/'.self::DATABASE_ENTRY_SQL;

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

    /**
     * @return array{string, array{created_at: string|null, database_entry: string, row_counts: array<string, int>, files: array<string, string>}}
     */
    private function verifiedArchive(?string $path): array
    {
        $verification = $this->verify($path);

        if (! $verification['ok']) {
            throw new RuntimeException('The backup archive failed verification: '.implode(' ', $verification['problems']));
        }

        $zip = $this->openArchive($verification['path']);
        $manifest = $zip === null ? null : $this->readManifest($zip);
        $zip?->close();

        if ($manifest === null) {
            throw new RuntimeException('The backup manifest could not be read.');
        }

        $liveDriver = DB::connection()->getDriverName();
        $archiveDriver = (string) ($manifest['database_driver'] ?? '');
        $sameFamily = fn (string $driver): string => $driver === 'mariadb' ? 'mysql' : $driver;

        if ($sameFamily($liveDriver) !== $sameFamily($archiveDriver)) {
            throw new RuntimeException("This backup holds a {$archiveDriver} database and cannot be restored into {$liveDriver}.");
        }

        return [$verification['path'], [
            'created_at' => isset($manifest['created_at']) ? (string) $manifest['created_at'] : null,
            'database_entry' => (string) $manifest['database_entry'],
            'row_counts' => is_array($manifest['row_counts'] ?? null) ? $manifest['row_counts'] : [],
            'files' => $manifest['files'],
        ]];
    }

    private function openArchive(string $path): ?ZipArchive
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return null;
        }

        $password = $this->password();

        if ($password !== null) {
            $zip->setPassword($password);
        }

        return $zip;
    }

    /** @return array<string, mixed>|null */
    private function readManifest(ZipArchive $zip): ?array
    {
        $manifestJson = $zip->getFromName(self::MANIFEST_ENTRY);
        $manifest = $manifestJson === false ? null : json_decode($manifestJson, true);

        return is_array($manifest) && isset($manifest['files'], $manifest['database_entry']) && is_array($manifest['files']) ? $manifest : null;
    }

    private function extract(ZipArchive $zip, string $entry, string $targetPath): string
    {
        $stream = $zip->getStream($entry);

        if ($stream === false) {
            throw new RuntimeException("{$entry} could not be read from the backup archive.");
        }

        File::ensureDirectoryExists(dirname($targetPath), 0700);
        $target = fopen($targetPath, 'wb');
        stream_copy_to_stream($stream, $target);
        fclose($stream);
        fclose($target);
        chmod($targetPath, 0600);

        return $targetPath;
    }

    /**
     * @param  array<string, string>  $files
     * @return list<string>
     */
    private function documentEntries(array $files): array
    {
        return array_values(array_filter(array_keys($files), fn (string $entry): bool => str_starts_with($entry, self::DOCUMENTS_PREFIX)));
    }

    /** @param array<string, string> $files */
    private function restoreDocuments(ZipArchive $zip, array $files): int
    {
        $disk = Storage::disk(config('kontotrack.backups.documents_disk'));
        $documentsDirectory = trim((string) config('kontotrack.backups.documents_directory'), '/').'/';
        $restored = 0;

        foreach ($this->documentEntries($files) as $entry) {
            $relativePath = substr($entry, strlen(self::DOCUMENTS_PREFIX));

            // Never write outside the documents directory, whatever the archive claims.
            if (! str_starts_with($relativePath, $documentsDirectory) || in_array('..', explode('/', $relativePath), true)) {
                throw new RuntimeException("{$entry} is outside the documents directory and was not restored.");
            }

            $stream = $zip->getStream($entry);

            if ($stream === false || ! $disk->writeStream($relativePath, $stream)) {
                throw new RuntimeException("{$entry} could not be restored.");
            }

            fclose($stream);
            $restored++;
        }

        return $restored;
    }

    private function replaceSqliteDatabase(string $dumpFile, string $databasePath): void
    {
        if ($databasePath === ':memory:') {
            throw new RuntimeException('An in-memory database cannot be restored.');
        }

        DB::purge();
        File::delete([$databasePath.'-wal', $databasePath.'-shm']);

        if (! File::copy($dumpFile, $databasePath)) {
            throw new RuntimeException("Could not write the restored database to {$databasePath}.");
        }
    }

    /** @param array<string, mixed> $config */
    private function importMysql(array $config, string $database, string $dumpFile, string $workingDirectory): void
    {
        $credentialsFile = $this->writeMysqlCredentials($config, $workingDirectory);
        $dump = fopen($dumpFile, 'rb');

        try {
            $result = Process::timeout(3600)->input($dump)->run([
                'mysql',
                '--defaults-extra-file='.$credentialsFile,
                '--default-character-set=utf8mb4',
                $database,
            ]);
        } finally {
            fclose($dump);
        }

        if ($result->failed()) {
            throw new RuntimeException('The database import failed: '.trim($result->errorOutput()));
        }
    }

    /** @param array<string, mixed> $config */
    private function writeMysqlCredentials(array $config, string $workingDirectory): string
    {
        $credentialsFile = $workingDirectory.'/mysql-credentials.cnf';
        File::put($credentialsFile, implode("\n", [
            '[client]',
            'user='.$this->quoteOption((string) $config['username']),
            'password='.$this->quoteOption((string) $config['password']),
            'host='.$this->quoteOption((string) $config['host']),
            'port='.(int) $config['port'],
        ])."\n");
        chmod($credentialsFile, 0600);

        return $credentialsFile;
    }

    /**
     * Rows per table. Recorded in the manifest so a restore drill has something to compare against;
     * a backup must not fail because counting did, so by default problems give an empty list.
     *
     * @return array<string, int>
     */
    private function rowCounts(?string $connection, bool $failSilently = true): array
    {
        try {
            $counts = [];

            foreach (Schema::connection($connection)->getTables() as $table) {
                $counts[(string) $table['name']] = DB::connection($connection)->table($table['name'])->count();
            }

            ksort($counts);

            return $counts;
        } catch (Throwable $exception) {
            if ($failSilently) {
                return [];
            }

            throw $exception;
        }
    }

    private function workingDirectory(): string
    {
        $workingDirectory = $this->backupDirectory().'/.tmp-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($workingDirectory, 0700);

        return $workingDirectory;
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
