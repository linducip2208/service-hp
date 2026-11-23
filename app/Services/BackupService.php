<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

/**
 * Logika backup terpusat — dipakai BackupController (manual)
 * dan command backup:weekly (otomatis).
 */
class BackupService
{
    public static function dir(): string
    {
        $d = storage_path('app/backups');
        if (! is_dir($d)) {
            mkdir($d, 0755, true);
        }

        return $d;
    }

    /** Cari binary mysqldump: PATH dulu, lalu lokasi umum Laragon/XAMPP. */
    public static function findMysqldump(): ?string
    {
        $fromPath = PHP_OS_FAMILY === 'Windows'
            ? @shell_exec('where mysqldump 2>nul')
            : @shell_exec('command -v mysqldump 2>/dev/null');
        foreach (preg_split('/\r?\n/', (string) $fromPath) ?: [] as $line) {
            $line = trim($line, " \t\r\n\"'");
            if ($line !== '' && is_file($line)) {
                return $line;
            }
        }

        $cands = PHP_OS_FAMILY === 'Windows'
            ? array_merge(
                glob('C:\\laragon\\bin\\mysql\\*\\bin\\mysqldump.exe') ?: [],
                ['C:\\xampp\\mysql\\bin\\mysqldump.exe', 'C:\\laragon\\bin\\mariadb\\*\\bin\\mysqldump.exe']
            )
            : ['/usr/bin/mysqldump', '/usr/local/bin/mysqldump', '/opt/lampp/bin/mysqldump'];

        foreach ($cands as $pattern) {
            foreach (glob($pattern) ?: [] as $g) {
                if (is_file($g)) {
                    return $g;
                }
            }
            if (is_file($pattern)) {
                return $pattern;
            }
        }

        return null;
    }

