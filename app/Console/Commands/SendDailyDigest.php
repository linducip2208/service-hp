<?php

namespace App\Console\Commands;

use App\Services\DailyDigestService;
use Illuminate\Console\Command;

class SendDailyDigest extends Command
{
    /**
     * Rekap kemarin (default) per toko → WA owner pertama tiap toko,
     * plus pengingat H-1 booking besok ke customer (anti-ganda via wa_logs),
     * plus pengingat H-1 piutang jatuh tempo besok (type debt_reminder_h1),
     * plus pengingat ambil HP siap_diambil >3 hari (type pickup_reminder,
     * anti-ganda 7 hari),
     * plus pengingat garansi aktif berakhir H-0 s/d H+7 ke customer
     * (type warranty_expiring, anti-ganda harian),
     * plus tanda-selesai otomatis garansi kedaluwarsa (aktif + end_date
     * < hari ini → selesai + marker [KEDALUWARSA otomatis], idempoten
     * cermin DashboardController@fixWarranty),
     * plus batal-otomatis booking basi menunggu (jadwal >7 hari lewat /
     * tanpa jadwal >14 hari → dibatalkan + WA booking_cancelled 1x),
     * plus eskalasi-otomatis prioritas tiket stagnan >3 hari (rendah→normal→mendesak
     * + ServiceLog, idempoten via updated_at),
     * plus ringkasan tiket aktif ke tiap teknisi aktif (type tech_daily).
     * Contoh: php artisan digest:daily
     *          php artisan digest:daily --date=2026-11-22 --store=1
     *          php artisan digest:daily --dry-run (calon penerima saja, tanpa kirim)
     */
    protected $signature = 'digest:daily {--store= : Batasi ke 1 toko (id atau kode)} {--date= : Tanggal rekap Y-m-d, default kemarin} {--dry-run : Tampilkan calon penerima digest + pengingat booking tanpa kirim WA}';

    protected $description = 'Kirim ringkasan harian (digest) omzet + service ke WA owner tiap toko';

    public function handle(): int
    {
        $date = $this->option('date') ?: now()->subDay()->toDateString();
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
            $this->error('Format --date harus Y-m-d, contoh: --date=' . now()->subDay()->toDateString());
            return 1;
        }

        $storeId = null;
        $storeOpt = trim((string) $this->option('store'));
        if ($storeOpt !== '') {
            $storeId = \App\Models\Store::where('id', $storeOpt)->orWhere('code', $storeOpt)->value('id');
            if (! $storeId) {
                $this->error("Toko '{$storeOpt}' tidak ditemukan (cari by id/kode).");
                return 1;
            }
        }

        $results = DailyDigestService::sendForDate($date, $storeId, (bool) $this->option('dry-run'));

