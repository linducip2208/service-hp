<?php

namespace App\Http\Controllers;

use App\Models\CashTransaction;
use App\Models\Customer;
use App\Models\Debt;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\ServiceTicket;
use App\Services\BackupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use ZipArchive;

/**
 * Backup Data (menu Master) — khusus superadmin/owner.
 * Strategi: dump SQL via mysqldump bila tersedia (deteksi PATH + lokasi
 * umum Laragon/XAMPP), fallback export JSON per tabel penting.
 * File tersimpan di storage/app/backups.
 */
class BackupController extends Controller
{
    private function canManage(): bool
    {
        return in_array(auth()->user()->role ?? '', ['superadmin', 'owner']);
    }

    private function dir(): string
    {
        return BackupService::dir();
    }

    private function safeName(string $file): ?string
    {
        $base = basename($file);
        if ($base !== $file) return null;
        if (!preg_match('/^[A-Za-z0-9._-]+\.(sql|json)$/', $base)) return null;
        return $base;
    }

    public function index()
    {
        abort_unless($this->canManage(), 403);
        $dir = $this->dir();
        $files = collect(glob($dir . DIRECTORY_SEPARATOR . 'backup-*') ?: [])
            ->filter('is_file')
            ->map(fn($f) => [
                'name' => basename($f),
                'size' => filesize($f),
                'size_h' => $this->humanSize((int) filesize($f)),
                'mtime' => filemtime($f),
                'date' => date('d/m/Y H:i', (int) filemtime($f)),
                'type' => str_ends_with($f, '.sql') ? 'SQL (mysqldump)' : 'JSON (fallback)',
                'via' => str_ends_with($f, '.sql') ? 'sql' : 'json',
            ])
            ->sortByDesc('mtime')->values();
        $demo = $this->demoStatus();
        return view('settings.backup', compact('files', 'demo'));
    }

    public function store(Request $request)
    {
        abort_unless($this->canManage(), 403);
        $r = BackupService::run('backup-');
        $label = $r['via'] === 'sql' ? 'Backup SQL berhasil' : 'Backup JSON berhasil';
        $verifyOk = (bool) ($r['verify_ok'] ?? false);
        $verifyDetail = (string) ($r['verify_detail'] ?? '');
        $flash = "{$label}: {$r['file']} ({$r['note']}). Verifikasi: "
            .($verifyOk ? 'SEHAT' : 'RUSAK').($verifyDetail !== '' ? " — {$verifyDetail}" : '');

        // BackupService::run sudah mencatat info/warning ke log; di sini
        // tinggal teruskan status verifikasi ke flash agar terlihat user.
        return back()->with($verifyOk ? 'success' : 'warning', $flash);
    }

    public function download(string $file)
    {
        abort_unless($this->canManage(), 403);
        $name = $this->safeName($file);
        abort_unless($name, 404);
        $path = $this->dir() . DIRECTORY_SEPARATOR . $name;
        abort_unless(is_file($path), 404);
        return response()->download($path);
    }

    public function destroy(string $file)
    {
        abort_unless($this->canManage(), 403);
        $name = $this->safeName($file);
        abort_unless($name, 404);
        $path = $this->dir() . DIRECTORY_SEPARATOR . $name;
        abort_unless(is_file($path), 404);
        @unlink($path);
        return back()->with('success', "Backup {$name} dihapus.");
    }

    /**
     * Verifikasi read-only satu file backup (tanpa restore).
     * SQL: header mysqldump + hitung CREATE TABLE/INSERT.
     * JSON: valid JSON + hitung kunci utama.
     */
    public function verify(string $file)
    {
        abort_unless($this->canManage(), 403);
        $name = $this->safeName($file);
        abort_unless($name, 404);
        $path = $this->dir() . DIRECTORY_SEPARATOR . $name;
        abort_unless(is_file($path), 404);

        $r = BackupService::verify($name);
        $label = $r['ok'] ? 'Sehat' : 'Rusak';
        $msg = "Verifikasi {$name}: {$label} — {$r['detail']}";

        return back()
            ->with($r['ok'] ? 'success' : 'error', $msg)
            ->with('verify_name', $name)
            ->with('verify_ok', $r['ok'])
            ->with('verify_detail', $r['detail']);
    }