    /** Jalankan mysqldump, tulis output ke $dest. Null = sukses, string = pesan error. */
    public static function dumpSql(string $bin, string $dest): ?string
    {
        if (config('database.default') !== 'mysql') {
            return 'koneksi DB bukan mysql ('.config('database.default').')';
        }
        $c = config('database.connections.mysql');
        $args = [$bin, '--host='.($c['host'] ?? '127.0.0.1'), '--port='.($c['port'] ?? '3306'),
            '--user='.($c['username'] ?? 'root'), '--routines', '--single-transaction', '--quick'];
        if (($c['password'] ?? '') !== '') {
            $args[] = '--password='.$c['password'];
        }
        $args[] = $c['database'];
        try {
            $p = new Process($args);
            $p->setTimeout(300);
            $p->run();
            if (! $p->isSuccessful()) {
                return trim($p->getErrorOutput() ?: $p->getOutput()) ?: 'mysqldump exit '.($p->getExitCode() ?? '?');
            }
            file_put_contents($dest, $p->getOutput());

            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    public static function dumpJson(string $stamp, string $prefix = 'backup-'): string
    {
        $tables = ['stores', 'products', 'sales', 'service_tickets', 'customers', 'cash_transactions'];
        $data = [
            'app' => 'ServiceHP',
            'type' => 'json-fallback',
            'created_at' => now()->toDateTimeString(),
            'db' => config('database.connections.'.config('database.default').'.database'),
            'tables' => [],
        ];
        foreach ($tables as $t) {
            try {
                $data['tables'][$t] = Schema::hasTable($t) ? DB::table($t)->orderBy('id')->get()->toArray() : ['__skipped' => 'tabel tidak ada'];
            } catch (\Throwable $e) {
                $data['tables'][$t] = ['__error' => $e->getMessage()];
            }
        }
        $file = "{$prefix}{$stamp}.json";
        file_put_contents(
            static::dir().DIRECTORY_SEPARATOR.$file,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        return $file;
    }

    /**
     * Jalankan satu siklus backup.
     * $prefix: 'backup-' (manual) atau 'auto-' (otomatis mingguan).
     * Kembalikan ['file' => ..., 'via' => 'sql'|'json', 'note' => ...,
     *   'verify_ok' => bool, 'verify_detail' => string].
     * Setelah file ditulis, verify() otomatis dijalankan dan hasilnya
     * dicatat di log (info bila sehat, warning bila rusak).
     */
    public static function run(string $prefix = 'backup-', ?string $stamp = null): array
    {
        $stamp ??= now()->format('Ymd-His');
        $bin = static::findMysqldump();

        if ($bin) {
            $file = "{$prefix}{$stamp}.sql";
            $err = static::dumpSql($bin, static::dir().DIRECTORY_SEPARATOR.$file);
            if ($err === null) {
                $result = ['file' => $file, 'via' => 'sql', 'note' => 'via mysqldump'];
            } else {
                $jsonFile = static::dumpJson($stamp, $prefix);

                $result = ['file' => $jsonFile, 'via' => 'json', 'note' => "mysqldump gagal ({$err}); fallback JSON"];
            }
        } else {
            $jsonFile = static::dumpJson($stamp, $prefix);

            $result = ['file' => $jsonFile, 'via' => 'json', 'note' => 'mysqldump tidak ditemukan; fallback JSON'];
        }

        // Verifikasi otomatis pasca-backup (read-only, tanpa restore).
        try {
            $v = static::verify($result['file']);
            $result['verify_ok'] = (bool) ($v['ok'] ?? false);
            $result['verify_detail'] = (string) ($v['detail'] ?? '');
            $logMsg = "[backup] {$prefix}* selesai: {$result['file']} ({$result['note']}); "
                ."verifikasi=".(($result['verify_ok']) ? 'SEHAT' : 'RUSAK')." — {$result['verify_detail']}";
            if ($result['verify_ok']) {
                Log::info($logMsg);
            } else {
                Log::warning($logMsg);
            }
        } catch (\Throwable $e) {
            $result['verify_ok'] = false;
            $result['verify_detail'] = 'Verifikasi otomatis gagal: '.$e->getMessage();
            Log::warning("[backup] {$prefix}* selesai: {$result['file']} ({$result['note']}); ".$result['verify_detail']);
        }

        return $result;
    }

    /**
     * Retensi file otomatis: simpan maksimal $keep file terbaru
     * dengan pola auto-*. Kembalikan jumlah file yang dihapus.
     */
    public static function pruneAuto(int $keep = 4): int
    {
        $files = glob(static::dir().DIRECTORY_SEPARATOR.'auto-*') ?: [];
        $files = array_values(array_filter($files, 'is_file'));
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        $deleted = 0;
        foreach (array_slice($files, $keep) as $old) {
            if (@unlink($old)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Retensi file log aktivitas: hapus storage/logs/activity-*.log
     * yang berumur lebih dari $days hari. Kembalikan jumlah dihapus.
     */
    public static function pruneActivityLogs(int $days = 90): int
    {
        $dir = storage_path('logs');
        $files = glob($dir.DIRECTORY_SEPARATOR.'activity-*.log') ?: [];
        $cutoff = time() - ($days * 86400);
        $deleted = 0;
        foreach ($files as $f) {
            if (is_file($f) && filemtime($f) < $cutoff && @unlink($f)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Verifikasi read-only satu file backup (tanpa restore).
     * SQL: cek header mysqldump + hitung CREATE TABLE / INSERT.
     * JSON: valid JSON + hitung kunci utama (tables + total baris).
     * Kembalikan ['ok' => bool, 'kind' => 'sql'|'json',
     *   'checks' => [...], 'detail' => string].
     */
    public static function verify(string $name): array
    {
        $path = static::dir().DIRECTORY_SEPARATOR.basename($name);
        if (! is_file($path)) {
            return ['ok' => false, 'kind' => 'unknown', 'checks' => [], 'detail' => 'File tidak ditemukan.'];
        }
        $size = (int) filesize($path);
        if ($size <= 0) {
            return ['ok' => false, 'kind' => 'unknown', 'checks' => [], 'detail' => 'File kosong (0 B).'];
        }

        if (str_ends_with($path, '.sql')) {
            $head = (string) @file_get_contents($path, false, null, 0, 32768);
            $hasHeader = (bool) preg_match('/mysqldump|MariaDB dump|MySQL dump/i', $head);
            $createCount = 0;
            $insertCount = 0;
            $fh = @fopen($path, 'rb');
            if ($fh) {
                while (! feof($fh)) {
                    $line = fgets($fh);
                    if ($line === false) {
                        break;
                    }
                    if (preg_match('/^\s*CREATE\s+TABLE/i', $line)) {
                        $createCount++;
                    }
                    if (stripos($line, 'INSERT INTO') !== false) {
                        $insertCount += max(1, substr_count($line, 'INSERT INTO'));
                    }
                }
                fclose($fh);
            }
            $ok = $hasHeader && $createCount > 0;
            $detail = 'Header mysqldump: '.($hasHeader ? 'ADA' : 'TIDAK ADA')
                ."; CREATE TABLE: {$createCount}; INSERT: {$insertCount}; ukuran {$size} B.";
            if (! $hasHeader) {
                $detail .= ' (bukan output mysqldump)';
            } elseif ($createCount === 0) {
                $detail .= ' (tidak ada struktur tabel)';
            } elseif ($insertCount === 0) {
                $detail .= ' (struktur OK, 0 baris INSERT — mungkin DB kosong)';
            }

            return [
                'ok' => $ok,
                'kind' => 'sql',
                'checks' => ['header' => $hasHeader, 'create_table' => $createCount, 'insert' => $insertCount, 'size' => $size],
                'detail' => $detail,
            ];
        }

        if (str_ends_with($path, '.json')) {
            $raw = (string) @file_get_contents($path);
            $data = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [
                    'ok' => false,
                    'kind' => 'json',
                    'checks' => [],
                    'detail' => 'JSON tidak valid: '.json_last_error_msg().'.',
                ];
            }
            $tables = (is_array($data) && isset($data['tables']) && is_array($data['tables'])) ? $data['tables'] : [];
            $tableCount = count($tables);
            $rowTotal = 0;
            foreach ($tables as $rows) {
                if (is_array($rows) && ! isset($rows['__skipped']) && ! isset($rows['__error'])) {
                    $rowTotal += count($rows);
                }
            }
            $keys = implode(', ', array_keys($tables));
            $ok = $tableCount > 0;
            $detail = "JSON valid; tabel/kunci utama: {$tableCount}"
                .($keys !== '' ? " ({$keys})" : '')
                ."; total baris: {$rowTotal}; ukuran {$size} B.";
            if (! $ok) {
                $detail .= ' (blok tables kosong)';
            }

            return [
                'ok' => $ok,
                'kind' => 'json',
                'checks' => ['tables' => $tableCount, 'rows' => $rowTotal, 'size' => $size],
                'detail' => $detail,
            ];
        }

        return ['ok' => false, 'kind' => 'unknown', 'checks' => [], 'detail' => 'Ekstensi tidak dikenal (harus .sql/.json).'];
    }
}