        $okCount = 0;
        $remCount = 0;
        foreach ($results as $r) {
            if ($r['ok']) {
                $okCount++;
                $this->info("✔ {$r['store']} → {$r['phone']}");
            } else {
                $this->warn("✘ {$r['store']}" . ($r['phone'] ? " → {$r['phone']} (gagal/antri" . ($this->option('dry-run') ? '/dry-run' : '') . ')' : ' (tanpa nomor tujuan)'));
            }
            $remCount += $r['reminded'] ?? 0;
            $this->line("  Booking besok ({$r['tomorrow']}): " . ($r['reminded'] ?? 0) . ' diingatkan, ' . ($r['skipped'] ?? 0) . ' dilewati.');
            foreach (($r['reminders'] ?? []) as $d) {
                $this->line("    - {$d['code']} → {$d['phone']} [{$d['status']}]");
            }
            $this->line("  Piutang jatuh tempo besok ({$r['tomorrow']}): " . ($r['debt_reminded'] ?? 0) . ' diingatkan, ' . ($r['debt_skipped'] ?? 0) . ' dilewati.');
            foreach (($r['debt_reminders'] ?? []) as $d) {
                $this->line("    - #{$d['id']} {$d['party']} → {$d['phone']} [{$d['status']}]");
            }
            $this->line('  Siap diambil >3 hari: ' . ($r['pickup_reminded'] ?? 0) . ' diingatkan, ' . ($r['pickup_skipped'] ?? 0) . ' dilewati.');
            foreach (($r['pickup_reminders'] ?? []) as $d) {
                $this->line("    - {$d['ticket']} → {$d['phone']} [{$d['status']}]");
            }
            $this->line('  Garansi berakhir ≤7 hari: ' . ($r['warranty_reminded'] ?? 0) . ' diingatkan, ' . ($r['warranty_skipped'] ?? 0) . ' dilewati.');
            foreach (($r['warranty_reminders'] ?? []) as $d) {
                $this->line("    - W#{$d['id']} {$d['name']} ({$d['end']}) → {$d['phone']} [{$d['status']}]");
            }
            $this->line('  Garansi berakhir 8–30 hari (awal, 1x selamanya): ' . ($r['warranty_early_reminded'] ?? 0) . ' diingatkan, ' . ($r['warranty_early_skipped'] ?? 0) . ' dilewati.');
            foreach (($r['warranty_early_reminders'] ?? []) as $d) {
                $this->line("    - W#{$d['id']} {$d['name']} ({$d['end']}) → {$d['phone']} [{$d['status']}]");
            }
            $staleN = $this->option('dry-run') ? count($r['stale_details'] ?? []) : (int) ($r['stale_cancelled'] ?? 0);
            $this->line('  Booking basi dibatalkan: ' . $staleN . ($this->option('dry-run') ? ' (calon, tanpa ubah)' : ' (WA: ' . ($r['stale_notified'] ?? 0) . ')') . '.');
            foreach (($r['stale_details'] ?? []) as $d) {
                $this->line("    - {$d['code']} → {$d['phone']} [{$d['status']}]");
            }
            $escN = $this->option('dry-run') ? count($r['escalation_details'] ?? []) : (int) ($r['escalated'] ?? 0);
            $this->line('  Eskalasi prioritas (>3 hari stagnan): ' . $escN . ($this->option('dry-run') ? ' (calon, tanpa ubah)' : '') . '.');
            foreach (($r['escalation_details'] ?? []) as $d) {
                $this->line("    - {$d['ticket']} ({$d['from']} → {$d['to']}) [{$d['status']}]");
            }
        }
        $tag = $this->option('dry-run') ? ' (DRY-RUN, tanpa kirim)' : '';
        $debCount = array_sum(array_map(fn ($r) => (int) ($r['debt_reminded'] ?? 0), $results));
        $pickCount = array_sum(array_map(fn ($r) => (int) ($r['pickup_reminded'] ?? 0), $results));
        $warrCount = array_sum(array_map(fn ($r) => (int) ($r['warranty_reminded'] ?? 0), $results));
        $warrEarlyCount = array_sum(array_map(fn ($r) => (int) ($r['warranty_early_reminded'] ?? 0), $results));
        $this->line("Digest {$date}: {$okCount}/" . count($results) . " terkirim{$tag}; pengingat booking: {$remCount} terkirim; pengingat piutang H-1: {$debCount} terkirim; pengingat ambil HP: {$pickCount} terkirim; pengingat garansi ≤7 hari: {$warrCount} terkirim; pengingat awal garansi 8–30 hari: {$warrEarlyCount} terkirim.");

        // Promo kedaluwarsa/kuota-habis dinonaktifkan otomatis sekali per run (di DailyDigestService).
        $first = $results[0] ?? null;
        $promoCount = (int) ($first['promos_deactivated'] ?? 0);
        $promoCodes = $first['promo_codes'] ?? [];
        if ($this->option('dry-run')) {
            $this->line('Promo kedaluwarsa (calon, tanpa ubah): ' . (empty($promoCodes) ? '-' : implode(', ', $promoCodes)));
        } else {
            $this->line('Promo kedaluwarsa dinonaktifkan: ' . $promoCount . (empty($promoCodes) ? '' : ' (' . implode(', ', array_slice($promoCodes, 0, 10)) . (count($promoCodes) > 10 ? ', …' : '') . ')'));
        }

        // Garansi kedaluwarsa ditandai selesai otomatis sekali per run (di DailyDigestService).
        $wExpIds = $first['warranty_expired_ids'] ?? [];
        $wExpCount = $this->option('dry-run') ? count($wExpIds) : (int) ($first['warranty_expired'] ?? 0);
        if ($this->option('dry-run')) {
            $this->line('Garansi kedaluwarsa (calon, tanpa ubah): ' . (empty($wExpIds) ? '-' : 'W#' . implode(', W#', array_slice(array_map('intval', (array) $wExpIds), 0, 10)) . (count($wExpIds) > 10 ? ', …' : '')));
        } else {
            $this->line('Garansi kedaluwarsa ditandai selesai: ' . $wExpCount . (empty($wExpIds) ? '' : ' (W#' . implode(', W#', array_slice(array_map('intval', (array) $wExpIds), 0, 10)) . (count($wExpIds) > 10 ? ', …' : '') . ')'));
        }

        $tech = DailyDigestService::sendTechDailies($date, (bool) $this->option('dry-run'));
        $this->line('Ringkasan teknisi: ' . $tech['sent'] . ' terkirim, ' . $tech['skipped'] . ' dilewati.');
        foreach ($tech['details'] as $d) {
            $this->line("    - {$d['name']} → {$d['phone']} [{$d['status']}]");
        }

        return ($this->option('dry-run') || $okCount === count($results)) ? 0 : 1;
    }
}