    /**
     * Bersihkan Cache Aplikasi (view + cache + config).
     * Khusus superadmin/owner mengikuti guard controller ini. Tanpa migration.
     */
    public function clearCache()
    {
        abort_unless($this->canManage(), 403);
        try {
            Artisan::call('view:clear');
            Artisan::call('cache:clear');
            Artisan::call('config:clear');
        } catch (\Throwable $e) {
            return back()->with('error', 'Gagal membersihkan cache: ' . $e->getMessage());
        }
        return back()->with('success', 'Cache aplikasi dibersihkan (view + cache + config).');
    }

    // ---------- Data Demo Showroom (ON/OFF aman, tanpa migration) ----------

    /**
     * Status data demo: hitung baris DEMO- per kelompok + flag file.
     * Dipakai view untuk guard tombol (Muat hanya bila belum ada).
     */
    private function demoStatus(): array
    {
        $count = function (string $table, string $col): int {
            try {
                return DB::table($table)->where($col, 'like', 'DEMO-%')->count();
            } catch (\Throwable $e) {
                return 0;
            }
        };
        $sales = $count('sales', 'invoice_no');
        $tickets = $count('service_tickets', 'ticket_no');
        $purchases = $count('purchases', 'invoice_no');
        $bookings = $count('bookings', 'code');
        try {
            $cash = DB::table('cash_transactions')->where('description', 'like', 'Demo:%')->count();
        } catch (\Throwable $e) {
            $cash = 0;
        }
        try {
            $expenses = DB::table('expenses')->where('description', 'like', 'Demo:%')->count();
        } catch (\Throwable $e) {
            $expenses = 0;
        }
        $flag = is_file(storage_path('app/demo_seeded'));
        $exists = ($sales + $tickets + $purchases + $bookings) > 0;

        return compact('sales', 'tickets', 'purchases', 'bookings', 'cash', 'expenses', 'flag', 'exists');
    }

    /**
     * Muat Data Demo (ON): jalankan DemoTransactionSeeder.
     * Guard: tolak bila baris DEMO-* sudah ada (idempoten, anti-ganda).
     * Khusus superadmin/owner mengikuti guard controller ini.
     */
    public function demoSeed()
    {
        abort_unless($this->canManage(), 403);
        try {
            $already = DB::table('sales')->where('invoice_no', 'like', 'DEMO-%')->exists();
        } catch (\Throwable $e) {
            $already = false;
        }
        if ($already) {
            return back()->with('warning', 'Data demo sudah ada (DEMO-* terdeteksi). Hapus dulu bila ingin muat ulang.');
        }
        try {
            Artisan::call('db:seed', ['--class' => 'DemoTransactionSeeder', '--force' => true]);
        } catch (\Throwable $e) {
            return back()->with('error', 'Gagal memuat data demo: ' . $e->getMessage());
        }
        $out = trim((string) Artisan::output());
        try {
            $ok = DB::table('sales')->where('invoice_no', 'like', 'DEMO-%')->exists();
        } catch (\Throwable $e) {
            $ok = false;
        }
        if (! $ok) {
            return back()->with('warning', 'Seeder selesai tetapi data DEMO-* tidak terdeteksi. ' . $out);
        }

        return back()->with('success', 'Data demo dimuat (15 penjualan, 8 tiket, 3 pembelian, 2 booking). ' . $out);
    }

