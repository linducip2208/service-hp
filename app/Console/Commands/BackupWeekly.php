<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BackupWeekly extends Command
{
    /**
     * Backup DB otomatis mingguan (mysqldump, fallback JSON per tabel).
     * File: storage/app/backups/auto-{Ymd-His}.sql|.json, retensi 4 file.
     * Plus retensi storage/logs/activity-*.log (>90 hari dihapus).
     * Contoh: php artisan backup:weekly
     *          php artisan backup:weekly --keep=8
     */
    protected $signature = 'backup:weekly {--keep=4 : Jumlah file auto-* terbaru yang dipertahankan}';

    protected $description = 'Backup DB otomatis mingguan ke storage/app/backups/auto-* (retensi default 4 file)';

    public function handle(): int
    {
        $keep = max(1, (int) $this->option('keep'));
        $r = BackupService::run('auto-');
        $deleted = BackupService::pruneAuto($keep);
        $logDeleted = BackupService::pruneActivityLogs(90);

        $verifyOk = (bool) ($r['verify_ok'] ?? false);
        $verifyDetail = (string) ($r['verify_detail'] ?? 'verifikasi tidak tersedia');
        $verifyLabel = $verifyOk ? 'SEHAT' : 'RUSAK';
        $msg = "Backup mingguan selesai: {$r['file']} ({$r['note']}); verifikasi={$verifyLabel} — {$verifyDetail}; retensi auto-*={$keep}, dihapus={$deleted}; activity-log >90 hari dihapus={$logDeleted}.";
        if ($verifyOk) {
            $this->info($msg);
            Log::info('[backup:weekly] '.$msg);
        } else {
            $this->warn($msg);
            Log::warning('[backup:weekly] '.$msg);
        }

        return 0;
    }
}
