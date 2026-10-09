<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Backup of the application database and private file store into one archive.
 * A run is successful only when the dump, every file and the archive verification all succeed.
 */
class Backup
{
    public function destination(): string
    {
        return rtrim((string) config('backup.destination'), '/');
    }

    public function setting(string $key, $default)
    {
        $value = DB::table('settings')->where('key', 'backup.'.$key)->value('value_json');

        return $value === null ? $default : json_decode($value, true);
    }

    public function time(): string
    {
        return (string) $this->setting('time', config('backup.time'));
    }

    public function retention(): int
    {
        return max(1, (int) $this->setting('retention', config('backup.retention')));
    }

    /** Problems that prevent a run, in plain language for the UI. */
    public function problems(): array
    {
        $dir = $this->destination();
        $problems = [];
        if (! is_dir($dir)) {
            $problems[] = 'Folder tujuan backup belum tersedia di container.';
        } elseif (! is_writable($dir)) {
            $problems[] = 'Folder tujuan backup tidak dapat ditulis oleh aplikasi (UID 33).';
        }
        if (str_starts_with(realpath($dir) ?: $dir, realpath(storage_path()) ?: storage_path())) {
            $problems[] = 'Tujuan backup tidak boleh berada di dalam storage aplikasi.';
        }

        return $problems;
    }

    public function queue(string $trigger, ?int $user): int
    {
        return DB::table('backup_runs')->insertGetId(['status' => 'queued', 'trigger' => $trigger, 'initiated_by' => $user, 'queued_at' => now()]);
    }

    /** Daily run when enabled, after the configured WIB time, at most once per day. */
    public function scheduleDue(): bool
    {
        if (! config('backup.enabled')) {
            return false;
        }
        $now = now('Asia/Jakarta');
        [$h, $m] = array_map('intval', explode(':', $this->time()) + [1 => 0]);
        if ($now->lt($now->copy()->setTime($h, $m))) {
            return false;
        }

        return ! DB::table('backup_runs')->where('trigger', 'scheduled')->where('queued_at', '>=', $now->copy()->startOfDay()->utc())->exists();
    }

    /** Audited changes (submissions, requests, staff edits) since the last successful backup started; backup runs themselves excluded. */
    public function pendingChanges(): int
    {
        $since = DB::table('backup_runs')->where('status', 'success')->max('started_at');

        return DB::table('activity_logs')->where('entity_type', '!=', 'backup_run')->when($since, fn ($q) => $q->where('created_at', '>=', $since))->count();
    }

    /** Change-triggered run when enabled: enough changes, nothing in progress, and the minimum interval since the last run has passed. */
    public function changesDue(): bool
    {
        $threshold = (int) config('backup.change_threshold');
        if (! config('backup.enabled') || $threshold < 1 || DB::table('backup_runs')->whereIn('status', ['queued', 'running'])->exists()) {
            return false;
        }
        $last = DB::table('backup_runs')->max('queued_at');
        if ($last && CarbonImmutable::parse($last)->gt(now()->subMinutes((int) config('backup.change_min_interval')))) {
            return false;
        }

        return $this->pendingChanges() >= $threshold;
    }