    /**
     * Bersihkan Demo (OFF): hapus SEMUA baris DEMO- di semua tabel + hapus
     * flag storage/app/demo_seeded. Konfirmasi ganda: checkbox + ketik
     * "HAPUS DEMO". Wajib backup dulu (peringatan di view).
     * Khusus superadmin/owner mengikuti guard controller ini.
     */
    public function demoClean(Request $request)
    {
        abort_unless($this->canManage(), 403);
        $request->validate([
            'confirm' => 'accepted',
            'confirm_text' => 'required|in:HAPUS DEMO',
        ], [
            'confirm.accepted' => 'Centang konfirmasi penghapusan dulu.',
            'confirm_text.required' => 'Ketik HAPUS DEMO untuk konfirmasi.',
            'confirm_text.in' => 'Teks konfirmasi harus persis: HAPUS DEMO.',
        ]);

        $deleted = [];
        DB::transaction(function () use (&$deleted) {
            $saleIds = DB::table('sales')->where('invoice_no', 'like', 'DEMO-%')->pluck('id');
            $ticketIds = DB::table('service_tickets')->where('ticket_no', 'like', 'DEMO-%')->pluck('id');
            $purchaseIds = DB::table('purchases')->where('invoice_no', 'like', 'DEMO-%')->pluck('id');
            $expenseIds = DB::table('expenses')->where('description', 'like', 'Demo:%')->pluck('id');
            $returnIds = $saleIds->isNotEmpty()
                ? DB::table('sale_returns')->whereIn('sale_id', $saleIds)->pluck('id')
                : collect();

            // Anak dulu (aman FK): kas & hutang/piutang yang merujuk demo.
            $deleted['cash_desc'] = DB::table('cash_transactions')->where('description', 'like', 'Demo:%')->delete();
            $cashRef = 0;
            $refPairs = [
                [Sale::class, $saleIds],
                [ServiceTicket::class, $ticketIds],
                [Purchase::class, $purchaseIds],
            ];
            foreach ($refPairs as [$type, $ids]) {
                if ($ids->isNotEmpty()) {
                    $cashRef += DB::table('cash_transactions')->where('ref_type', $type)->whereIn('ref_id', $ids)->delete();
                }
            }
            if ($expenseIds->isNotEmpty()) {
                $cashRef += DB::table('cash_transactions')->where('ref_type', \App\Models\Expense::class)->whereIn('ref_id', $expenseIds)->delete();
            }
            if ($returnIds->isNotEmpty()) {
                $cashRef += DB::table('cash_transactions')->where('ref_type', \App\Models\SaleReturn::class)->whereIn('ref_id', $returnIds)->delete();
            }
            $deleted['cash_ref'] = $cashRef;

            $debtRef = 0;
            foreach ($refPairs as [$type, $ids]) {
                if ($ids->isNotEmpty()) {
                    $debtRef += DB::table('debts')->where('ref_type', $type)->whereIn('ref_id', $ids)->delete();
                }
            }
            $deleted['debts'] = $debtRef;

            $deleted['warranties'] = $ticketIds->isNotEmpty()
                ? DB::table('warranties')->where('ref_type', ServiceTicket::class)->whereIn('ref_id', $ticketIds)->delete()
                : 0;
            $deleted['spareparts'] = $ticketIds->isNotEmpty()
                ? DB::table('service_spareparts')->whereIn('service_ticket_id', $ticketIds)->delete()
                : 0;
            $deleted['returns'] = $returnIds->count()
                ? DB::table('sale_returns')->whereIn('id', $returnIds)->delete()
                : 0;
            $deleted['sale_payments'] = $saleIds->isNotEmpty()
                ? DB::table('sale_payments')->whereIn('sale_id', $saleIds)->delete()
                : 0;
            $deleted['sale_items'] = $saleIds->isNotEmpty()
                ? DB::table('sale_items')->whereIn('sale_id', $saleIds)->delete()
                : 0;
            $deleted['purchase_items'] = $purchaseIds->isNotEmpty()
                ? DB::table('purchase_items')->whereIn('purchase_id', $purchaseIds)->delete()
                : 0;

            // Induk demo.
            $deleted['sales'] = $saleIds->isNotEmpty()
                ? DB::table('sales')->whereIn('id', $saleIds)->delete()
                : 0;
            $deleted['tickets'] = $ticketIds->isNotEmpty()
                ? DB::table('service_tickets')->whereIn('id', $ticketIds)->delete()
                : 0;
            $deleted['purchases'] = $purchaseIds->isNotEmpty()
                ? DB::table('purchases')->whereIn('id', $purchaseIds)->delete()
                : 0;
            $deleted['bookings'] = DB::table('bookings')->where('code', 'like', 'DEMO-%')->delete();
            $deleted['expenses'] = $expenseIds->isNotEmpty()
                ? DB::table('expenses')->whereIn('id', $expenseIds)->delete()
                : 0;
        });

        // Hapus flag idempoten agar seeder bisa jalan lagi.
        try {
            File::delete(storage_path('app/demo_seeded'));
        } catch (\Throwable $e) {
        }

        $total = array_sum($deleted);
        $detail = collect($deleted)->filter(fn($v) => $v > 0)
            ->map(fn($v, $k) => "{$k}: {$v}")->implode(', ');

        return back()->with('success', "Data demo dibersihkan ({$total} baris + flag demo_seeded). " . ($detail !== '' ? $detail . '.' : 'Tidak ada sisa DEMO-*.'));
    }

