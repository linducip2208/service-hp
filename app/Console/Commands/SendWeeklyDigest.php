<?php

namespace App\Console\Commands;

use App\Services\DailyDigestService;
use Illuminate\Console\Command;

class SendWeeklyDigest extends Command
{
    /**
     * Rekap 7 hari terakhir (default: kemarin dan 6 hari sebelumnya) per toko
     * → WA owner tiap toko, plus daftar booking 7 hari ke depan per toko.
     * Contoh: php artisan digest:weekly
     *          php artisan digest:weekly --store=1 --dry-run
     *          php artisan digest:weekly --from=2026-11-16 --to=2026-11-22
     */
    protected $signature = 'digest:weekly {--store= : Batasi ke 1 toko (id atau kode)} {--from= : Tanggal awal rekap Y-m-d, default 7 hari ke belakang} {--to= : Tanggal akhir rekap Y-m-d, default kemarin} {--dry-run : Tampilkan calon penerima digest tanpa kirim WA}';

    protected $description = 'Kirim rekap mingguan (7 hari) omzet + service ke WA owner tiap toko';

    public function handle(): int
    {
        $to = $this->option('to') ?: now()->subDay()->toDateString();
        $from = $this->option('from') ?: now()->subDays(7)->toDateString();

        foreach (['from' => $from, 'to' => $to] as $label => $val) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $val)) {
                $this->error("Format --{$label} harus Y-m-d, contoh: --{$label}=" . now()->subDay()->toDateString());
                return 1;
            }
        }
        if ($from > $to) {
            $this->error('--from tidak boleh sesudah --to.');
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

        $results = DailyDigestService::sendForRange($from, $to, $storeId, (bool) $this->option('dry-run'));

        $okCount = 0;
        foreach ($results as $r) {
            if ($r['ok']) {
                $okCount++;
                $this->info("✔ {$r['store']} → {$r['phone']}");
            } else {
                $this->warn("✘ {$r['store']}" . ($r['phone'] ? " → {$r['phone']} (gagal/antri" . ($this->option('dry-run') ? '/dry-run' : '') . ')' : ' (tanpa nomor tujuan)'));
            }
            $this->line('  Booking 7 hari ke depan: ' . ($r['upcoming'] ?? 0) . ' jadwal.');
        }
        $tag = $this->option('dry-run') ? ' (DRY-RUN, tanpa kirim)' : '';
        $this->line("Digest mingguan {$from} s/d {$to}: {$okCount}/" . count($results) . " terkirim{$tag}.");

        $first = $results[0] ?? null;
        $promoCount = (int) ($first['promos_deactivated'] ?? 0);
        $promoCodes = $first['promo_codes'] ?? [];
        if ($this->option('dry-run')) {
            $this->line('Promo kedaluwarsa (calon, tanpa ubah): ' . (empty($promoCodes) ? '-' : implode(', ', $promoCodes)));
        } else {
            $this->line('Promo kedaluwarsa dinonaktifkan: ' . $promoCount . (empty($promoCodes) ? '' : ' (' . implode(', ', array_slice($promoCodes, 0, 10)) . (count($promoCodes) > 10 ? ', …' : '') . ')'));
        }

        // Ajakan servis berkala dormant (>6 bln, maks 1x/90 hr per customer).
        $dormSent = array_sum(array_map(fn ($r) => (int) ($r['dormant_sent'] ?? 0), $results));
        $dormCalon = array_sum(array_map(fn ($r) => count($r['dormant_details'] ?? []), $results));
        if ($this->option('dry-run')) {
            $this->line("Ajakan servis berkala (calon, tanpa kirim): {$dormCalon} customer dormant.");
        } else {
            $this->line("Ajakan servis berkala terkirim: {$dormSent} (calon terlewati 90 hr/tanpa nomor lihat detail dry-run).");
        }

        return ($this->option('dry-run') || $okCount === count($results)) ? 0 : 1;
    }
}