    public function run(int $id): object
    {
        $updated = DB::table('backup_runs')->where('id', $id)->where('status', 'queued')->update(['status' => 'running', 'started_at' => now()]);
        if (! $updated) {
            throw new RuntimeException('Backup run tidak dalam antrean.');
        }
        $dir = $this->destination();
        $stamp = now('Asia/Jakarta')->format('Ymd-His');
        $name = "portal-backup-$stamp-$id.zip";
        $partial = "$dir/.partial-$name";
        $work = null;
        try {
            if ($problems = $this->problems()) {
                throw new RuntimeException(implode(' ', $problems));
            }
            $work = $dir.'/.work-'.$id.'-'.Str::random(8);
            if (! mkdir($work, 0700) && ! is_dir($work)) {
                throw new RuntimeException('Folder kerja backup tidak dapat dibuat.');
            }
            $tables = $this->dump("$work/database.sql");
            $files = $this->fileList();
            $manifest = [
                'format' => 1, 'created_at' => now()->toIso8601String(), 'app' => config('app.name'), 'database' => DB::connection()->getDatabaseName(),
                'tables' => $tables, 'files' => $files, 'database_sha256' => hash_file('sha256', "$work/database.sql"), 'encrypted' => (bool) config('backup.password'),
            ];
            file_put_contents("$work/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->archive($partial, $work, $files);
            $this->verify($partial, $manifest);
            $checksum = hash_file('sha256', $partial);
            if (! rename($partial, "$dir/$name")) {
                throw new RuntimeException('Arsip backup tidak dapat difinalisasi.');
            }
            file_put_contents("$dir/$name.sha256", "$checksum  $name\n");
            DB::table('backup_runs')->where('id', $id)->update([
                'status' => 'success', 'finished_at' => now(), 'artifact_ref' => $name, 'size' => filesize("$dir/$name"), 'checksum' => $checksum,
                'manifest_json' => json_encode(['tables' => count($tables), 'rows' => array_sum($tables), 'files' => count($files), 'bytes' => array_sum(array_column($files, 'size')), 'encrypted' => $manifest['encrypted']]),
            ]);
            $this->prune();
        } catch (\Throwable $e) {
            @unlink($partial);
            DB::table('backup_runs')->where('id', $id)->update(['status' => 'failed', 'finished_at' => now(), 'error_summary' => $this->sanitize($e->getMessage())]);
        } finally {
            if ($work) {
                $this->removeDir($work);
            }
        }

        return DB::table('backup_runs')->where('id', $id)->first();
    }

    /** Never leak credentials or absolute paths into the stored error. */
    private function sanitize(string $message): string
    {
        $secrets = array_filter([config('backup.password'), config('database.connections.mysql.password')]);
        $message = str_replace($secrets, '***', $message);
        $message = str_replace([$this->destination(), storage_path()], ['<tujuan>', '<storage>'], $message);

        return Str::limit($message, 500);
    }

    /** Logical dump inside one consistent snapshot. One statement per line; generated columns are skipped. */
    private function dump(string $path): array
    {
        $pdo = DB::connection()->getPdo();
        $db = DB::connection()->getDatabaseName();
        $out = fopen($path, 'w');
        $counts = [];
        fwrite($out, "-- Portal Praktikum logical backup\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\n");
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        try {
            $tables = collect(DB::select("SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME", [$db]))->pluck('name');
            foreach ($tables as $table) {
                $create = (array) DB::selectOne("SHOW CREATE TABLE `$table`");
                fwrite($out, "DROP TABLE IF EXISTS `$table`;\n".str_replace("\n", ' ', $create['Create Table']).";\n");
                if (in_array($table, config('backup.structure_only'), true)) {
                    $counts[$table] = 0;

                    continue;
                }
                $columns = collect(DB::select("SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND EXTRA NOT LIKE '%GENERATED%' ORDER BY ORDINAL_POSITION", [$db, $table]))->pluck('name')->all();
                $list = implode(', ', array_map(fn ($c) => "`$c`", $columns));
                $count = 0;
                // Unbuffered: rows stream from MySQL instead of loading the whole table into memory.
                $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
                $stmt = $pdo->query("SELECT $list FROM `$table`", \PDO::FETCH_NUM);
                $batch = [];
                foreach ($stmt as $row) {
                    // PDO::quote escapes newlines, so each INSERT stays on one line.
                    $batch[] = '('.implode(', ', array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row)).')';
                    $count++;
                    if (count($batch) === 200) {
                        fwrite($out, "INSERT INTO `$table` ($list) VALUES ".implode(', ', $batch).";\n");
                        $batch = [];
                    }
                }
                $stmt->closeCursor();
                $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
                if ($batch) {
                    fwrite($out, "INSERT INTO `$table` ($list) VALUES ".implode(', ', $batch).";\n");
                }
                $counts[$table] = $count;
            }
        } finally {
            $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            $pdo->exec('COMMIT');
        }
        fwrite($out, "SET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n");
        fclose($out);

        return $counts;
    }

    private function fileList(): array
    {
        $disk = Storage::disk('local');
        $files = [];
        foreach ($disk->allFiles() as $path) {
            if (str_ends_with($path, '.gitignore')) {
                continue;
            }
            $files[] = ['path' => $path, 'size' => $disk->size($path), 'sha256' => hash_file('sha256', $disk->path($path))];
        }

        return $files;
    }