    /**
     * Unduh SEMUA CSV dalam satu ZIP (arsip).
     * Scope: SEMUA toko (backup = arsip, bukan laporan toko aktif) — khusus
     * superadmin/owner mengikuti guard controller ini.
     * Format tiap CSV: delimiter `;` + BOM (Excel Indonesia), limit 20.000
     * baris per file, ditulis via chunk agar hemat memori.
     * Arsip: ZipArchive bila tersedia, fallback PharData (.tar) bila tidak.
     */
    public function exportCsv()
    {
        abort_unless($this->canManage(), 403);
        $stamp = now()->format('Ymd_His');
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'backup-csv-' . $stamp . '-' . uniqid();
        @mkdir($tmp, 0777, true);

        $files = [];
        $files['sales.csv'] = [
            ['id', 'invoice_no', 'tanggal', 'toko', 'pelanggan', 'subtotal', 'diskon', 'pajak', 'total', 'metode', 'status', 'dibayar', 'sisa', 'catatan'],
            Sale::with(['store', 'customer'])->orderBy('id'),
            fn($s) => [$s->id, $s->invoice_no, optional($s->created_at)->format('Y-m-d H:i'), $s->store->name ?? '', $s->customer->name ?? 'Umum', (float) $s->subtotal, (float) $s->discount, (float) $s->tax, (float) $s->total, $s->payment_method, $s->payment_status, (float) $s->paid_amount, (float) $s->remaining, $s->note],
        ];
        $files['sale_items.csv'] = [
            ['invoice_no', 'tanggal', 'pelanggan', 'produk', 'imei', 'qty', 'harga', 'diskon', 'subtotal', 'profit'],
            SaleItem::with(['sale.customer', 'product', 'productItem'])->orderBy('id'),
            fn($it) => [$it->sale->invoice_no ?? '', optional($it->sale->created_at ?? null)->format('Y-m-d H:i'), $it->sale->customer->name ?? 'Umum', $it->product->name ?? $it->description ?? '', $it->productItem->imei ?? '', (int) $it->qty, (float) $it->price, (float) $it->discount, (float) $it->subtotal, (float) $it->profit],
        ];
        $files['products.csv'] = [
            ['id', 'sku', 'nama', 'type', 'brand', 'category', 'harga_beli', 'harga_jual', 'min_stock', 'barcode', 'aktif'],
            Product::with(['brand', 'category'])->orderBy('id'),
            fn($p) => [$p->id, $p->sku, $p->name, $p->type, $p->brand->name ?? '', $p->category->name ?? '', (float) $p->purchase_price, (float) $p->selling_price, (int) $p->min_stock, $p->barcode, (int) $p->is_active],
        ];
        $files['product_stocks.csv'] = [
            ['id', 'sku_produk', 'nama_produk', 'toko', 'qty', 'lokasi'],
            ProductStock::with(['product', 'store'])->orderBy('id'),
            fn($st) => [$st->id, $st->product->sku ?? '', $st->product->name ?? '', $st->store->name ?? '', (int) $st->qty, $st->location],
        ];
        $files['service_tickets.csv'] = [
            ['id', 'no_tiket', 'tanggal', 'toko', 'pelanggan', 'merk', 'tipe', 'imei', 'keluhan', 'status', 'persetujuan', 'estimasi_biaya', 'dp', 'total_biaya', 'diambil_pada'],
            ServiceTicket::with(['store', 'customer'])->orderBy('id'),
            fn($t) => [$t->id, $t->ticket_no, optional($t->created_at)->format('Y-m-d H:i'), $t->store->name ?? '', $t->customer->name ?? '', $t->product_brand, $t->product_type, $t->imei, $t->complaint, $t->status, $t->approval_status, (float) $t->estimate_cost, (float) $t->dp, (float) $t->total_cost, optional($t->taken_at)->format('Y-m-d H:i')],
        ];
        $files['customers.csv'] = [
            ['id', 'toko', 'nama', 'telepon', 'alamat', 'kode_member', 'total_belanja'],
            Customer::with('store')->orderBy('id'),
            fn($c) => [$c->id, $c->store->name ?? '', $c->name, $c->phone, $c->address, $c->member_code, (float) $c->total_belanja],
        ];
        $files['debts.csv'] = [
            ['id', 'toko', 'tipe', 'pihak', 'referensi', 'total', 'dibayar', 'sisa', 'jatuh_tempo', 'status'],
            Debt::with('store')->orderBy('id'),
            fn($d) => [$d->id, $d->store->name ?? '', $d->type, $d->party_name, trim(($d->ref_type ?? '') . '#' . ($d->ref_id ?? '')), (float) $d->total, (float) $d->paid, (float) $d->remaining, $d->due_date, $d->status],
        ];
        $files['cash_transactions.csv'] = [
            ['id', 'tanggal', 'toko', 'akun_kas', 'tipe', 'kategori', 'jumlah', 'referensi', 'keterangan'],
            CashTransaction::with(['store', 'cashAccount'])->orderBy('id'),
            fn($c) => [$c->id, optional($c->created_at)->format('Y-m-d H:i'), $c->store->name ?? '', $c->cashAccount->name ?? '', $c->type, $c->category, (float) $c->amount, trim(($c->ref_type ?? '') . '#' . ($c->ref_id ?? '')), $c->description],
        ];
        $files['purchases.csv'] = [
            ['id', 'invoice_no', 'tanggal', 'toko', 'supplier_id', 'total', 'dibayar', 'status', 'catatan'],
            Purchase::with('store')->orderBy('id'),
            fn($p) => [$p->id, $p->invoice_no, optional($p->created_at)->format('Y-m-d H:i'), $p->store->name ?? '', $p->supplier_id, (float) $p->total, (float) $p->paid, $p->status, $p->note],
        ];
        $files['purchase_items.csv'] = [
            ['purchase_id', 'invoice_no', 'produk', 'qty', 'harga', 'subtotal'],
            PurchaseItem::with(['purchase', 'product'])->orderBy('id'),
            fn($it) => [$it->purchase_id, $it->purchase->invoice_no ?? '', $it->product->name ?? '', (int) $it->qty, (float) $it->price, (float) $it->subtotal],
        ];

        $paths = [];
        foreach ($files as $name => [$header, $query, $map]) {
            $paths[$name] = $tmp . DIRECTORY_SEPARATOR . $name;
            $this->writeCsv($paths[$name], $header, $query, $map);
        }

        // Sertakan file settings JSON (yang ada saja) + catatan restore manual.
        $settings = [];
        foreach (['wa.json', 'printers.json', 'branding.json', 'targets.json', 'tech_targets.json', 'budget.json'] as $s) {
            $src = storage_path('app' . DIRECTORY_SEPARATOR . $s);
            if (is_file($src)) {
                $settings['settings/' . $s] = $src;
            }
        }
        $readmePath = $tmp . DIRECTORY_SEPARATOR . 'README_RESTORE.txt';
        file_put_contents($readmePath, implode("\n", [
            'ServiceHP — arsip CSV + settings (' . $stamp . ')',
            '',
            'Isi ZIP: *.csv (data semua toko) + settings/*.json (konfigurasi).',
            '',
            'RESTORE SETTINGS (manual, tanpa migration):',
            '1. Ekstrak ZIP ini.',
            '2. Copy file settings/*.json yang dibutuhkan kembali ke storage/app/',
            '   contoh: settings/wa.json -> storage/app/wa.json',
            '3. Pastikan permission file terbaca web server, lalu refresh halaman.',
            '',
            'Daftar file settings yang ikut dalam ZIP ini:',
            ...array_values(array_map(fn($k) => '- ' . $k, array_keys($settings))),
            '',
        ]));
        $settings['README_RESTORE.txt'] = $readmePath;

        if (class_exists(ZipArchive::class)) {
            $zipPath = $tmp . DIRECTORY_SEPARATOR . "backup-csv_semua-toko_{$stamp}.zip";
            $zip = new ZipArchive();
            abort_unless($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 500, 'Gagal membuat arsip ZIP.');
            foreach ($paths as $name => $path) {
                $zip->addFile($path, $name);
            }
            foreach ($settings as $zipName => $srcPath) {
                $zip->addFile($srcPath, $zipName);
            }
            $zip->close();
            foreach ($paths as $path) {
                @unlink($path);
            }
            @unlink($readmePath);
            return response()->download($zipPath, basename($zipPath))->deleteFileAfterSend(true);
        }

        // Fallback: ekstensi zip tidak tersedia → arsip .tar via PharData (bawaan PHP).
        $tarPath = $tmp . DIRECTORY_SEPARATOR . "backup-csv_semua-toko_{$stamp}.tar";
        $phar = new \PharData($tarPath);
        foreach ($paths as $name => $path) {
            $phar->addFile($path, $name);
            @unlink($path);
        }
        foreach ($settings as $zipName => $srcPath) {
            $phar->addFile($srcPath, $zipName);
        }
        @unlink($readmePath);
        return response()->download($tarPath, basename($tarPath))->deleteFileAfterSend(true);
    }

