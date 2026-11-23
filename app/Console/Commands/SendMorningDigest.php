<?php

namespace App\Console\Commands;

use App\Services\DailyDigestService;
use Illuminate\Console\Command;

class SendMorningDigest extends Command
{
    /**
     * Digest PAGI operasional (read-only, khusus hari ini) per toko → WA owner.
     * Isi: jadwal hari ini (booking + estimasi selesai), tiket mendesak/
     * terlambat, siap-diambil >3 hari, stok habis (=0), posisi kas pagi.
     * TANPA pengingat WA ke customer (anti-spam) dan TANPA auto-ubah data
     * (tanpa eskalasi/batal/nonaktif/tutup garansi).
     * Contoh: php artisan digest:morning
     *          php artisan digest:morning --date=2026-11-24 --store=1
     *          php artisan digest:morning --dry-run (calon penerima saja, tanpa kirim)
     */
    protected $signature = 'digest:morning {--store= : Batasi ke 1 toko (id atau kode)} {--date= : Tanggal operasional Y-m-d, default hari ini} {--dry-run : Tampilkan calon penerima digest pagi tanpa kirim WA}';

    protected $description = 'Kirim digest pagi operasional hari ini ke WA owner tiap toko (read-only)';

    public function handle(): int
    {
        $date = $this->option('date') ?: now()->toDateString();
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
            $this->error('Format --date harus Y-m-d, contoh: --date=' . now()->toDateString());
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

        $results = DailyDigestService::sendMorningForDate($date, $storeId, (bool) $this->option('dry-run'));

        $okCount = 0;
        foreach ($results as $r) {
            if ($r['ok']) {
                $okCount++;
                $this->info("✔ {$r['store']} → {$r['phone']}");
            } else {
                $this->warn("✘ {$r['store']}" . ($r['phone'] ? " → {$r['phone']} (gagal/antri" . ($this->option('dry-run') ? '/dry-run' : '') . ')' : ' (tanpa nomor tujuan)'));
            }
            $this->line("  Booking hari ini: {$r['bookings']} • estimasi selesai: {$r['eta']} • mendesak: {$r['urgent']} • terlambat: {$r['overdue']} • stok habis: {$r['out_of_stock']}");
        }
        $tag = $this->option('dry-run') ? ' (DRY-RUN, tanpa kirim, tanpa ubah data)' : ' (tanpa pengingat customer, tanpa ubah data)';
        $this->line("Digest pagi {$date}: {$okCount}/" . count($results) . " terkirim{$tag}.");

        if ($this->option('dry-run') && isset($results[0]['message'])) {
            $this->line('--- Contoh pesan toko pertama ---');
            $this->line($results[0]['message']);
        }

        return ($this->option('dry-run') || $okCount === count($results)) ? 0 : 1;
    }
}