    private function archive(string $target, string $work, array $files): void
    {
        $zip = new ZipArchive;
        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('Arsip backup tidak dapat dibuat.');
        }
        $password = config('backup.password');
        if ($password) {
            $zip->setPassword($password);
        }
        $entries = ['database.sql' => "$work/database.sql", 'manifest.json' => "$work/manifest.json"];
        foreach ($files as $f) {
            $entries['files/'.$f['path']] = Storage::disk('local')->path($f['path']);
        }
        foreach ($entries as $entry => $source) {
            if (! $zip->addFile($source, $entry)) {
                throw new RuntimeException("Gagal menambahkan $entry ke arsip.");
            }
            if ($password) {
                $zip->setEncryptionName($entry, ZipArchive::EM_AES_256);
            }
        }
        if (! $zip->close()) {
            throw new RuntimeException('Arsip backup gagal ditutup.');
        }
    }

    /** Re-open the archive and compare every entry with the manifest before declaring success. */
    public function verify(string $archive, ?array $expected = null): array
    {
        $zip = new ZipArchive;
        if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Arsip backup tidak dapat dibuka.');
        }
        if (config('backup.password')) {
            $zip->setPassword(config('backup.password'));
        }
        try {
            $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
            if (! is_array($manifest)) {
                throw new RuntimeException('Manifest backup tidak terbaca.');
            }
            if ($expected && $manifest['database_sha256'] !== $expected['database_sha256']) {
                throw new RuntimeException('Manifest tidak cocok dengan dump.');
            }
            $sql = $zip->getFromName('database.sql');
            if ($sql === false || hash('sha256', $sql) !== $manifest['database_sha256']) {
                throw new RuntimeException('Checksum dump database tidak cocok.');
            }
            foreach ($manifest['files'] as $f) {
                $content = $zip->getFromName('files/'.$f['path']);
                if ($content === false || hash('sha256', $content) !== $f['sha256']) {
                    throw new RuntimeException('Checksum file tidak cocok: '.$f['path']);
                }
            }

            return $manifest;
        } finally {
            $zip->close();
        }
    }

    /** Keep the newest N successful archives; only files created by this application are removed. */
    public function prune(): int
    {
        $keep = DB::table('backup_runs')->where('status', 'success')->whereNull('pruned_at')->orderByDesc('finished_at')->limit($this->retention())->pluck('id');
        $old = DB::table('backup_runs')->where('status', 'success')->whereNull('pruned_at')->whereNotIn('id', $keep)->get();
        foreach ($old as $run) {
            if (preg_match('/^portal-backup-[0-9-]+\.zip$/', (string) $run->artifact_ref)) {
                @unlink($this->destination().'/'.$run->artifact_ref);
                @unlink($this->destination().'/'.$run->artifact_ref.'.sha256');
            }
            DB::table('backup_runs')->where('id', $run->id)->update(['pruned_at' => now()]);
        }

        return $old->count();
    }

    /** Restore the dump into an isolated connection and compare row counts with the manifest. */
    public function restoreInto(string $archive, string $connection): array
    {
        $target = config("database.connections.$connection.database");
        $live = config('database.connections.'.config('database.default').'.database');
        if (! $target || $target === $live || strcasecmp($target, 'Lab') === 0) {
            throw new RuntimeException('Restore hanya ke database terisolasi, bukan database aplikasi.');
        }
        $manifest = $this->verify($archive);
        $zip = new ZipArchive;
        $zip->open($archive, ZipArchive::RDONLY);
        if (config('backup.password')) {
            $zip->setPassword(config('backup.password'));
        }
        $stream = $zip->getStream('database.sql');
        $db = DB::connection($connection);
        foreach ($db->select('SHOW TABLES') as $row) {
            $db->statement('SET FOREIGN_KEY_CHECKS=0');
            $db->statement('DROP TABLE IF EXISTS `'.array_values((array) $row)[0].'`');
        }
        while (($line = fgets($stream)) !== false) {
            $line = rtrim($line, "\n");
            if ($line !== '' && ! str_starts_with($line, '--')) {
                $db->unprepared($line);
            }
        }
        fclose($stream);
        $zip->close();
        $mismatch = [];
        foreach ($manifest['tables'] as $table => $count) {
            $actual = $db->table($table)->count();
            if ($actual !== $count) {
                $mismatch[$table] = "$actual/$count";
            }
        }

        return ['manifest' => $manifest, 'mismatch' => $mismatch];
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $item) {
            is_dir("$dir/$item") ? $this->removeDir("$dir/$item") : unlink("$dir/$item");
        }
        rmdir($dir);
    }

    public static function wib($utc): string
    {
        return $utc ? CarbonImmutable::parse($utc, 'UTC')->setTimezone('Asia/Jakarta')->format('d/m/Y H:i') : '—';
    }
}