    /** Tulis CSV (BOM + `;`) dari query via chunk; maksimal 20.000 baris. */
    private function writeCsv(string $path, array $header, $query, callable $map, int $limit = 20000): void
    {
        $out = fopen($path, 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $header, ';');
        $count = 0;
        $query->chunk(1000, function ($rows) use ($out, $map, &$count, $limit) {
            foreach ($rows as $row) {
                if ($count++ >= $limit) return false;
                fputcsv($out, $map($row), ';');
            }
            return true;
        });
        fclose($out);
    }

    // ---------- mysqldump (delegasi ke BackupService, dipakai command otomatis) ----------

    /** Cari binary mysqldump: PATH dulu, lalu lokasi umum Laragon/XAMPP. */
    private function findMysqldump(): ?string
    {
        return BackupService::findMysqldump();
    }

    /** Jalankan mysqldump, tulis output ke $dest. Kembalikan null bila sukses, pesan error bila gagal. */
    private function dumpSql(string $bin, string $dest): ?string
    {
        return BackupService::dumpSql($bin, $dest);
    }

    // ---------- Fallback JSON ----------

    private function dumpJson(string $stamp): string
    {
        return BackupService::dumpJson($stamp, 'backup-');
    }

    private function humanSize(int $b): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $i => $u) {
            if ($b < 1024 || $i === 3) return round($b, 1) . ' ' . $u;
            $b /= 1024;
        }
        return $b . ' B';
    }

    // ---------- Audit File Yatim (storage/app/public) — tanpa migration ----------

    /** Folder yang dikenal & boleh dihapus isinya bila yatim. Di luar ini TIDAK dipindai/dihapus. */
    private function knownDirs(): array
    {
        return ['service', 'signatures', 'bukti-kas', 'bukti-pengeluaran', 'bukti-pembayaran',
            'nota-supplier', 'products', 'consignment', 'tradein', 'secondhand', 'logo'];
    }

    /** Normalisasi path file ke bentuk relatif disk public (tanpa prefix storage/). */
    private function normPath(?string $p): ?string
    {
        $p = trim((string) ($p ?? ''));
        if ($p === '') return null;
        $p = str_replace('\\', '/', $p);
        $p = preg_replace('#^storage/#', '', $p);
        $p = ltrim($p, '/');
        if ($p === '' || str_contains($p, '..')) return null;
        return $p;
    }

    /** Kumpulkan SEMUA path file yang masih dirujuk DB/branding ke dalam set assoc. */
    private function referencedFiles(): array
    {
        $refs = [];
        $add = function ($p) use (&$refs) {
            $n = $this->normPath(is_array($p) ? null : $p);
            if ($n) $refs[$n] = true;
        };
        $addMarkers = function (string $text, string $tag) use (&$refs) {
            if (preg_match_all('/\[' . $tag . ':([^\]]+)\]/', $text, $m)) {
                foreach ($m[1] as $p) {
                    $n = $this->normPath($p);
                    if ($n) $refs[$n] = true;
                }
            }
        };

        // Foto service: kolom JSON photos (termsk service/after/*)
        try {
            \DB::table('service_tickets')->select('id', 'photos')->orderBy('id')->chunk(500, function ($rows) use ($add) {
                foreach ($rows as $r) {
                    $ph = is_string($r->photos ?? null) ? json_decode($r->photos, true) : ($r->photos ?? null);
                    if (is_array($ph)) foreach ($ph as $p) $add($p);
                }
            });
        } catch (\Throwable $e) {}

        // TTD serah terima service: [SIG:path] di service_logs.note
        try {
            \DB::table('service_logs')->select('id', 'note')->where('note', 'like', '%[SIG:%')
                ->orderBy('id')->chunk(500, function ($rows) use ($addMarkers) {
                    foreach ($rows as $r) $addMarkers((string) ($r->note ?? ''), 'SIG');
                });
        } catch (\Throwable $e) {}

        // TTD persetujuan + bukti cicilan/DP: [SIG:] & [BUKTI:] di sales.note
        try {
            \DB::table('sales')->select('id', 'note')
                ->where(fn($q) => $q->where('note', 'like', '%[SIG:%')->orWhere('note', 'like', '%[BUKTI:%'))
                ->orderBy('id')->chunk(500, function ($rows) use ($addMarkers) {
                    foreach ($rows as $r) {
                        $addMarkers((string) ($r->note ?? ''), 'SIG');
                        $addMarkers((string) ($r->note ?? ''), 'BUKTI');
                    }
                });
        } catch (\Throwable $e) {}

        // Bukti pembayaran cicilan: [BUKTI:] di sale_payments.note
        try {
            \DB::table('sale_payments')->select('id', 'note')->where('note', 'like', '%[BUKTI:%')
                ->orderBy('id')->chunk(500, function ($rows) use ($addMarkers) {
                    foreach ($rows as $r) $addMarkers((string) ($r->note ?? ''), 'BUKTI');
                });
        } catch (\Throwable $e) {}

        // Bukti kas & pengeluaran: [BUKTI:] di cash_transactions.description / expenses.description
        try {
            \DB::table('cash_transactions')->select('id', 'description')->where('description', 'like', '%[BUKTI:%')
                ->orderBy('id')->chunk(500, function ($rows) use ($addMarkers) {
                    foreach ($rows as $r) $addMarkers((string) ($r->description ?? ''), 'BUKTI');
                });
        } catch (\Throwable $e) {}
        try {
            \DB::table('expenses')->select('id', 'description')->where('description', 'like', '%[BUKTI:%')
                ->orderBy('id')->chunk(500, function ($rows) use ($addMarkers) {
                    foreach ($rows as $r) $addMarkers((string) ($r->description ?? ''), 'BUKTI');
                });
        } catch (\Throwable $e) {}

        // Nota supplier: [BUKTI:] di purchases.note
        try {
            \DB::table('purchases')->select('id', 'note')->where('note', 'like', '%[BUKTI:%')
                ->orderBy('id')->chunk(500, function ($rows) use ($addMarkers) {
                    foreach ($rows as $r) $addMarkers((string) ($r->note ?? ''), 'BUKTI');
                });
        } catch (\Throwable $e) {}

        // Foto konsinyasi & secondhand: [FOTO:] di stock_movements.note
        try {
            \DB::table('stock_movements')->select('id', 'note')->where('note', 'like', '%[FOTO:%')
                ->orderBy('id')->chunk(500, function ($rows) use ($addMarkers) {
                    foreach ($rows as $r) $addMarkers((string) ($r->note ?? ''), 'FOTO');
                });
        } catch (\Throwable $e) {}

        // Foto tukar tambah: [FOTO:] di trade_ins.condition_note
        try {
            if (\Schema::hasTable('trade_ins')) {
                \DB::table('trade_ins')->select('id', 'condition_note')->where('condition_note', 'like', '%[FOTO:%')
                    ->orderBy('id')->chunk(500, function ($rows) use ($addMarkers) {
                        foreach ($rows as $r) $addMarkers((string) ($r->condition_note ?? ''), 'FOTO');
                    });
            }
        } catch (\Throwable $e) {}

        // Foto produk: products.photo
        try {
            \DB::table('products')->select('id', 'photo')->whereNotNull('photo')
                ->orderBy('id')->chunk(500, function ($rows) use ($add) {
                    foreach ($rows as $r) $add($r->photo ?? null);
                });
        } catch (\Throwable $e) {}

        // Logo toko: daftar di branding.json + logo/{id}.png milik toko yang masih ada
        try {
            $f = storage_path('app/branding.json');
            if (is_file($f)) {
                $all = json_decode((string) file_get_contents($f), true) ?: [];
                foreach ($all as $b) {
                    if (is_array($b) && !empty($b['logo'])) $add($b['logo']);
                }
            }
        } catch (\Throwable $e) {}
        try {
            $ids = \DB::table('stores')->pluck('id');
            foreach ($ids as $id) $refs['logo/' . $id . '.png'] = true;
        } catch (\Throwable $e) {}

        return $refs;
    }

    /** Pindai disk public: hanya file di dalam folder yang dikenal (lewati .gitignore & dotfile). */
    private function scanPublicFiles(): array
    {
        $base = storage_path('app/public');
        $known = $this->knownDirs();
        $out = [];
        if (!is_dir($base)) return $out;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $file) {
            if (!$file->isFile()) continue;
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
            $rel = ltrim($rel, '/');
            $base_name = basename($rel);
            // JANGAN sentuh .gitignore / dotfile
            if ($base_name === '.gitignore' || str_starts_with($base_name, '.')) continue;
            $top = explode('/', $rel)[0] ?? '';
            if (!in_array($top, $known, true)) continue;
            $out[] = ['rel' => $rel, 'top' => $top, 'size' => (int) $file->getSize(), 'mtime' => (int) $file->getMTime()];
        }
        usort($out, fn($a, $b) => strcmp($a['rel'], $b['rel']));
        return $out;
    }

    /** Halaman audit file yatim: scan disk vs referensi DB. Khusus superadmin/owner. */
    public function storage()
    {
        abort_unless($this->canManage(), 403);
        $files = $this->scanPublicFiles();
        $refs = $this->referencedFiles();
        $known = $this->knownDirs();
        $orphans = [];
        $usedCount = 0;
        foreach ($files as $f) {
            if (isset($refs[$f['rel']])) {
                $usedCount++;
            } else {
                $f['size_h'] = $this->humanSize($f['size']);
                $f['date'] = date('d/m/Y H:i', $f['mtime']);
                $orphans[] = $f;
            }
        }
        $perFolder = [];
        foreach ($known as $d) $perFolder[$d] = ['total' => 0, 'orphan' => 0, 'orphan_size' => 0];
        foreach ($files as $f) {
            if (isset($perFolder[$f['top']])) $perFolder[$f['top']]['total']++;
        }
        $orphanBytes = 0;
        foreach ($orphans as $o) {
            if (isset($perFolder[$o['top']])) {
                $perFolder[$o['top']]['orphan']++;
                $perFolder[$o['top']]['orphan_size'] += $o['size'];
            }
            $orphanBytes += $o['size'];
        }
        foreach ($perFolder as $k => $v) $perFolder[$k]['orphan_size_h'] = $this->humanSize($v['orphan_size']);
        return view('settings.storage', [
            'files' => $files, 'orphans' => $orphans, 'perFolder' => $perFolder,
            'usedCount' => $usedCount, 'orphanBytesH' => $this->humanSize($orphanBytes),
        ]);
    }

    /** Hapus file yatim terpilih: hanya yang dicentang + masih yatim + di folder dikenal. */
    public function storageDestroy(Request $request)
    {
        abort_unless($this->canManage(), 403);
        $files = (array) $request->input('files', []);
        abort_if(empty($files), 422, 'Pilih dulu file yang mau dihapus.');
        $request->validate(['confirm' => 'accepted'], ['confirm.accepted' => 'Centang konfirmasi penghapusan dulu.']);

        $base = realpath(storage_path('app/public')) ?: storage_path('app/public');
        $known = $this->knownDirs();
        $refs = $this->referencedFiles(); // hitung ulang: hanya hapus yang MASIH yatim
        $deleted = 0;
        $skipped = [];
        foreach (array_slice($files, 0, 500) as $raw) {
            $rel = $this->normPath($raw);
            if (!$rel) { $skipped[] = $raw . ' (path tidak valid)'; continue; }
            if (basename($rel) === '.gitignore' || str_starts_with(basename($rel), '.')) {
                $skipped[] = $rel . ' (file sistem, dilindungi)'; continue;
            }
            $top = explode('/', $rel)[0] ?? '';
            if (!in_array($top, $known, true)) { $skipped[] = $rel . ' (di luar folder dikenal)'; continue; }
            if (isset($refs[$rel])) { $skipped[] = $rel . ' (masih dirujuk DB, batal dihapus)'; continue; }
            $abs = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            $real = realpath($abs);
            if (!$real || !str_starts_with($real, $base) || !is_file($real)) {
                $skipped[] = $rel . ' (tidak ditemukan)'; continue;
            }
            try {
                \Storage::disk('public')->delete($rel);
                $deleted++;
            } catch (\Throwable $e) {
                $skipped[] = $rel . ' (gagal: ' . $e->getMessage() . ')';
            }
        }
        $msg = "File yatim dihapus: {$deleted}.";
        if ($skipped) $msg .= ' Dilewati (' . count($skipped) . '): ' . implode(' | ', array_slice($skipped, 0, 10));
        return back()->with($deleted > 0 ? 'success' : 'error', $msg);
    }
}
