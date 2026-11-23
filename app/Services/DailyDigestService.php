<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Debt;
use App\Models\Promo;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\ServiceLog;
use App\Models\ServiceTicket;
use App\Models\Store;
use App\Models\User;
use App\Models\Warranty;

/**
 * Ringkasan harian otomatis (digest) — dipakai ulang oleh:
 * - Artisan command `digest:daily` (jadwal 21:00)
 * - SaleController@closingDigest (tombol "Kirim Digest Sekarang")
 *
 * Tanpa migration/tabel baru. Log pengiriman tetap lewat WaService (tabel wa_logs bila ada).
 */
class DailyDigestService
{
    /**
     * Hitung rekap 1 toko untuk 1 tanggal. Logika inti sama dengan
     * SaleController@closingData (omzet, per metode, diskon, retur,
     * kas masuk/keluar) + statistik tiket service.
     */
    public static function dataForStore(?int $storeId, string $date): array
    {
        $saleScope = Sale::query()
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->whereDate('created_at', $date);

        $omzet = (clone $saleScope)->sum('total');
        $diskon = (clone $saleScope)->sum('discount');
        $trxCount = (clone $saleScope)->count();

        $perMetode = SalePayment::query()
            ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->when($storeId, fn ($q) => $q->where('sales.store_id', $storeId))
            ->whereDate('sale_payments.created_at', $date)
            ->groupBy('sale_payments.method')
            ->selectRaw('sale_payments.method, SUM(sale_payments.amount) as total')
            ->pluck('total', 'method');

        $retur = 0;
        try {
            if (\Schema::hasTable('sale_returns')) {
                $retur = \DB::table('sale_returns')
                    ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
                    ->whereDate('created_at', $date)->sum('amount');
            }
        } catch (\Throwable $e) {
        }

        $kasMasuk = $kasKeluar = 0;
        try {
            if (\Schema::hasTable('cash_transactions')) {
                $kasMasuk = \DB::table('cash_transactions')
                    ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
                    ->where('type', 'masuk')->whereDate('created_at', $date)->sum('amount');
                $kasKeluar = \DB::table('cash_transactions')
                    ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
                    ->where('type', 'keluar')->whereDate('created_at', $date)->sum('amount');
            }
        } catch (\Throwable $e) {
        }

        $tiketMasuk = $tiketSiap = $tiketDiserahkan = 0;
        try {
            if (\Schema::hasTable('service_tickets')) {
                $base = ServiceTicket::query()
                    ->when($storeId, fn ($q) => $q->where('store_id', $storeId));
                $tiketMasuk = (clone $base)->whereDate('created_at', $date)->count();
                $tiketSiap = (clone $base)->whereIn('status', ['siap_diambil', 'selesai'])
                    ->whereDate('updated_at', $date)->count();
                $tiketDiserahkan = (clone $base)->where('status', 'diserahkan')
                    ->whereDate('updated_at', $date)->count();
            }
        } catch (\Throwable $e) {
        }

        // Stok menipis: product_stocks join products, qty <= min EFEKTIF
        // (COALESCE(product_stocks.min_stock, products.min_stock)), scope toko.
        $lowStocks = collect();
        $lowStockCount = 0;
        try {
            if (\Schema::hasTable('product_stocks') && \Schema::hasTable('products')) {
                $lowBase = \DB::table('product_stocks')
                    ->join('products', 'products.id', '=', 'product_stocks.product_id')
                    ->when($storeId, fn ($q) => $q->where('product_stocks.store_id', $storeId))
                    ->whereRaw('product_stocks.qty <= COALESCE(product_stocks.min_stock, products.min_stock)');
                $lowStockCount = (int) (clone $lowBase)->count();
                $lowStocks = (clone $lowBase)
                    ->select('products.name', 'product_stocks.qty')
                    ->orderBy('product_stocks.qty')->limit(10)->get();
            }
        } catch (\Throwable $e) {
        }

        // Hutang/piutang jatuh tempo ≤7 hari (termasuk lewat jatuh tempo):
        // snapshot saat ini, bukan kumulatif harian. Pola sama dgn
        // DebtController@index ($dueSoon) & DashboardController (alert).
        $debtDues = self::dueDebts($storeId);
        $debtDueCount = $debtDues->count();
        try {
            if (\Schema::hasTable('debts')) {
                $debtDueCount = (int) Debt::query()
                    ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
                    ->where('status', '!=', 'lunas')
                    ->whereNotNull('due_date')
                    ->whereDate('due_date', '<=', \Carbon\Carbon::today()->addDays(7)->toDateString())
                    ->count();
            }
        } catch (\Throwable $e) {
        }

        return compact(
            'omzet', 'diskon', 'trxCount', 'perMetode',
            'retur', 'kasMasuk', 'kasKeluar',
            'tiketMasuk', 'tiketSiap', 'tiketDiserahkan',
            'lowStocks', 'lowStockCount',
            'debtDues', 'debtDueCount'
        );
    }

    /** Susun pesan WA digest dari array dataForStore(). */
    public static function messageForStore(string $storeName, string $date, array $data): string
    {
        $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');
        $metodeLines = collect($data['perMetode'] ?? [])
            ->map(fn ($t, $m) => '- ' . strtoupper((string) $m) . ': ' . $rp($t))
            ->implode("\n");

        $low = collect($data['lowStocks'] ?? []);
        $lowCount = (int) ($data['lowStockCount'] ?? $low->count());
        $lowBlock = 'Stok menipis (' . $lowCount . '):';
        if ($low->isEmpty()) {
            $lowBlock .= "\n-";
        } else {
            $lowBlock .= "\n" . $low->take(10)->map(
                fn ($r) => '- ' . (is_array($r) ? ($r['name'] ?? '?') : ($r->name ?? '?'))
                    . ' (sisa ' . (is_array($r) ? ($r['qty'] ?? 0) : ($r->qty ?? 0)) . ')'
            )->implode("\n");
            if ($lowCount > 10) {
                $lowBlock .= "\n… +" . ($lowCount - 10) . ' lainnya';
            }
        }

        $debtBlock = self::debtDueBlock(
            collect($data['debtDues'] ?? []),
            (int) ($data['debtDueCount'] ?? collect($data['debtDues'] ?? [])->count())
        );

        return '📊 *DIGEST HARIAN* ' . $date . "\n"
            . 'Toko: ' . $storeName . "\n"
            . 'Omzet (' . ($data['trxCount'] ?? 0) . ' trx): ' . $rp($data['omzet'] ?? 0) . "\n"
            . ($metodeLines ? $metodeLines . "\n" : '')
            . 'Diskon: ' . $rp($data['diskon'] ?? 0) . "\n"
            . 'Retur: ' . $rp($data['retur'] ?? 0) . "\n"
            . 'Kas masuk: ' . $rp($data['kasMasuk'] ?? 0) . "\n"
            . 'Kas keluar: ' . $rp($data['kasKeluar'] ?? 0) . "\n"
            . 'Service masuk: ' . ($data['tiketMasuk'] ?? 0)
            . ' • siap: ' . ($data['tiketSiap'] ?? 0)
            . ' • diserahkan: ' . ($data['tiketDiserahkan'] ?? 0) . "\n"
            . $lowBlock . "\n"
            . $debtBlock;
    }

    /**
     * Hutang/piutang belum lunas dengan due_date ≤ hari ini + 7 hari
     * (termasuk yang sudah lewat jatuh tempo). Snapshot — bukan per tanggal.
     */
    public static function dueDebts(?int $storeId, int $limit = 5): \Illuminate\Support\Collection
    {
        try {
            if (! \Schema::hasTable('debts')) return collect();

            return Debt::query()
                ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
                ->where('status', '!=', 'lunas')
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<=', \Carbon\Carbon::today()->addDays(7)->toDateString())
                ->orderBy('due_date')->limit($limit)
                ->select('party_name', 'remaining', 'due_date')
                ->get();
        } catch (\Throwable $e) {
            return collect();
        }
    }

    /** Blok hutang/piutang jatuh tempo ≤7 hari untuk ditempel di pesan digest owner (max 5 + count). */
    public static function debtDueBlock(\Illuminate\Support\Collection $debts, int $totalCount): string
    {
        $block = 'Hutang/piutang jatuh tempo ≤7 hari (' . $totalCount . '):';
        if ($debts->isEmpty()) {
            return $block . "\n-";
        }
        $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');
        $shown = $debts->take(5);
        $block .= "\n" . $shown->map(function ($d) use ($rp) {
            $nama = is_array($d) ? ($d['party_name'] ?? '?') : ($d->party_name ?? '?');
            $sisa = is_array($d) ? ($d['remaining'] ?? 0) : ($d->remaining ?? 0);
            $raw = is_array($d) ? ($d['due_date'] ?? null) : ($d->due_date ?? null);
            try {
                $tgl = $raw ? \Carbon\Carbon::parse($raw)->format('d/m') : '-';
            } catch (\Throwable $e) {
                $tgl = '-';
            }

            return '- ' . $nama . ' ' . $rp($sisa) . ' (' . $tgl . ')';
        })->implode("\n");
        if ($totalCount > $shown->count()) {
            $block .= "\n… +" . ($totalCount - $shown->count()) . ' lainnya';
        }

        return $block;
    }

    /**
     * Cari nomor WA penerima digest 1 toko:
     * owner pertama yang terikat toko (pivot store_user / active_store_id),
     * lalu owner global pertama, lalu telepon toko (fallback).
     * @return array{0:string,1:string} [phone, label]
     */
    public static function recipientForStore(?int $storeId): array
    {
        // Batch: 1 query nama+telepon toko (dulu 2x whereKey->value per toko).
        $storeRow = $storeId ? Store::whereKey($storeId)->first(['name', 'phone']) : null;
        $storeName = $storeId ? ($storeRow?->name ?? ('#' . $storeId)) : 'Semua toko';
        $storePhone = $storeId ? ($storeRow?->phone ?? '') : '';

        if ($storeId) {
            try {
                $ownerToko = User::where('role', 'owner')
                    ->where(function ($q) use ($storeId) {
                        $q->where('active_store_id', $storeId)
                            ->orWhereHas('stores', fn ($qq) => $qq->where('stores.id', $storeId));
                    })
                    ->orderBy('id')->first();
                if ($ownerToko?->phone) return [$ownerToko->phone, 'owner ' . $ownerToko->name . ' (' . $storeName . ')'];
            } catch (\Throwable $e) {
            }
        }

        $ownerGlobal = User::where('role', 'owner')->orderBy('id')->first();
        if ($ownerGlobal?->phone) return [$ownerGlobal->phone, 'owner ' . $ownerGlobal->name . ' (' . $storeName . ')'];
        if ($storePhone) return [$storePhone, 'telepon toko ' . $storeName];

        return ['', ''];
    }

    /**
     * Kandidat promo kedaluwarsa/habis yang masih aktif:
     * end_date < hari ini (non-null) ATAU (quota > 0 [0 = tanpa batas] dan used >= quota).
     * Idempoten: hanya yang is_active=true yang dikembalikan.
     */
    public static function expiredPromoCandidates(?int $storeId = null): \Illuminate\Support\Collection
    {
        try {
            $today = \Carbon\Carbon::today()->toDateString();

            return Promo::query()
                ->when($storeId, fn ($q) => $q->where(fn ($qq) => $qq->where('store_id', $storeId)->orWhereNull('store_id')))
                ->where('is_active', true)
                ->where(function ($q) use ($today) {
                    $q->where(function ($qq) use ($today) {
                        $qq->whereNotNull('end_date')->whereDate('end_date', '<', $today);
                    })->orWhere(function ($qq) {
                        $qq->where('quota', '>', 0)->whereColumn('used', '>=', 'quota');
                    });
                })
                ->orderBy('id')
                ->get();
        } catch (\Throwable $e) {
            return collect();
        }
    }

    /**
     * Nonaktifkan promo kedaluwarsa/kuota-habis. Aman & idempoten
     * (hanya menyentuh is_active=true; update massal satu query).
     * Bila $dryRun, hanya kembalikan calon tanpa mengubah data.
     *
     * @return array{count:int,codes:array<int,string>}
     */
    public static function deactivateExpiredPromos(bool $dryRun = false, ?int $storeId = null): array
    {
        $candidates = self::expiredPromoCandidates($storeId);
        $codes = $candidates->pluck('code')->filter()->values()->all();

        if ($dryRun || $candidates->isEmpty()) {
            return ['count' => $dryRun ? 0 : 0, 'codes' => $codes, 'candidates' => $codes];
        }

        try {
            Promo::query()->whereIn('id', $candidates->pluck('id')->all())->update(['is_active' => false]);

            return ['count' => count($codes), 'codes' => $codes, 'candidates' => $codes];
        } catch (\Throwable $e) {
            return ['count' => 0, 'codes' => [], 'candidates' => $codes];
        }
    }

    /** Satu baris ringkasan promo untuk ditempel di pesan digest owner. */
    public static function promoDeactivatedBlock(array $promoResult, bool $dryRun = false): string
    {
        $codes = $promoResult['codes'] ?? $promoResult['candidates'] ?? [];
        $n = $dryRun ? count($codes) : (int) ($promoResult['count'] ?? 0);
        if ($n <= 0 && empty($codes)) {
            return 'Promo kedaluwarsa dinonaktifkan: -';
        }
        $label = $dryRun ? 'calon' : (string) $n;
        $list = empty($codes) ? '' : ' (' . implode(', ', array_slice($codes, 0, 10)) . (count($codes) > 10 ? ', …' : '') . ')';

        return 'Promo kedaluwarsa dinonaktifkan: ' . $label . $list . ($dryRun ? ' [dry-run]' : '');
    }

    /**
     * Kandidat garansi kedaluwarsa: status=aktif dengan end_date < hari ini
     * (non-null). Idempoten: hanya yang masih aktif yang dikembalikan.
     * Scope toko mengikuti expiringWarranties (customer toko tsb ATAU ref
     * tiket service toko tsb); tanpa filter = semua toko termasuk yatim.
     */
    public static function expiredWarrantyCandidates(?int $storeId = null): \Illuminate\Support\Collection
    {
        try {
            if (! \Schema::hasTable('warranties')) return collect();
            $today = \Carbon\Carbon::today()->toDateString();

            return Warranty::query()
                ->with('customer')
                ->when($storeId, fn ($q) => $q->where(function ($w) use ($storeId) {
                    $w->whereHas('customer', fn ($c) => $c->where('store_id', $storeId))
                        ->orWhere(function ($x) use ($storeId) {
                            $x->where('ref_type', 'service')
                                ->whereIn('ref_id', ServiceTicket::where('store_id', $storeId)->select('id'));
                        });
                }))
                ->where('status', 'aktif')
                ->whereNotNull('end_date')
                ->whereDate('end_date', '<', $today)
                ->orderBy('end_date')->orderBy('id')
                ->get();
        } catch (\Throwable $e) {
            return collect();
        }
    }

    /**
     * Tandai selesai otomatis garansi kedaluwarsa (aktif + end_date < hari
     * ini → selesai + append marker ke claim_note). Cermin persis
     * DashboardController@fixWarranty (tombol manual). Idempoten: hanya
     * status=aktif yang disentuh; sekali jadi selesai tidak diproses lagi;
     * marker tidak diduplikasi bila claim_note sudah memuatnya.
     * Tanpa migration: hanya kolom status + claim_note yang sudah ada.
     * Bila $dryRun, hanya kembalikan calon tanpa mengubah data.
     *
     * @return array{count:int,ids:array<int,int>,candidates:array<int,int>}
     */
    public static function autoExpireWarranties(bool $dryRun = false, ?int $storeId = null): array
    {
        $candidates = self::expiredWarrantyCandidates($storeId);
        $ids = $candidates->pluck('id')->map(fn ($v) => (int) $v)->all();

        if ($dryRun || empty($ids)) {
            return ['count' => 0, 'ids' => $dryRun ? $ids : [], 'candidates' => $ids];
        }

        $count = 0;
        $done = [];
        $marker = '[KEDALUWARSA otomatis]';
        foreach ($candidates as $w) {
            try {
                if (($w->status ?? null) !== 'aktif') continue;
                $end = $w->end_date ? \Carbon\Carbon::parse($w->end_date)->toDateString() : null;
                if ($end === null || $end >= \Carbon\Carbon::today()->toDateString()) continue;
                $note = (string) ($w->claim_note ?? '');
                if (! str_contains($note, $marker)) {
                    $note = trim($note . ' ' . $marker);
                }
                $w->status = 'selesai';
                $w->claim_note = $note !== '' ? $note : null;
                $w->save();
                $count++;
                $done[] = (int) $w->id;
            } catch (\Throwable $e) {
                continue;
            }
        }

        return ['count' => $count, 'ids' => $done, 'candidates' => $ids];
    }

    /** Satu baris ringkasan garansi kedaluwarsa untuk ditempel di pesan digest owner. */
    public static function warrantyExpiredBlock(array $warrantyResult, bool $dryRun = false): string
    {
        $ids = $warrantyResult['ids'] ?? $warrantyResult['candidates'] ?? [];
        $n = $dryRun ? count($ids) : (int) ($warrantyResult['count'] ?? 0);
        if ($n <= 0 && empty($ids)) {
            return 'Garansi kedaluwarsa ditandai selesai: -';
        }
        $label = $dryRun ? 'calon' : (string) $n;
        $list = empty($ids) ? '' : ' (W#' . implode(', W#', array_slice(array_map('intval', (array) $ids), 0, 10)) . (count($ids) > 10 ? ', …' : '') . ')';

        return 'Garansi kedaluwarsa ditandai selesai: ' . $label . $list . ($dryRun ? ' [dry-run]' : '');
    }

    /**
     * Booking BASI (kandidat batal otomatis): status menunggu dengan
     * - schedule_date lewat >7 hari (schedule_date < sekarang - 7 hari), ATAU
     * - tanpa schedule_date dan created_at >14 hari lalu.
     * Tanpa migration: hanya baca kolom yang sudah ada.
     */
    public static function staleBookingCandidates(?int $storeId): \Illuminate\Support\Collection
    {
        try {
            $schedLimit = \Carbon\Carbon::now()->subDays(7);
            $createdLimit = \Carbon\Carbon::now()->subDays(14);

            return Booking::query()
                ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
                ->where('status', 'menunggu')
                ->where(function ($q) use ($schedLimit, $createdLimit) {
                    $q->where(function ($qq) use ($schedLimit) {
                        $qq->whereNotNull('schedule_date')->where('schedule_date', '<', $schedLimit);
                    })->orWhere(function ($qq) use ($createdLimit) {
                        $qq->whereNull('schedule_date')->where('created_at', '<', $createdLimit);
                    });
                })
                ->orderBy('schedule_date')->orderBy('created_at')
                ->get();
        } catch (\Throwable $e) {
            return collect();
        }
    }

    /** Pesan WA sopan 1x untuk pembatalan otomatis booking basi. */
    public static function bookingCancelMessage(Booking $booking): string
    {
        $jadwal = $booking->schedule_date
            ? \Carbon\Carbon::parse($booking->schedule_date)->format('d/m/Y H:i') : '-';
        $nama = $booking->customer_name ?: 'Pelanggan';
        $device = $booking->device ?: 'perangkat';

        return "Halo {$nama}, booking service *{$booking->code}* ({$device}) jadwal {$jadwal} kami batalkan otomatis karena sudah lama tidak ada konfirmasi/kedatangan. Maaf atas ketidaknyamanannya — silakan booking ulang / hubungi kami bila masih membutuhkan layanan. Terima kasih. [{$booking->code}]";
    }

    /**
     * Anti-ganda WA pembatalan: sudah ada wa_logs type booking_cancelled
     * yang memuat kode booking (kapan pun, bukan hanya hari ini) → 1x selamanya.
     */
    public static function alreadyCancelNotified(Booking $booking): bool
    {
        try {
            if (! \Schema::hasTable('wa_logs')) return false;

            return \DB::table('wa_logs')
                ->where('type', 'booking_cancelled')
                ->where('message', 'like', '%[' . $booking->code . ']%')
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Batalkan otomatis booking basi 1 toko. Idempoten: hanya status menunggu
     * yang disentuh; sekali jadi dibatalkan tidak akan diproses lagi.
     * WA dikirim maksimal 1x per booking (cek alreadyCancelNotified); tanpa
     * nomor HP → tetap dibatalkan, dicatat sebagai skip tanpa WA.
     *
     * @return array{cancelled:int,notified:int,details:array<int,array{code:string,phone:string,status:string}>}
     */
    public static function autoCancelStaleBookings(?int $storeId, bool $dryRun = false): array
    {
        $cancelled = 0;
        $notified = 0;
        $details = [];

        // Batch anti-ganda: 1 query semua pesan booking_cancelled (dulu exists per booking).
        $cancelMsgs = collect();
        try {
            if (\Schema::hasTable('wa_logs')) {
                $cancelMsgs = \DB::table('wa_logs')->where('type', 'booking_cancelled')->pluck('message');
            }
        } catch (\Throwable $e) {
        }

        foreach (self::staleBookingCandidates($storeId) as $b) {
            $phone = WaService::normalize((string) ($b->customer_phone ?? ''));
            if ($dryRun) {
                $details[] = [
                    'code' => $b->code,
                    'phone' => $phone !== '' ? $phone : (string) ($b->customer_phone ?? ''),
                    'status' => $phone === '' ? 'calon (dry-run, tanpa nomor)' : 'calon (dry-run)',
                ];
                continue;
            }
            try {
                $b->status = 'dibatalkan';
                $b->save();
                $cancelled++;
            } catch (\Throwable $e) {
                $details[] = ['code' => $b->code, 'phone' => $phone, 'status' => 'skip: gagal update'];
                continue;
            }
            if ($phone === '') {
                $details[] = ['code' => $b->code, 'phone' => (string) ($b->customer_phone ?? ''), 'status' => 'dibatalkan (tanpa nomor, tanpa WA)'];
                continue;
            }
            $alreadyNotified = $cancelMsgs->contains(fn ($m) => str_contains((string) $m, '[' . $b->code . ']'));
            if ($alreadyNotified) {
                $details[] = ['code' => $b->code, 'phone' => $phone, 'status' => 'dibatalkan (WA sudah pernah dikirim)'];
                continue;
            }
            try {
                $ok = WaService::send($phone, self::bookingCancelMessage($b), 'booking_cancelled', $b->store_id);
            } catch (\Throwable $e) {
                $ok = false;
            }
            if ($ok) {
                $notified++;
                $details[] = ['code' => $b->code, 'phone' => $phone, 'status' => 'dibatalkan + WA terkirim 1x'];
            } else {
                $details[] = ['code' => $b->code, 'phone' => $phone, 'status' => 'dibatalkan (WA gagal/antri)'];
            }
        }

        return ['cancelled' => $cancelled, 'notified' => $notified, 'details' => $details];
    }

    /** Satu-dua baris ringkasan pembatalan basi untuk ditempel di pesan digest owner. */
    public static function staleCancelBlock(array $cancelResult, bool $dryRun = false): string
    {
        $details = $cancelResult['details'] ?? [];
        if (empty($details) && (int) ($cancelResult['cancelled'] ?? 0) === 0) {
            return 'Booking basi dibatalkan otomatis: -';
        }
        $label = $dryRun ? 'calon' : (string) (int) ($cancelResult['cancelled'] ?? 0);
        $codes = collect($details)->pluck('code')->filter()->take(10)->implode(', ');
        $extra = count($details) > 10 ? ', …' : '';
        $wa = $dryRun ? '' : ' (WA terkirim: ' . (int) ($cancelResult['notified'] ?? 0) . ')';

        return 'Booking basi dibatalkan otomatis: ' . $label . $wa . ($codes !== '' ? ' (' . $codes . $extra . ')' : '') . ($dryRun ? ' [dry-run]' : '');
    }

    // ---------- Eskalasi prioritas otomatis (tiket stagnan >3 hari) ----------

    /** Batas stagnan (hari tanpa perubahan status) sebelum prioritas naik 1 tingkat. */
    public const ESCALATION_STAGNANT_DAYS = 3;

    /** Status tiket yang dianggap sudah tutup untuk eskalasi (tidak disentuh). */
    public const ESCALATION_CLOSED_STATUSES = ['diserahkan', 'batal'];

    /** Peta naik 1 tingkat: rendah→normal→mendesak (mendesak sudah mentok). */
    public const ESCALATION_NEXT = ['rendah' => 'normal', 'normal' => 'mendesak'];

    /**
     * Kandidat eskalasi: tiket aktif (status selain diserahkan/batal) dengan
     * prioritas rendah/normal yang tidak berubah status >3 hari (updated_at
     * lebih lama dari 3 hari lalu). Tanpa migration: hanya baca kolom yang
     * sudah ada (status, priority, updated_at).
     */
    public static function stagnantPriorityCandidates(?int $storeId): \Illuminate\Support\Collection
    {
        try {
            if (! \Schema::hasTable('service_tickets')) return collect();

            return ServiceTicket::query()
                ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
                ->whereNotIn('status', self::ESCALATION_CLOSED_STATUSES)
                ->whereIn('priority', ['rendah', 'normal'])
                ->where('updated_at', '<', \Carbon\Carbon::now()->subDays(self::ESCALATION_STAGNANT_DAYS))
                ->orderBy('updated_at')
                ->get();
        } catch (\Throwable $e) {
            return collect();
        }
    }

    /**
     * Naikkan prioritas tiket stagnan 1 tingkat per run (rendah→normal→mendesak)
     * + catat ServiceLog (user_id null = sistem, from/to = status saat ini).
     * Idempoten berdasarkan updated_at: save() menyentuh updated_at (= sekarang)
     * sehingga tiket yang baru dieskalasi tidak lagi memenuhi syarat
     * updated_at < sekarang-3hari pada run berikutnya; run berikutnya hanya
     * menyentuh tiket yang masih stagnan 3 hari setelah eskalasi terakhir
     * (atau sentuhan manual lain yang juga menggeser updated_at). Sekali naik
     * mentok di mendesak (keluar dari kandidat prioritas rendah/normal).
     * Bila $dryRun, hanya kembalikan calon tanpa mengubah data.
     *
     * @return array{escalated:int,details:array<int,array{ticket:string,from:string,to:string,status:string}>}
     */
    public static function autoEscalateStagnantPriorities(?int $storeId, bool $dryRun = false): array
    {
        $escalated = 0;
        $details = [];

        foreach (self::stagnantPriorityCandidates($storeId) as $t) {
            $from = (string) ($t->priority ?? 'normal');
            $to = self::ESCALATION_NEXT[$from] ?? null;
            if ($to === null) {
                continue;
            }
            if ($dryRun) {
                $details[] = [
                    'ticket' => (string) ($t->ticket_no ?? ('#' . $t->id)),
                    'from' => $from, 'to' => $to,
                    'status' => 'calon (dry-run)',
                ];
                continue;
            }
            try {
                $status = (string) $t->status;
                $t->priority = $to;
                $t->save();
                $escalated++;
            } catch (\Throwable $e) {
                $details[] = [
                    'ticket' => (string) ($t->ticket_no ?? ('#' . $t->id)),
                    'from' => $from, 'to' => $to,
                    'status' => 'skip: gagal update',
                ];
                continue;
            }
            try {
                ServiceLog::create([
                    'service_ticket_id' => $t->id,
                    'user_id' => null,
                    'from_status' => $status,
                    'to_status' => $status,
                    'note' => "Eskalasi prioritas otomatis {$from} → {$to}: stagnan >3 hari tanpa perubahan status.",
                ]);
            } catch (\Throwable $e) {
            }
            $details[] = [
                'ticket' => (string) ($t->ticket_no ?? ('#' . $t->id)),
                'from' => $from, 'to' => $to,
                'status' => "dieskalasi ({$from} → {$to})",
            ];
        }

        return ['escalated' => $escalated, 'details' => $details];
    }

    /**
     * Pengingat Kunci Periode (tutup buku) bulan lalu untuk ditempel di pesan
     * digest HARIAN 21:00 owner. Tanpa migration: baca PeriodLock per toko
     * (storage/app/period_lock.json).
     * Syarat tampil: tanggal hari ini > 5 DAN batas kunci toko < tanggal 1
     * bulan berjalan (atau belum ada kunci) = bulan lalu belum dikunci.
     * Di luar syarat → string kosong (baris tidak ditempel).
     */
    public static function periodLockReminderBlock(?int $storeId, ?string $today = null): string
    {
        try {
            if (! $storeId) return '';
            $now = $today ? \Carbon\Carbon::parse($today) : \Carbon\Carbon::today();
            if ((int) $now->format('j') <= 5) return '';
            $namaBulan = [
                1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
                5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
                9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
            ];
            $prev = $now->copy()->subMonth();
            $prevLabel = ($namaBulan[(int) $prev->format('n')] ?? $prev->format('m')) . ' ' . $prev->format('Y');
            $firstOfMonth = $now->copy()->startOfMonth()->toDateString();
            $lock = \App\Support\PeriodLock::get($storeId);
            if ($lock !== null && $lock >= $firstOfMonth) return '';

            return 'Belum kunci periode ' . $prevLabel . ' — tutup di Kasir > Tutup Kasir';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Pengingat opname berkala untuk ditempel di pesan digest HARIAN owner.
     * Tanpa migration: baca max(created_at) stock_movements type=opname per toko.
     * Muncul hanya bila opname terakhir >30 hari lalu (atau belum pernah sama
     * sekali). Di dalam 30 hari → string kosong (baris tidak ditempel).
     */
    public static function opnameReminderBlock(?int $storeId): string
    {
        try {
            if (! $storeId) return '';
            if (! \Schema::hasTable('stock_movements')) return '';
            $last = \DB::table('stock_movements')
                ->where('store_id', $storeId)
                ->where('type', 'opname')
                ->max('created_at');
            if (! $last) return 'Opname terakhir belum pernah — jadwalkan stok opname';
            $days = (int) \Carbon\Carbon::parse($last)->startOfDay()
                ->diffInDays(\Carbon\Carbon::today()->startOfDay());
            if ($days <= 30) return '';
            return 'Opname terakhir ' . $days . ' hari lalu — jadwalkan stok opname';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** Satu baris ringkasan eskalasi prioritas untuk ditempel di pesan digest owner. */
    public static function escalationBlock(array $escResult, bool $dryRun = false): string
    {
        $details = $escResult['details'] ?? [];
        if (empty($details) && (int) ($escResult['escalated'] ?? 0) === 0) {
            return 'Eskalasi prioritas otomatis (>3 hari stagnan): -';
        }
        $label = $dryRun ? 'calon' : (string) (int) ($escResult['escalated'] ?? 0);
        $codes = collect($details)
            ->map(fn ($d) => ($d['ticket'] ?? '') . ' (' . ($d['from'] ?? '?') . '→' . ($d['to'] ?? '?') . ')')
            ->filter()->take(10)->implode(', ');
        $extra = count($details) > 10 ? ', …' : '';

        return 'Eskalasi prioritas otomatis (>3 hari stagnan): ' . $label . ($codes !== '' ? ' (' . $codes . $extra . ')' : '') . ($dryRun ? ' [dry-run]' : '');
    }

    /**
     * Kirim digest 1 tanggal untuk 1/semua toko + pengingat H-1 booking besok.
     *
     * Pengingat: tiap toko ambil booking status menunggu/dikonfirmasi dengan
     * schedule_date = besok (real besok, bukan offset dari $date karena
     * time-sensitive) → kirim WA ke customer via template booking_konfirmasi
     * (fallback teks, sama persis dgn BookingController@remind).
     *
     * Anti-ganda TANPA migration: tidak ada kolom penanda di tabel bookings,
     * jadi cek tabel wa_logs (type booking_reminder + phone ternormalisasi +
     * kode booking di message + created_at hari ini). Booking yang sudah ada
     * log hari ini dilewati.
     *
     * Termasuk pengingat piutang H-1: debts type=piutang, status!=lunas,
     * due_date = besok → WA ke nomor hasil pencocokan nama di customers
     * (pola sama dgn DebtController@resolvePhone), template hutang_jatuh_tempo,
     * anti-ganda via wa_logs type debt_reminder_h1.
     *
     * Termasuk pengingat ambil HP: tiket status siap_diambil dengan
     * updated_at >3 hari lalu → WA ke customer via template
     * service_siap_diambil (fallback teks, sama persis dgn
     * ServiceController status → siap_diambil). Anti-ganda TANPA migration:
     * cek wa_logs type pickup_reminder + phone ternormalisasi + nomor tiket
     * di message + created_at 7 hari terakhir.
     *
     * Termasuk pengingat garansi mau habis: warranties status=aktif dengan
     * end_date H-0 s/d H+7 (hari ini s/d 7 hari ke depan) → WA ke customer
     * (klaim sebelum habis), anti-ganda harian via wa_logs type
     * warranty_expiring (tanpa migration).
     *
     * Termasuk pengingat AWAL garansi: warranties status=aktif dengan
     * end_date H-8 s/d H-30 → WA sekali saja selamanya per garansi via
     * wa_logs type warranty_early (tanpa filter tanggal/nomor, tanpa spam
     * harian; tanpa migration).
     *
     * @return array<int, array{store:string,phone:string,ok:bool,tomorrow:string,reminded:int,skipped:int,reminders:array,promos_deactivated:int,promo_codes:array,warranty_expired:int,warranty_expired_ids:array,debt_reminded:int,debt_skipped:int,debt_reminders:array,pickup_reminded:int,pickup_skipped:int,pickup_reminders:array,warranty_reminded:int,warranty_skipped:int,warranty_reminders:array,warranty_early_reminded:int,warranty_early_skipped:int,warranty_early_reminders:array,stale_cancelled:int,stale_notified:int,stale_details:array,escalated:int,escalation_details:array}>
     */
    public static function sendForDate(string $date, ?int $storeId = null, bool $dryRun = false): array
    {
        $stores = $storeId
            ? Store::whereKey($storeId)->get()
            : Store::orderBy('id')->get();

        // Fallback instalasi 1 toko tanpa baris stores: tetap kirim 1 digest global.
        if ($stores->isEmpty()) {
            $stores = collect([(object) ['id' => null, 'name' => config('app.name', 'ServiceHP')]]);
        }

        // Nonaktifkan promo kedaluwarsa/kuota-habis sekali per run (idempoten;
        // dry-run hanya menampilkan calon). Dibatasi ke toko filter + global bila --store dipakai.
        $promoResult = self::deactivateExpiredPromos($dryRun, $storeId);
        $promoLine = self::promoDeactivatedBlock($promoResult, $dryRun);

        // Tandai selesai otomatis garansi kedaluwarsa sekali per run (idempoten,
        // cermin DashboardController@fixWarranty; dry-run hanya calon).
        $warrantyExpiredResult = self::autoExpireWarranties($dryRun, $storeId);
        $warrantyExpiredLine = self::warrantyExpiredBlock($warrantyExpiredResult, $dryRun);

        $tomorrow = \Carbon\Carbon::tomorrow()->toDateString();

        $results = [];
        foreach ($stores as $store) {
            $sid = $store->id ?? null;
            $data = self::dataForStore($sid, $date);
            $msg = self::messageForStore($store->name, $date, $data);

            // Ringkasan booking besok di pesan digest owner (poin 2).
            $upcoming = self::tomorrowBookings($sid, $tomorrow);
            $msg .= "\n" . self::bookingSummaryBlock($upcoming, $tomorrow);
            $msg .= "\n" . $promoLine;
            $msg .= "\n" . $warrantyExpiredLine;

            // Batalkan booking basi (>7 hari lewat jadwal / >14 hari tanpa jadwal).
            $cancel = self::autoCancelStaleBookings($sid, $dryRun);
            $msg .= "\n" . self::staleCancelBlock($cancel, $dryRun);

            // Eskalasi prioritas tiket stagnan >3 hari (rendah→normal→mendesak + ServiceLog).
            $esc = self::autoEscalateStagnantPriorities($sid, $dryRun);
            $msg .= "\n" . self::escalationBlock($esc, $dryRun);

            // Pengingat Kunci Periode bulan lalu (muncul bila hari ini > tgl 5 & bulan lalu belum dikunci).
            $lockLine = self::periodLockReminderBlock($sid);
            if ($lockLine !== '') $msg .= "\n" . $lockLine;

            // Pengingat opname berkala (muncul bila opname terakhir >30 hari lalu / belum pernah).
            $opnameLine = self::opnameReminderBlock($sid);
            if ($opnameLine !== '') $msg .= "\n" . $opnameLine;

            [$phone, $label] = self::recipientForStore($sid);
            $ok = false;
            if ($phone !== '' && ! $dryRun) {
                $ok = WaService::send($phone, $msg, 'digest_harian', $sid);
            }
            $rem = self::sendBookingRemindersForStore($sid, $tomorrow, $dryRun);
            $deb = self::sendDebtRemindersForStore($sid, $tomorrow, $dryRun);
            $pick = self::sendPickupRemindersForStore($sid, $dryRun);
            $warr = self::sendWarrantyRemindersForStore($sid, $dryRun);
            $warrEarly = self::sendWarrantyEarlyRemindersForStore($sid, $dryRun);
            $results[] = [
                'store' => $store->name . ($label ? " → {$label}" : ''),
                'phone' => $phone, 'ok' => $ok,
                'tomorrow' => $tomorrow,
                'reminded' => $rem['sent'], 'skipped' => $rem['skipped'],
                'reminders' => $rem['details'],
                'debt_reminded' => $deb['sent'], 'debt_skipped' => $deb['skipped'],
                'debt_reminders' => $deb['details'],
                'pickup_reminded' => $pick['sent'], 'pickup_skipped' => $pick['skipped'],
                'pickup_reminders' => $pick['details'],
                'warranty_reminded' => $warr['sent'], 'warranty_skipped' => $warr['skipped'],
                'warranty_reminders' => $warr['details'],
                'warranty_early_reminded' => $warrEarly['sent'], 'warranty_early_skipped' => $warrEarly['skipped'],
                'warranty_early_reminders' => $warrEarly['details'],
                'promos_deactivated' => $dryRun ? 0 : (int) ($promoResult['count'] ?? 0),
                'promo_codes' => $promoResult['codes'] ?? [],
                'warranty_expired' => $dryRun ? 0 : (int) ($warrantyExpiredResult['count'] ?? 0),
                'warranty_expired_ids' => $warrantyExpiredResult['ids'] ?? [],
                'stale_cancelled' => $dryRun ? 0 : (int) ($cancel['cancelled'] ?? 0),
                'stale_notified' => $dryRun ? 0 : (int) ($cancel['notified'] ?? 0),
                'stale_details' => $cancel['details'] ?? [],
                'escalated' => $dryRun ? 0 : (int) ($esc['escalated'] ?? 0),
                'escalation_details' => $esc['details'] ?? [],
            ];
        }

        return $results;
    }

    /**
     * Booking aktif (menunggu/dikonfirmasi) dengan schedule_date = $tomorrow.
     * Status enum aktual: menunggu, dikonfirmasi, dibatalkan, selesai.
     */
    public static function tomorrowBookings(?int $storeId, string $tomorrow): \Illuminate\Support\Collection
    {
        try {
            return Booking::query()
                ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
                ->whereIn('status', ['menunggu', 'dikonfirmasi'])
                ->whereDate('schedule_date', $tomorrow)
                ->orderBy('schedule_date')
                ->get();
        } catch (\Throwable $e) {
            return collect();
        }
    }

    /** Blok ringkasan booking besok untuk ditempel di pesan digest owner. */
    public static function bookingSummaryBlock(\Illuminate\Support\Collection $bookings, string $tomorrow): string
    {
        $tgl = \Carbon\Carbon::parse($tomorrow)->format('d/m');
        if ($bookings->isEmpty()) {
            return "Booking besok ({$tgl}): -";
        }
        $lines = $bookings->map(function ($b) {
            $jam = $b->schedule_date ? \Carbon\Carbon::parse($b->schedule_date)->format('H:i') : '-';
            return '- ' . $b->code . ' ' . ($b->customer_name ?? '') . ' ' . $jam . ' (' . $b->status . ')';
        })->implode("\n");

        return "Booking besok ({$tgl}, {$bookings->count()}):\n" . $lines;
    }

    /** Pesan pengingat 1 booking — sama persis dgn BookingController@remind. */
    public static function bookingReminderMessage(Booking $booking): string
    {
        $jadwal = $booking->schedule_date
            ? \Carbon\Carbon::parse($booking->schedule_date)->format('d/m/Y H:i') : '-';
        $msg = WaService::render('booking_konfirmasi', [
            'nama' => $booking->customer_name, 'tiket' => $booking->code, 'status' => $booking->status,
            'device' => $booking->device ?? '-', 'jadwal' => $jadwal,
            'total' => '', 'track' => url('/track'),
        ]);
        if ($msg === '') {
            $msg = "Halo {$booking->customer_name}, pengingat booking service *{$booking->code}* ({$booking->device}) jadwal {$jadwal}. Mohon datang tepat waktu. Balas OK untuk konfirmasi.";
        }

        return $msg;
    }

    /**
     * Anti-ganda: sudah ada wa_logs type booking_reminder untuk phone
     * (ternormalisasi) + kode booking di message + created_at hari ini?
     */
    public static function alreadyRemindedToday(Booking $booking): bool
    {
        try {
            if (! \Schema::hasTable('wa_logs')) return false;
            $phone = WaService::normalize((string) ($booking->customer_phone ?? ''));
            if ($phone === '') return false;

            return \DB::table('wa_logs')
                ->where('type', 'booking_reminder')
                ->where('phone', $phone)
                ->where('message', 'like', '%' . $booking->code . '%')
                ->whereDate('created_at', \Carbon\Carbon::today()->toDateString())
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Kirim (atau, bila $dryRun, hanya daftar) pengingat H-1 1 toko.
     * @return array{sent:int,skipped:int,details:array<int,array{code:string,phone:string,status:string}>}
     */
    public static function sendBookingRemindersForStore(?int $storeId, ?string $tomorrow = null, bool $dryRun = false): array
    {
        $tomorrow ??= \Carbon\Carbon::tomorrow()->toDateString();
        $sent = 0;
        $skipped = 0;
        $details = [];

        // Batch anti-ganda: 1 query log reminder hari ini (dulu exists per booking).
        $todayLogs = collect();
        try {
            if (\Schema::hasTable('wa_logs')) {
                $todayLogs = \DB::table('wa_logs')
                    ->where('type', 'booking_reminder')
                    ->whereDate('created_at', \Carbon\Carbon::today()->toDateString())
                    ->get(['phone', 'message']);
            }
        } catch (\Throwable $e) {
        }

        foreach (self::tomorrowBookings($storeId, $tomorrow) as $b) {
            $phone = WaService::normalize((string) ($b->customer_phone ?? ''));
            if ($phone === '') {
                $skipped++;
                $details[] = ['code' => $b->code, 'phone' => (string) ($b->customer_phone ?? ''), 'status' => 'skip: tanpa nomor'];
                continue;
            }
            $already = $todayLogs->contains(fn ($l) => ($l->phone ?? null) === $phone && str_contains((string) ($l->message ?? ''), (string) $b->code));
            if ($already) {
                $skipped++;
                $details[] = ['code' => $b->code, 'phone' => $phone, 'status' => 'skip: sudah diingatkan hari ini'];
                continue;
            }
            if ($dryRun) {
                $details[] = ['code' => $b->code, 'phone' => $phone, 'status' => 'calon (dry-run)'];
                continue;
            }
            try {
                $ok = WaService::send($phone, self::bookingReminderMessage($b), 'booking_reminder', $b->store_id);
            } catch (\Throwable $e) {
                $ok = false;
            }
            if ($ok) {
                $sent++;
                $details[] = ['code' => $b->code, 'phone' => $phone, 'status' => 'terkirim'];
            } else {
                $skipped++;
                $details[] = ['code' => $b->code, 'phone' => $phone, 'status' => 'skip: gagal/antri'];
            }
        }

        return ['sent' => $sent, 'skipped' => $skipped, 'details' => $details];
    }

    // ---------- Pengingat piutang H-1 (jatuh tempo besok) ----------

    /**
     * Piutang belum lunas dengan due_date = $tomorrow (tepat H-1, bukan ≤).
     * debts tidak punya customer_id — hanya party_name, jadi nomor HP
     * dicocokkan via customers (lihat resolveDebtPhone()).
     */
    public static function tomorrowPiutangs(?int $storeId, string $tomorrow): \Illuminate\Support\Collection
    {
        try {
            if (! \Schema::hasTable('debts')) return collect();

            return Debt::query()
                ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
                ->where('type', 'piutang')
                ->where('status', '!=', 'lunas')
                ->whereDate('due_date', $tomorrow)
                ->orderBy('id')
                ->get();
        } catch (\Throwable $e) {
            return collect();
        }
    }

    /**
     * Cari nomor HP pihak piutang dari tabel customers berdasar kecocokan
     * nama — pola SAMA PERSIS dengan DebtController@resolvePhone untuk
     * type piutang: exact match dulu (scope store_id), lalu LIKE %nama%.
     */
    public static function resolveDebtPhone(Debt $debt, ?int $storeId): ?string
    {
        try {
            $name = trim((string) $debt->party_name);
            if ($name === '') return null;
            $c = \App\Models\Customer::where('store_id', $storeId)->where('name', $name)->first()
                ?? \App\Models\Customer::where('store_id', $storeId)->where('name', 'like', "%{$name}%")->first();
            if ($c?->phone) return $c->phone;
        } catch (\Throwable $e) {
        }

        return null;
    }

    /** Pesan pengingat 1 piutang — sama persis dgn DebtController@index (?remind=). */
    public static function debtReminderMessage(Debt $debt): string
    {
        $msg = WaService::render('hutang_jatuh_tempo', [
            'nama' => $debt->party_name, 'tiket' => '#' . $debt->id, 'status' => $debt->status,
            'total' => number_format((float) $debt->remaining, 0, ',', '.'),
            'jadwal' => ($debt->due_date ? \Carbon\Carbon::parse($debt->due_date)->format('d/m/Y') : '-'),
            'track' => url('/track'),
        ]);
        if ($msg === '') {
            $msg = "Halo {$debt->party_name}, pengingat pembayaran Anda"
                . " sebesar Rp " . number_format((float) $debt->remaining, 0, ',', '.')
                . " (jatuh tempo: " . ($debt->due_date ? \Carbon\Carbon::parse($debt->due_date)->format('d/m/Y') : '-') . "). Terima kasih.";
        }
        // Penanda unik anti-ganda (wa_logs tak punya kolom debt_id):
        // "[#id]" tidak bertabrakan antar id (mis. #1 vs #12).
        $msg .= " [#{$debt->id}]";

        return $msg;
    }

    /**
     * Anti-ganda: sudah ada wa_logs type debt_reminder_h1 untuk phone
     * (ternormalisasi) + penanda [#id] di message + created_at hari ini?
     * Type baru (bukan debt_reminder manual) TANPA migration — kolom type
     * bebas diisi string apa pun.
     */
    public static function alreadyRemindedDebtToday(Debt $debt, string $phone): bool
    {
        try {
            if (! \Schema::hasTable('wa_logs')) return false;
            $phone = WaService::normalize($phone);
            if ($phone === '') return false;

            return \DB::table('wa_logs')
                ->where('type', 'debt_reminder_h1')
                ->where('phone', $phone)
                ->where('message', 'like', '%[#' . (int) $debt->id . ']%')
                ->whereDate('created_at', \Carbon\Carbon::today()->toDateString())
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Kirim (atau, bila $dryRun, hanya daftar) pengingat piutang H-1 1 toko.
     * @return array{sent:int,skipped:int,details:array<int,array{id:int,party:string,phone:string,status:string}>}
     */
    public static function sendDebtRemindersForStore(?int $storeId, ?string $tomorrow = null, bool $dryRun = false): array
    {
        $tomorrow ??= \Carbon\Carbon::tomorrow()->toDateString();
        $sent = 0;
        $skipped = 0;
        $details = [];

        $piutangs = self::tomorrowPiutangs($storeId, $tomorrow);
        // Batch: exact nama→HP sekali per toko (dulu 2 query per piutang) + 1 query log H-1 hari ini.
        // Kunci "store_id|nama" agar run global (semua toko) tetap benar per toko.
        $exactMap = collect();
        try {
            $byStore = [];
            foreach ($piutangs as $d) {
                $sid = (int) ($d->store_id ?? $storeId ?? 0);
                $nm = trim((string) $d->party_name);
                if ($nm !== '') $byStore[$sid][$nm] = true;
            }
            foreach ($byStore as $sid => $names) {
                $rows = \App\Models\Customer::where('store_id', $sid ?: null)
                    ->whereIn('name', array_keys($names))->pluck('phone', 'name');
                foreach ($rows as $nm => $ph) $exactMap[$sid . '|' . $nm] = $ph;
            }
        } catch (\Throwable $e) {
        }
        $debtLogs = collect();
        try {
            if (\Schema::hasTable('wa_logs')) {
                $debtLogs = \DB::table('wa_logs')
                    ->where('type', 'debt_reminder_h1')
                    ->whereDate('created_at', \Carbon\Carbon::today()->toDateString())
                    ->get(['phone', 'message']);
            }
        } catch (\Throwable $e) {
        }

        foreach ($piutangs as $d) {
            $sid = (int) ($d->store_id ?? $storeId ?? 0);
            $rawPhone = $exactMap->get($sid . '|' . trim((string) $d->party_name)) ?? self::resolveDebtPhone($d, $d->store_id ?? $storeId);
            $phone = $rawPhone ? WaService::normalize($rawPhone) : '';
            if ($phone === '') {
                $skipped++;
                $details[] = ['id' => $d->id, 'party' => (string) $d->party_name, 'phone' => '', 'status' => 'skip: tanpa nomor (nama tidak cocok di customers)'];
                continue;
            }
            $already = $debtLogs->contains(fn ($l) => ($l->phone ?? null) === $phone && str_contains((string) ($l->message ?? ''), '[#' . (int) $d->id . ']'));
            if ($already) {
                $skipped++;
                $details[] = ['id' => $d->id, 'party' => (string) $d->party_name, 'phone' => $phone, 'status' => 'skip: sudah diingatkan hari ini'];
                continue;
            }
            if ($dryRun) {
                $details[] = ['id' => $d->id, 'party' => (string) $d->party_name, 'phone' => $phone, 'status' => 'calon (dry-run)'];
                continue;
            }
            try {
                $ok = WaService::send($phone, self::debtReminderMessage($d), 'debt_reminder_h1', $d->store_id ?? $storeId);
            } catch (\Throwable $e) {
                $ok = false;
            }
            if ($ok) {
                $sent++;
                $details[] = ['id' => $d->id, 'party' => (string) $d->party_name, 'phone' => $phone, 'status' => 'terkirim'];
            } else {
                $skipped++;
                $details[] = ['id' => $d->id, 'party' => (string) $d->party_name, 'phone' => $phone, 'status' => 'skip: gagal/antri'];
            }
        }

        return ['sent' => $sent, 'skipped' => $skipped, 'details' => $details];
    }

    // ---------- Pengingat ambil HP (siap_diambil >3 hari) ----------

    /**
     * Tiket siap_diambil yang belum diambil >3 hari (updated_at lebih lama
     * dari 3 hari lalu). taken_at menandai serah terima — tiket yang masih
     * siap_diambil berarti taken_at masih null, jadi acuan usia = updated_at
     * (momen status → siap_diambil, pola sama dgn statistik tiketSiap harian).
     */
    public static function stagnantPickupTickets(?int $storeId): \Illuminate\Support\Collection
    {
        try {
            if (! \Schema::hasTable('service_tickets')) return collect();

            return ServiceTicket::query()
                ->with('customer')
                ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
                ->where('status', 'siap_diambil')
                ->where('updated_at', '<', \Carbon\Carbon::now()->subDays(3))
                ->orderBy('updated_at')
                ->get();
        } catch (\Throwable $e) {
            return collect();
        }
    }

    /** Pesan pengingat ambil 1 tiket — sama persis dgn ServiceController status → siap_diambil. */
    public static function pickupReminderMessage(ServiceTicket $ticket): string
    {
        $t = $ticket->relationLoaded('customer') ? $ticket : $ticket->loadMissing('customer');
        $nama = $t->customer->name ?? 'Pelanggan';
        $total = number_format((float) ($t->total_cost ?? 0), 0, ',', '.');
        $sisa = number_format(max(0, (float) ($t->total_cost ?? 0) - (float) ($t->dp ?? 0)), 0, ',', '.');
        $msg = WaService::render('service_siap_diambil', [
            'nama' => $nama, 'tiket' => $ticket->ticket_no, 'status' => 'siap_diambil',
            'total' => $total, 'sisa' => $sisa, 'track' => url('/track'),
        ]);
        if ($msg === '') {
            $msg = "Halo $nama, HP Anda tiket *{$ticket->ticket_no}* sudah SIAP DIAMBIL. Total Rp $total, sisa bayar Rp $sisa. " . url('/track');
        }
        try {
            $hari = (int) \Carbon\Carbon::parse($ticket->updated_at)->startOfDay()
                ->diffInDays(\Carbon\Carbon::today()->startOfDay());
        } catch (\Throwable $e) {
            $hari = 4;
        }
        $msg .= " Mohon segera diambil — sudah menunggu {$hari} hari.";

        return $msg;
    }

    /**
     * Anti-ganda: sudah ada wa_logs type pickup_reminder untuk phone
     * (ternormalisasi) + nomor tiket di message + created_at 7 hari terakhir?
     * Type baru TANPA migration — kolom type bebas diisi string apa pun.
     */
    public static function alreadyRemindedPickup(ServiceTicket $ticket, string $phone): bool
    {
        try {
            if (! \Schema::hasTable('wa_logs')) return false;
            $phone = WaService::normalize($phone);
            if ($phone === '' || trim((string) $ticket->ticket_no) === '') return false;

            return \DB::table('wa_logs')
                ->where('type', 'pickup_reminder')
                ->where('phone', $phone)
                ->where('message', 'like', '%' . $ticket->ticket_no . '%')
                ->where('created_at', '>=', \Carbon\Carbon::now()->subDays(7))
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Kirim (atau, bila $dryRun, hanya daftar) pengingat ambil HP 1 toko.
     * @return array{sent:int,skipped:int,details:array<int,array{ticket:string,phone:string,status:string}>}
     */
    public static function sendPickupRemindersForStore(?int $storeId, bool $dryRun = false): array
    {
        $sent = 0;
        $skipped = 0;
        $details = [];

        // Batch anti-ganda: 1 query log pickup 7 hari terakhir (dulu exists per tiket).
        $pickupLogs = collect();
        try {
            if (\Schema::hasTable('wa_logs')) {
                $pickupLogs = \DB::table('wa_logs')
                    ->where('type', 'pickup_reminder')
                    ->where('created_at', '>=', \Carbon\Carbon::now()->subDays(7))
                    ->get(['phone', 'message']);
            }
        } catch (\Throwable $e) {
        }

        foreach (self::stagnantPickupTickets($storeId) as $t) {
            $rawPhone = $t->customer->phone ?? '';
            $phone = $rawPhone ? WaService::normalize((string) $rawPhone) : '';
            if ($phone === '') {
                $skipped++;
                $details[] = ['ticket' => (string) $t->ticket_no, 'phone' => '', 'status' => 'skip: tanpa nomor (customer tidak punya HP)'];
                continue;
            }
            $already = $pickupLogs->contains(fn ($l) => ($l->phone ?? null) === $phone && str_contains((string) ($l->message ?? ''), (string) $t->ticket_no));
            if ($already) {
                $skipped++;
                $details[] = ['ticket' => (string) $t->ticket_no, 'phone' => $phone, 'status' => 'skip: sudah diingatkan 7 hari terakhir'];
                continue;
            }
            if ($dryRun) {
                $details[] = ['ticket' => (string) $t->ticket_no, 'phone' => $phone, 'status' => 'calon (dry-run)'];
                continue;
            }
            try {
                $ok = WaService::send($phone, self::pickupReminderMessage($t), 'pickup_reminder', $t->store_id ?? $storeId);
            } catch (\Throwable $e) {
                $ok = false;
            }
            if ($ok) {
                $sent++;
                $details[] = ['ticket' => (string) $t->ticket_no, 'phone' => $phone, 'status' => 'terkirim'];
            } else {
                $skipped++;
                $details[] = ['ticket' => (string) $t->ticket_no, 'phone' => $phone, 'status' => 'skip: gagal/antri'];
            }
        }

        return ['sent' => $sent, 'skipped' => $skipped, 'details' => $details];
    }

    // ---------- Pengingat garansi mau habis (H-0 s/d H+7) ----------

    /**
     * Garansi aktif yang berakhir hari ini s/d 7 hari ke depan
     * (end_date >= hari ini DAN <= hari ini + 7 hari, inklusif).
     * Hanya status=aktif (klaim/selesai tidak diingatkan).
     * Scope toko mengikuti WarrantyController@index: customer toko tsb
     * ATAU ref tiket service toko tsb (warranties tak punya store_id).
     */
    public static function expiringWarranties(?int $storeId): \Illuminate\Support\Collection
    {
        try {
            if (! \Schema::hasTable('warranties')) return collect();
            $today = \Carbon\Carbon::today()->toDateString();
            $limit = \Carbon\Carbon::today()->addDays(7)->toDateString();

            return Warranty::query()
                ->with('customer')
                ->when($storeId, fn ($q) => $q->where(function ($w) use ($storeId) {
                    $w->whereHas('customer', fn ($c) => $c->where('store_id', $storeId))
                        ->orWhere(function ($x) use ($storeId) {
                            $x->where('ref_type', 'service')
                                ->whereIn('ref_id', ServiceTicket::where('store_id', $storeId)->select('id'));
                        });
                }))
                ->where('status', 'aktif')
                ->whereNotNull('end_date')
                ->whereDate('end_date', '>=', $today)
                ->whereDate('end_date', '<=', $limit)
                ->orderBy('end_date')
                ->get();
        } catch (\Throwable $e) {
            return collect();
        }
    }

    /** Toko penanggung log WA 1 garansi (warranty tak punya store_id). */
    public static function resolveWarrantyStore(Warranty $warranty, ?int $fallbackStoreId): ?int
    {
        try {
            if ($warranty->relationLoaded('customer') || $warranty->customer_id) {
                $sid = $warranty->customer->store_id ?? null;
                if ($sid) return (int) $sid;
            }
            if ($warranty->ref_type === 'service' && $warranty->ref_id) {
                $sid = ServiceTicket::whereKey($warranty->ref_id)->value('store_id');
                if ($sid) return (int) $sid;
            }
        } catch (\Throwable $e) {
        }

        return $fallbackStoreId;
    }

    /** Pesan WA info garansi mau habis 1 garansi — ajak klaim sebelum habis. */
    public static function warrantyReminderMessage(Warranty $warranty): string
    {
        $w = $warranty->relationLoaded('customer') ? $warranty : $warranty->loadMissing('customer');
        $nama = $w->customer->name ?? 'Pelanggan';
        try {
            $tgl = $w->end_date ? \Carbon\Carbon::parse($w->end_date)->format('d/m/Y') : '-';
            $sisa = $w->end_date ? max(0, (int) \Carbon\Carbon::today()->startOfDay()
                ->diffInDays(\Carbon\Carbon::parse($w->end_date)->startOfDay(), false)) : 0;
        } catch (\Throwable $e) {
            $tgl = '-';
            $sisa = 0;
        }
        $tipe = $w->type ? " ({$w->type})" : '';
        $sisaTxt = $sisa === 0 ? 'hari ini' : $sisa . ' hari lagi';

        return "Halo {$nama}, info garansi{$tipe} Anda berakhir {$tgl} ({$sisaTxt}). Segera klaim / bawa perangkat & kartu garansi ke toko sebelum masa garansi habis. Terima kasih. [W#{$w->id}]";
    }

    /**
     * Anti-ganda harian: sudah ada wa_logs type warranty_expiring untuk
     * phone (ternormalisasi) + penanda [W#id] di message + created_at hari
     * ini? Type baru TANPA migration — kolom type bebas diisi string apa pun.
     */
    public static function alreadyRemindedWarrantyToday(Warranty $warranty, string $phone): bool
    {
        try {
            if (! \Schema::hasTable('wa_logs')) return false;
            $phone = WaService::normalize($phone);
            if ($phone === '') return false;

            return \DB::table('wa_logs')
                ->where('type', 'warranty_expiring')
                ->where('phone', $phone)
                ->where('message', 'like', '%[W#' . (int) $warranty->id . ']%')
                ->whereDate('created_at', \Carbon\Carbon::today()->toDateString())
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Kirim (atau, bila $dryRun, hanya daftar) pengingat garansi H-0 s/d H+7 1 toko.
     * @return array{sent:int,skipped:int,details:array<int,array{id:int,name:string,phone:string,end:string,status:string}>}
     */
    public static function sendWarrantyRemindersForStore(?int $storeId, bool $dryRun = false): array
    {
        $sent = 0;
        $skipped = 0;
        $details = [];

        $warranties = self::expiringWarranties($storeId);
        // Batch: 1 query log expiring hari ini + 1 query peta store tiket ref
        // (dulu exists per garansi + value store tiket per garansi).
        $warrLogs = collect();
        try {
            if (\Schema::hasTable('wa_logs')) {
                $warrLogs = \DB::table('wa_logs')
                    ->where('type', 'warranty_expiring')
                    ->whereDate('created_at', \Carbon\Carbon::today()->toDateString())
                    ->get(['phone', 'message']);
            }
        } catch (\Throwable $e) {
        }
        $ticketStoreMap = collect();
        try {
            $needTicketIds = $warranties
                ->filter(fn ($w) => empty($w->customer?->store_id) && ($w->ref_type ?? null) === 'service' && ! empty($w->ref_id))
                ->map(fn ($w) => (int) $w->ref_id)->unique()->values()->all();
            if (! empty($needTicketIds)) {
                $ticketStoreMap = ServiceTicket::whereIn('id', $needTicketIds)->pluck('store_id', 'id');
            }
        } catch (\Throwable $e) {
        }

        foreach ($warranties as $w) {
            $rawPhone = $w->customer->phone ?? '';
            $phone = $rawPhone ? WaService::normalize((string) $rawPhone) : '';
            try {
                $end = $w->end_date ? \Carbon\Carbon::parse($w->end_date)->format('d/m/Y') : '-';
            } catch (\Throwable $e) {
                $end = '-';
            }
            $nama = $w->customer->name ?? ('#' . $w->id);
            if ($phone === '') {
                $skipped++;
                $details[] = ['id' => $w->id, 'name' => (string) $nama, 'phone' => '', 'end' => $end, 'status' => 'skip: tanpa nomor (customer tidak punya HP)'];
                continue;
            }
            $already = $warrLogs->contains(fn ($l) => ($l->phone ?? null) === $phone && str_contains((string) ($l->message ?? ''), '[W#' . (int) $w->id . ']'));
            if ($already) {
                $skipped++;
                $details[] = ['id' => $w->id, 'name' => (string) $nama, 'phone' => $phone, 'end' => $end, 'status' => 'skip: sudah diingatkan hari ini'];
                continue;
            }
            if ($dryRun) {
                $details[] = ['id' => $w->id, 'name' => (string) $nama, 'phone' => $phone, 'end' => $end, 'status' => 'calon (dry-run)'];
                continue;
            }
            try {
                $storeForLog = $w->customer?->store_id
                    ?? ($w->ref_type === 'service' && $w->ref_id ? ($ticketStoreMap->get((int) $w->ref_id) ?? $storeId) : $storeId);
                $ok = WaService::send($phone, self::warrantyReminderMessage($w), 'warranty_expiring', $storeForLog ? (int) $storeForLog : $storeId);
            } catch (\Throwable $e) {
                $ok = false;
            }
            if ($ok) {
                $sent++;
                $details[] = ['id' => $w->id, 'name' => (string) $nama, 'phone' => $phone, 'end' => $end, 'status' => 'terkirim'];
            } else {
                $skipped++;
                $details[] = ['id' => $w->id, 'name' => (string) $nama, 'phone' => $phone, 'end' => $end, 'status' => 'skip: gagal/antri'];
            }
        }

        return ['sent' => $sent, 'skipped' => $skipped, 'details' => $details];
    }

    // ---------- Pengingat AWAL garansi (H-30 s/d H-8, sekali selamanya) ----------

    /**
     * Garansi aktif yang berakhir 8 s/d 30 hari ke depan
     * (end_date >= hari ini + 8 hari DAN <= hari ini + 30 hari, inklusif).
     * Batas bawah H-8 sengaja tidak beririsan dengan pengingat intensif
     * H-0 s/d H+7 (expiringWarranties) agar tidak ada garansi yang masuk
     * dua corong sekaligus. Hanya status=aktif.
     * Scope toko sama dengan expiringWarranties (customer toko tsb ATAU
     * ref tiket service toko tsb).
     */
    public static function earlyExpiringWarranties(?int $storeId): \Illuminate\Support\Collection
    {
        try {
            if (! \Schema::hasTable('warranties')) return collect();
            $from = \Carbon\Carbon::today()->addDays(8)->toDateString();
            $to = \Carbon\Carbon::today()->addDays(30)->toDateString();

            return Warranty::query()
                ->with('customer')
                ->when($storeId, fn ($q) => $q->where(function ($w) use ($storeId) {
                    $w->whereHas('customer', fn ($c) => $c->where('store_id', $storeId))
                        ->orWhere(function ($x) use ($storeId) {
                            $x->where('ref_type', 'service')
                                ->whereIn('ref_id', ServiceTicket::where('store_id', $storeId)->select('id'));
                        });
                }))
                ->where('status', 'aktif')
                ->whereNotNull('end_date')
                ->whereDate('end_date', '>=', $from)
                ->whereDate('end_date', '<=', $to)
                ->orderBy('end_date')
                ->get();
        } catch (\Throwable $e) {
            return collect();
        }
    }

    /** Pesan WA pengingat AWAL garansi — info dini + janji diingatkan lagi mendekati habis. */
    public static function warrantyEarlyMessage(Warranty $warranty): string
    {
        $w = $warranty->relationLoaded('customer') ? $warranty : $warranty->loadMissing('customer');
        $nama = $w->customer->name ?? 'Pelanggan';
        try {
            $tgl = $w->end_date ? \Carbon\Carbon::parse($w->end_date)->format('d/m/Y') : '-';
            $sisa = $w->end_date ? max(0, (int) \Carbon\Carbon::today()->startOfDay()
                ->diffInDays(\Carbon\Carbon::parse($w->end_date)->startOfDay(), false)) : 0;
        } catch (\Throwable $e) {
            $tgl = '-';
            $sisa = 0;
        }
        $tipe = $w->type ? " ({$w->type})" : '';

        return "Halo {$nama}, pengingat awal: garansi{$tipe} Anda berakhir {$tgl} (sekitar {$sisa} hari lagi). Simpan info ini — kami ingatkan lagi mendekati masa habis. Terima kasih. [W#{$w->id}]";
    }

    /**
     * Anti-ganda SEKALI SELAMANYA: sudah ada wa_logs type warranty_early yang
     * memuat penanda [W#id] (kapan pun, ke nomor mana pun) → 1x selamanya
     * per garansi, tanpa spam harian. Type baru TANPA migration — kolom type
     * bebas diisi string apa pun. Sengaja TANPA filter phone/tanggal supaya
     * ganti nomor HP pun tidak memicu kirim ulang.
     */
    public static function alreadySentWarrantyEarly(Warranty $warranty): bool
    {
        try {
            if (! \Schema::hasTable('wa_logs')) return false;

            return \DB::table('wa_logs')
                ->where('type', 'warranty_early')
                ->where('message', 'like', '%[W#' . (int) $warranty->id . ']%')
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Kirim (atau, bila $dryRun, hanya daftar) pengingat AWAL garansi
     * H-30 s/d H-8 1 toko. Sekali per garansi selamanya (cek
     * alreadySentWarrantyEarly); tanpa nomor HP → skip tanpa WA.
     * @return array{sent:int,skipped:int,details:array<int,array{id:int,name:string,phone:string,end:string,status:string}>}
     */
    public static function sendWarrantyEarlyRemindersForStore(?int $storeId, bool $dryRun = false): array
    {
        $sent = 0;
        $skipped = 0;
        $details = [];

        $earlyWarranties = self::earlyExpiringWarranties($storeId);
        // Batch: 1 query semua pesan warranty_early + 1 query peta store tiket ref
        // (dulu exists per garansi + value store tiket per garansi).
        $earlyMsgs = collect();
        try {
            if (\Schema::hasTable('wa_logs')) {
                $earlyMsgs = \DB::table('wa_logs')->where('type', 'warranty_early')->pluck('message');
            }
        } catch (\Throwable $e) {
        }
        $earlyTicketMap = collect();
        try {
            $needTicketIds = $earlyWarranties
                ->filter(fn ($w) => empty($w->customer?->store_id) && ($w->ref_type ?? null) === 'service' && ! empty($w->ref_id))
                ->map(fn ($w) => (int) $w->ref_id)->unique()->values()->all();
            if (! empty($needTicketIds)) {
                $earlyTicketMap = ServiceTicket::whereIn('id', $needTicketIds)->pluck('store_id', 'id');
            }
        } catch (\Throwable $e) {
        }

        foreach ($earlyWarranties as $w) {
            $rawPhone = $w->customer->phone ?? '';
            $phone = $rawPhone ? WaService::normalize((string) $rawPhone) : '';
            try {
                $end = $w->end_date ? \Carbon\Carbon::parse($w->end_date)->format('d/m/Y') : '-';
            } catch (\Throwable $e) {
                $end = '-';
            }
            $nama = $w->customer->name ?? ('#' . $w->id);
            if ($phone === '') {
                $skipped++;
                $details[] = ['id' => $w->id, 'name' => (string) $nama, 'phone' => '', 'end' => $end, 'status' => 'skip: tanpa nomor (customer tidak punya HP)'];
                continue;
            }
            $already = $earlyMsgs->contains(fn ($m) => str_contains((string) $m, '[W#' . (int) $w->id . ']'));
            if ($already) {
                $skipped++;
                $details[] = ['id' => $w->id, 'name' => (string) $nama, 'phone' => $phone, 'end' => $end, 'status' => 'skip: sudah pernah dikirim (1x selamanya)'];
                continue;
            }
            if ($dryRun) {
                $details[] = ['id' => $w->id, 'name' => (string) $nama, 'phone' => $phone, 'end' => $end, 'status' => 'calon (dry-run)'];
                continue;
            }
            try {
                $storeForLog = $w->customer?->store_id
                    ?? ($w->ref_type === 'service' && $w->ref_id ? ($earlyTicketMap->get((int) $w->ref_id) ?? $storeId) : $storeId);
                $ok = WaService::send($phone, self::warrantyEarlyMessage($w), 'warranty_early', $storeForLog ? (int) $storeForLog : $storeId);
            } catch (\Throwable $e) {
                $ok = false;
            }
            if ($ok) {
                $sent++;
                $details[] = ['id' => $w->id, 'name' => (string) $nama, 'phone' => $phone, 'end' => $end, 'status' => 'terkirim'];
            } else {
                $skipped++;
                $details[] = ['id' => $w->id, 'name' => (string) $nama, 'phone' => $phone, 'end' => $end, 'status' => 'skip: gagal/antri'];
            }
        }

        return ['sent' => $sent, 'skipped' => $skipped, 'details' => $details];
    }

    // ---------- Ajakan servis berkala (dormant >6 bulan, maks 1x/90 hari) ----------

    /** Tiket terakhir dianggap dormant bila lebih lama dari 6 bulan. */
    public const DORMANT_MONTHS = 6;

    /** Jeda anti-ganda ajakan servis berkala per customer (hari). */
    public const DORMANT_COOLDOWN_DAYS = 90;

    /** Maksimal ajakan servis berkala per toko per run mingguan (agar tidak banjir WA). */
    public const DORMANT_PER_RUN_LIMIT = 50;

    /**
     * Kandidat ajakan servis berkala 1 toko: customer yang tiket TERAKHIR-nya
     * (MAX created_at service_tickets per customer_id, scope toko) lebih lama
     * dari 6 bulan lalu. Diurutkan paling lama dulu + dibatasi 50 per run
     * agar pengiriman tersebar antar minggu. Tanpa migration: hanya baca
     * kolom yang sudah ada (service_tickets.customer_id/created_at,
     * customers.phone).
     *
     * @return \Illuminate\Support\Collection<int, array{customer:\App\Models\Customer,last_ticket:\App\Models\ServiceTicket,last_at:string,months:int}>
     */
    public static function dormantServiceCandidates(?int $storeId, int $limit = self::DORMANT_PER_RUN_LIMIT): \Illuminate\Support\Collection
    {
        try {
            if (! \Schema::hasTable('service_tickets')) return collect();
            $cutoff = \Carbon\Carbon::now()->subMonths(self::DORMANT_MONTHS);

            $rows = \DB::table('service_tickets')
                ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
                ->whereNotNull('customer_id')
                ->selectRaw('customer_id, MAX(created_at) as last_at')
                ->groupBy('customer_id')
                ->havingRaw('MAX(created_at) < ?', [$cutoff->toDateTimeString()])
                ->orderBy('last_at')
                ->limit(max(1, $limit))
                ->get();
            if ($rows->isEmpty()) return collect();

            $ids = $rows->pluck('customer_id')->map(fn ($v) => (int) $v)->all();
            $customers = \App\Models\Customer::whereIn('id', $ids)
                ->whereNotNull('phone')->where('phone', '<>', '')
                ->get()->keyBy('id');
            // Tiket terakhir per customer untuk konteks pesan (device + tanggal).
            $tickets = ServiceTicket::whereIn('customer_id', $ids)
                ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
                ->orderByDesc('created_at')->get()
                ->groupBy('customer_id');

            $today = \Carbon\Carbon::today()->startOfDay();
            $out = collect();
            foreach ($rows as $r) {
                $cid = (int) $r->customer_id;
                $customer = $customers->get($cid);
                $lastTicket = $tickets->get($cid)?->first();
                if (! $customer || ! $lastTicket) continue;
                try {
                    $months = max(self::DORMANT_MONTHS, (int) \Carbon\Carbon::parse($r->last_at)->startOfDay()->diffInMonths($today));
                } catch (\Throwable $e) {
                    $months = self::DORMANT_MONTHS;
                }
                $out->push([
                    'customer' => $customer,
                    'last_ticket' => $lastTicket,
                    'last_at' => (string) $r->last_at,
                    'months' => $months,
                ]);
            }

            return $out;
        } catch (\Throwable $e) {
            return collect();
        }
    }

    /** Pesan WA ajakan servis berkala — teks saja, tanpa promo. */
    public static function dormantServiceMessage(\App\Models\Customer $customer, ServiceTicket $ticket, int $months): string
    {
        $nama = $customer->name ?? 'Pelanggan';
        $device = trim((($ticket->product_brand ?? '') . ' ' . ($ticket->product_type ?? ''))) ?: 'HP';
        try {
            $tgl = $ticket->created_at ? \Carbon\Carbon::parse($ticket->created_at)->format('d/m/Y') : '-';
        } catch (\Throwable $e) {
            $tgl = '-';
        }

        return "Halo {$nama}, sudah {$months} bulan sejak servis terakhir {$device} ({$tgl}) di kami. Agar perangkat tetap prima, yuk mampir untuk cek/servis berkala. Balas WA ini untuk booking. Terima kasih. [DORMANT#C{$customer->id}]";
    }

    /**
     * Anti-ganda 90 hari: sudah ada wa_logs type service_dormant yang memuat
     * penanda [DORMANT#C{id}] dalam 90 hari terakhir (kapan pun ke nomor mana
     * pun)? Type baru TANPA migration — kolom type bebas diisi string apa pun.
     * Sengaja TANPA filter phone supaya ganti nomor HP pun tidak memicu kirim
     * ulang dalam masa jeda.
     */
    public static function alreadySentDormant(int $customerId): bool
    {
        try {
            if (! \Schema::hasTable('wa_logs')) return false;

            return \DB::table('wa_logs')
                ->where('type', 'service_dormant')
                ->where('message', 'like', '%[DORMANT#C' . $customerId . ']%')
                ->where('created_at', '>=', \Carbon\Carbon::now()->subDays(self::DORMANT_COOLDOWN_DAYS))
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Kirim (atau, bila $dryRun, hanya daftar) ajakan servis berkala 1 toko.
     * Maks 1x per customer per 90 hari (cek alreadySentDormant via batch
     * 1 query log 90 hari); tanpa nomor HP → skip tanpa WA.
     * @return array{sent:int,skipped:int,details:array<int,array{id:int,name:string,phone:string,last:string,status:string}>}
     */
    public static function sendDormantRemindersForStore(?int $storeId, bool $dryRun = false): array
    {
        $sent = 0;
        $skipped = 0;
        $details = [];

        // Batch anti-ganda: 1 query semua pesan service_dormant 90 hari terakhir
        // (dulu exists per customer).
        $dormantMsgs = collect();
        try {
            if (\Schema::hasTable('wa_logs')) {
                $dormantMsgs = \DB::table('wa_logs')
                    ->where('type', 'service_dormant')
                    ->where('created_at', '>=', \Carbon\Carbon::now()->subDays(self::DORMANT_COOLDOWN_DAYS))
                    ->pluck('message');
            }
        } catch (\Throwable $e) {
        }

        foreach (self::dormantServiceCandidates($storeId) as $c) {
            /** @var \App\Models\Customer $customer */
            $customer = $c['customer'];
            /** @var ServiceTicket $ticket */
            $ticket = $c['last_ticket'];
            $phone = $customer->phone ? WaService::normalize((string) $customer->phone) : '';
            try {
                $last = \Carbon\Carbon::parse($c['last_at'])->format('d/m/Y');
            } catch (\Throwable $e) {
                $last = '-';
            }
            $nama = (string) ($customer->name ?? ('#' . $customer->id));
            if ($phone === '') {
                $skipped++;
                $details[] = ['id' => $customer->id, 'name' => $nama, 'phone' => '', 'last' => $last, 'status' => 'skip: tanpa nomor (customer tidak punya HP)'];
                continue;
            }
            $already = $dormantMsgs->contains(fn ($m) => str_contains((string) $m, '[DORMANT#C' . (int) $customer->id . ']'));
            if ($already) {
                $skipped++;
                $details[] = ['id' => $customer->id, 'name' => $nama, 'phone' => $phone, 'last' => $last, 'status' => 'skip: sudah diajak 90 hari terakhir'];
                continue;
            }
            if ($dryRun) {
                $details[] = ['id' => $customer->id, 'name' => $nama, 'phone' => $phone, 'last' => $last, 'status' => 'calon (dry-run)'];
                continue;
            }
            try {
                $ok = WaService::send($phone, self::dormantServiceMessage($customer, $ticket, (int) $c['months']), 'service_dormant', $ticket->store_id ?? $storeId);
            } catch (\Throwable $e) {
                $ok = false;
            }
            if ($ok) {
                $sent++;
                $details[] = ['id' => $customer->id, 'name' => $nama, 'phone' => $phone, 'last' => $last, 'status' => 'terkirim'];
            } else {
                $skipped++;
                $details[] = ['id' => $customer->id, 'name' => $nama, 'phone' => $phone, 'last' => $last, 'status' => 'skip: gagal/antri'];
            }
        }

        return ['sent' => $sent, 'skipped' => $skipped, 'details' => $details];
    }

    /** Satu baris ringkasan ajakan servis berkala untuk ditempel di pesan digest mingguan owner. */
    public static function dormantServiceBlock(array $dormantResult, bool $dryRun = false): string
    {
        $details = $dormantResult['details'] ?? [];
        if (empty($details) && (int) ($dormantResult['sent'] ?? 0) === 0) {
            return 'Ajakan servis berkala (>6 bln, maks 1x/90 hr): -';
        }
        $label = $dryRun ? 'calon' : (string) (int) ($dormantResult['sent'] ?? 0);
        $names = collect($details)->map(fn ($d) => ($d['name'] ?? '') . ' (' . ($d['last'] ?? '-') . ')')
            ->filter()->take(10)->implode(', ');
        $extra = count($details) > 10 ? ', …' : '';

        return 'Ajakan servis berkala (>6 bln, maks 1x/90 hr): ' . $label . ($names !== '' ? ' (' . $names . $extra . ')' : '') . ($dryRun ? ' [dry-run]' : '');
    }

    // ---------- Ringkasan harian teknisi via WA ----------

    /** Status tiket yang dianggap sudah tutup (tidak masuk ringkasan teknisi). */
    public const TECH_CLOSED_STATUSES = ['diserahkan', 'batal', 'selesai'];

    /** Teknisi AKTIF yang punya nomor HP (penerima ringkasan harian teknisi). */
    public static function activeTechnicians(): \Illuminate\Support\Collection
    {
        try {
            return User::query()
                ->where('role', 'teknisi')
                ->where('is_active', true)
                ->whereNotNull('phone')->where('phone', '<>', '')
                ->orderBy('name')->get();
        } catch (\Throwable $e) {
            return collect();
        }
    }

    /**
     * Tiket aktif 1 teknisi di toko mana pun yang ditugaskan padanya
     * (query by technician_id, status selain diserahkan/batal/selesai).
     */
    public static function techActiveTickets(int $technicianId): \Illuminate\Support\Collection
    {
        try {
            return ServiceTicket::query()
                ->with('store')
                ->where('technician_id', $technicianId)
                ->whereNotIn('status', self::TECH_CLOSED_STATUSES)
                ->orderByRaw("CASE priority WHEN 'mendesak' THEN 0 WHEN 'normal' THEN 1 WHEN 'rendah' THEN 2 ELSE 3 END")
                ->orderByRaw('COALESCE(estimated_done_at, created_at) ASC')
                ->get();
        } catch (\Throwable $e) {
            return collect();
        }
    }

    /** Susun pesan WA ringkasan tiket aktif 1 teknisi. */
    public static function techDailyMessage(User $tech, \Illuminate\Support\Collection $tickets, string $date): string
    {
        $today = \Carbon\Carbon::parse($date)->startOfDay();
        $late = 0;
        $lines = $tickets->map(function ($t) use ($today, &$late) {
            $device = trim(($t->product_brand ?? '') . ' ' . ($t->product_type ?? '')) ?: '-';
            $toko = $t->store->name ?? ('#' . ($t->store_id ?? '?'));
            if ($t->estimated_done_at) {
                $est = \Carbon\Carbon::parse($t->estimated_done_at);
                if ($est->startOfDay()->lt($today)) {
                    $late++;
                    $eta = '⚠ TERLAMBAT ' . (int) abs($today->diffInDays($est->startOfDay())) . ' hr (estimasi ' . $est->format('d/m') . ')';
                } else {
                    $eta = 'estimasi ' . $est->format('d/m');
                }
            } else {
                $eta = 'tanpa estimasi';
            }

            return '• ' . $t->ticket_no . ' — ' . $device . ' [' . $t->status . '] @ ' . $toko . ' — ' . $eta;
        })->implode("\n");

        $msg = '🔧 *TIKET AKTIF HARIAN* ' . $today->format('d/m/Y') . "\n"
            . 'Halo ' . $tech->name . ', ' . $tickets->count() . ' tiket aktif:'
            . "\n" . $lines;
        if ($late > 0) {
            $msg .= "\n⚠ {$late} terlambat — dahulukan!";
        }

        return $msg;
    }

    /**
     * Anti-ganda: sudah ada wa_logs type tech_daily untuk phone
     * (ternormalisasi) + created_at hari ini?
     */
    public static function alreadySentTechDailyToday(string $phone): bool
    {
        try {
            if (! \Schema::hasTable('wa_logs')) return false;
            $phone = WaService::normalize($phone);
            if ($phone === '') return false;

            return \DB::table('wa_logs')
                ->where('type', 'tech_daily')
                ->where('phone', $phone)
                ->whereDate('created_at', \Carbon\Carbon::today()->toDateString())
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Kirim (atau, bila $dryRun, hanya daftar) ringkasan tiket aktif ke tiap
     * teknisi aktif bernomor HP yang masih punya tiket aktif. Sekali sehari
     * per nomor (cek wa_logs type tech_daily).
     * @return array{sent:int,skipped:int,details:array<int,array{name:string,phone:string,status:string}>}
     */
    public static function sendTechDailies(string $date, bool $dryRun = false): array
    {
        $sent = 0;
        $skipped = 0;
        $details = [];

        foreach (self::activeTechnicians() as $tech) {
            $phone = WaService::normalize((string) ($tech->phone ?? ''));
            if ($phone === '') {
                $skipped++;
                $details[] = ['name' => $tech->name, 'phone' => (string) ($tech->phone ?? ''), 'status' => 'skip: tanpa nomor'];
                continue;
            }
            $tickets = self::techActiveTickets((int) $tech->id);
            if ($tickets->isEmpty()) {
                $skipped++;
                $details[] = ['name' => $tech->name, 'phone' => $phone, 'status' => 'skip: tanpa tiket aktif'];
                continue;
            }
            if (self::alreadySentTechDailyToday($phone)) {
                $skipped++;
                $details[] = ['name' => $tech->name, 'phone' => $phone, 'status' => 'skip: sudah dikirim hari ini'];
                continue;
            }
            if ($dryRun) {
                $details[] = ['name' => $tech->name, 'phone' => $phone, 'status' => 'calon (dry-run, ' . $tickets->count() . ' tiket)'];
                continue;
            }
            try {
                $storeId = $tech->active_store_id ?? $tickets->first()->store_id;
                $ok = WaService::send($phone, self::techDailyMessage($tech, $tickets, $date), 'tech_daily', $storeId);
            } catch (\Throwable $e) {
                $ok = false;
            }
            if ($ok) {
                $sent++;
                $details[] = ['name' => $tech->name, 'phone' => $phone, 'status' => 'terkirim (' . $tickets->count() . ' tiket)'];
            } else {
                $skipped++;
                $details[] = ['name' => $tech->name, 'phone' => $phone, 'status' => 'skip: gagal/antri'];
            }
        }

        return ['sent' => $sent, 'skipped' => $skipped, 'details' => $details];
    }

    // ---------- Ringkasan kesehatan data (8 cek DashboardController@health) ----------

    /**
     * Hitung ringkas 8 cek kesehatan data (jumlah temuan per cek, tanpa
     * detail baris). Query disalin dari DashboardController@health, tapi
     * hanya COUNT — tanpa limit 100 baris detail, tanpa kirim WA.
     * Read-only TANPA migration: tiap cek try/catch sendiri; cek yang gagal
     * (mis. tabel belum ada) menghasilkan null (= '?' di blok pesan).
     *
     * Bila $storeId diisi, cek difilter ke toko itu (kolom store_id yang
     * ada; garansi mengikuti scope WarrantyController: customer toko tsb
     * ATAU ref tiket service toko tsb). Bila null = semua toko (sama dgn
     * halaman /health owner/superadmin).
     *
     * @return array{unit_terjual:int|null,stok_negatif:int|null,selisih_sale:int|null,selisih_kas:int|null,selisih_hutang:int|null,komisi_batal:int|null,unit_service_yatim:int|null,garansi_kedaluwarsa:int|null}
     */
    public static function healthSummary(?int $storeId = null): array
    {
        $summary = [
            'unit_terjual' => null, 'stok_negatif' => null,
            'selisih_sale' => null, 'selisih_kas' => null,
            'selisih_hutang' => null, 'komisi_batal' => null,
            'unit_service_yatim' => null, 'garansi_kedaluwarsa' => null,
        ];

        // 1. Unit IMEI status 'tersedia' tapi sudah ada di sale_items (tanpa retur).
        try {
            if (\Schema::hasTable('product_items') && \Schema::hasTable('sale_items') && \Schema::hasTable('sales')) {
                $q = \DB::table('product_items')
                    ->join('sale_items', 'sale_items.product_item_id', '=', 'product_items.id')
                    ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                    ->where('product_items.status', 'tersedia');
                if ($storeId) {
                    $q->where('product_items.store_id', $storeId);
                }
                if (\Schema::hasTable('sale_returns')) {
                    $q->leftJoin('sale_returns', 'sale_returns.sale_id', '=', 'sales.id')
                        ->whereNull('sale_returns.id');
                }
                $summary['unit_terjual'] = (int) $q->count();
            } else {
                $summary['unit_terjual'] = 0;
            }
        } catch (\Throwable $e) {
        }

        // 2. Stok negatif.
        try {
            if (\Schema::hasTable('product_stocks')) {
                $summary['stok_negatif'] = (int) \DB::table('product_stocks')
                    ->when($storeId, fn ($q) => $q->where('product_stocks.store_id', $storeId))
                    ->where('qty', '<', 0)
                    ->count();
            } else {
                $summary['stok_negatif'] = 0;
            }
        } catch (\Throwable $e) {
        }

        // 3. Sale remaining != total - paid_amount (scan 2000 terakhir, pola sama dgn /health).
        try {
            if (\Schema::hasTable('sales')) {
                $rows = \DB::table('sales')
                    ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
                    ->select('id', 'total', 'paid_amount', 'remaining')
                    ->orderByDesc('id')->limit(2000)->get();
                $n = 0;
                foreach ($rows as $s) {
                    if (abs((float) $s->remaining - ((float) $s->total - (float) $s->paid_amount)) > 1) {
                        $n++;
                    }
                }
                $summary['selisih_sale'] = $n;
            } else {
                $summary['selisih_sale'] = 0;
            }
        } catch (\Throwable $e) {
        }

        // 4. CashAccount balance != sum mutasi (masuk - keluar).
        try {
            if (\Schema::hasTable('cash_accounts') && \Schema::hasTable('cash_transactions')) {
                $accounts = \DB::table('cash_accounts')
                    ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
                    ->select('id', 'balance')->get();
                // Batch: 1 query agregat mutasi per akun (dulu 2x sum per akun = N+1).
                $mutasi = collect();
                if ($accounts->isNotEmpty()) {
                    $mutasi = \DB::table('cash_transactions')
                        ->whereIn('cash_account_id', $accounts->pluck('id')->all())
                        ->selectRaw("cash_account_id, SUM(CASE WHEN type = 'masuk' THEN amount ELSE 0 END) as masuk, SUM(CASE WHEN type = 'keluar' THEN amount ELSE 0 END) as keluar")
                        ->groupBy('cash_account_id')->get()->keyBy('cash_account_id');
                }
                $n = 0;
                foreach ($accounts as $a) {
                    $in = (float) ($mutasi->get($a->id)->masuk ?? 0);
                    $out = (float) ($mutasi->get($a->id)->keluar ?? 0);
                    if (abs((float) $a->balance - ($in - $out)) > 1) {
                        $n++;
                    }
                }
                $summary['selisih_kas'] = $n;
            } else {
                $summary['selisih_kas'] = 0;
            }
        } catch (\Throwable $e) {
        }

        // 5. Debt remaining != total - paid (scan 2000 terakhir).
        try {
            if (\Schema::hasTable('debts')) {
                $rows = \DB::table('debts')
                    ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
                    ->select('total', 'paid', 'remaining')
                    ->orderByDesc('id')->limit(2000)->get();
                $n = 0;
                foreach ($rows as $d) {
                    if (abs((float) $d->remaining - ((float) $d->total - (float) $d->paid)) > 1) {
                        $n++;
                    }
                }
                $summary['selisih_hutang'] = $n;
            } else {
                $summary['selisih_hutang'] = 0;
            }
        } catch (\Throwable $e) {
        }

        // 6. Komisi teknisi 'menunggu' untuk tiket 'batal'.
        try {
            if (\Schema::hasTable('technician_commissions') && \Schema::hasTable('service_tickets')) {
                $summary['komisi_batal'] = (int) \DB::table('technician_commissions')
                    ->join('service_tickets', 'service_tickets.id', '=', 'technician_commissions.service_ticket_id')
                    ->when($storeId, fn ($q) => $q->where('service_tickets.store_id', $storeId))
                    ->where('service_tickets.status', 'batal')
                    ->where('technician_commissions.status', 'menunggu')
                    ->count();
            } else {
                $summary['komisi_batal'] = 0;
            }
        } catch (\Throwable $e) {
        }

        // 7. Unit status 'service' yang tiket aktifnya sudah tidak ada (yatim, ber-IMEI).
        try {
            if (\Schema::hasTable('product_items') && \Schema::hasTable('service_tickets')) {
                $summary['unit_service_yatim'] = (int) \DB::table('product_items as pi')
                    ->when($storeId, fn ($q) => $q->where('pi.store_id', $storeId))
                    ->where('pi.status', 'service')
                    ->whereNotNull('pi.imei')->where('pi.imei', '!=', '')
                    ->whereNotExists(function ($q) {
                        $q->select(\DB::raw(1))->from('service_tickets as st')
                            ->whereRaw('st.imei = pi.imei')
                            ->whereNotIn('st.status', ['diserahkan', 'batal']);
                    })
                    ->count();
            } else {
                $summary['unit_service_yatim'] = 0;
            }
        } catch (\Throwable $e) {
        }

        // 8. Garansi kedaluwarsa: status 'aktif' tapi end_date < hari ini.
        try {
            if (\Schema::hasTable('warranties')) {
                $q = \DB::table('warranties')
                    ->where('warranties.status', 'aktif')
                    ->whereNotNull('warranties.end_date')
                    ->whereDate('warranties.end_date', '<', \Carbon\Carbon::today()->toDateString());
                if ($storeId) {
                    $q->where(function ($w) use ($storeId) {
                        $w->whereIn('warranties.customer_id', \DB::table('customers')->where('store_id', $storeId)->select('id'))
                            ->orWhere(function ($x) use ($storeId) {
                                $x->where('warranties.ref_type', 'service')
                                    ->whereIn('warranties.ref_id', \DB::table('service_tickets')->where('store_id', $storeId)->select('id'));
                            });
                    });
                }
                $summary['garansi_kedaluwarsa'] = (int) $q->count();
            } else {
                $summary['garansi_kedaluwarsa'] = 0;
            }
        } catch (\Throwable $e) {
        }

        return $summary;
    }

    /** Blok ringkas 8 cek kesehatan (hanya jumlah temuan) untuk pesan digest mingguan owner. */
    public static function healthSummaryBlock(array $summary, ?int $storeId = null): string
    {
        $n = fn ($v) => $v === null ? '?' : (string) (int) $v;
        $scope = $storeId ? 'toko ini' : 'semua toko';

        return '🩺 Kesehatan data (' . $scope . '):'
            . "\n- Unit terjual tapi status tersedia: " . $n($summary['unit_terjual'] ?? null)
            . "\n- Stok negatif: " . $n($summary['stok_negatif'] ?? null)
            . "\n- Selisih remaining sale: " . $n($summary['selisih_sale'] ?? null)
            . "\n- Selisih saldo kas: " . $n($summary['selisih_kas'] ?? null)
            . "\n- Selisih remaining hutang/piutang: " . $n($summary['selisih_hutang'] ?? null)
            . "\n- Komisi menunggu utk tiket batal: " . $n($summary['komisi_batal'] ?? null)
            . "\n- Unit service yatim: " . $n($summary['unit_service_yatim'] ?? null)
            . "\n- Garansi kedaluwarsa (aktif): " . $n($summary['garansi_kedaluwarsa'] ?? null);
    }

    // ---------- Rekap mingguan (7 hari) per toko ----------

    /**
     * Agregasi rekap 1 toko untuk rentang [$from, $to] inklusif.
     *
     * Cara paling konsisten TANPA mengubah query inti: panggil dataForStore()
     * per tanggal lalu jumlahkan (arus kas/omzet/diskon/retur/tiket bersifat
     * aditif harian). Stok menipis BUKAN kumulatif — dipakai snapshot
     * terakhir ($to) sebagai kondisi saat ini.
     */
    public static function dataForStoreRange(?int $storeId, string $from, string $to): array
    {
        $start = \Carbon\Carbon::parse($from)->startOfDay();
        $end = \Carbon\Carbon::parse($to)->startOfDay();

        $agg = [
            'omzet' => 0, 'diskon' => 0, 'trxCount' => 0,
            'perMetode' => collect(),
            'retur' => 0, 'kasMasuk' => 0, 'kasKeluar' => 0,
            'tiketMasuk' => 0, 'tiketSiap' => 0, 'tiketDiserahkan' => 0,
            'lowStocks' => collect(), 'lowStockCount' => 0,
        ];
        $metodeSums = [];
        $days = 0;

        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $date = $d->toDateString();
            $one = self::dataForStore($storeId, $date);
            $days++;
            $agg['omzet'] += (float) ($one['omzet'] ?? 0);
            $agg['diskon'] += (float) ($one['diskon'] ?? 0);
            $agg['trxCount'] += (int) ($one['trxCount'] ?? 0);
            $agg['retur'] += (float) ($one['retur'] ?? 0);
            $agg['kasMasuk'] += (float) ($one['kasMasuk'] ?? 0);
            $agg['kasKeluar'] += (float) ($one['kasKeluar'] ?? 0);
            $agg['tiketMasuk'] += (int) ($one['tiketMasuk'] ?? 0);
            $agg['tiketSiap'] += (int) ($one['tiketSiap'] ?? 0);
            $agg['tiketDiserahkan'] += (int) ($one['tiketDiserahkan'] ?? 0);
            foreach ((array) ($one['perMetode'] ?? []) as $m => $t) {
                $metodeSums[$m] = ((float) ($metodeSums[$m] ?? 0)) + (float) $t;
            }
            // Snapshot stok = kondisi terakhir (timpa tiap hari).
            $agg['lowStocks'] = $one['lowStocks'] ?? collect();
            $agg['lowStockCount'] = (int) ($one['lowStockCount'] ?? 0);
        }

        $agg['perMetode'] = collect($metodeSums);
        $agg['days'] = $days;

        return $agg;
    }

    /** Susun pesan WA digest mingguan dari array dataForStoreRange(). */
    public static function messageForStoreRange(string $storeName, string $from, string $to, array $data): string
    {
        $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');
        $metodeLines = collect($data['perMetode'] ?? [])
            ->map(fn ($t, $m) => '- ' . strtoupper((string) $m) . ': ' . $rp($t))
            ->implode("\n");

        $low = collect($data['lowStocks'] ?? []);
        $lowCount = (int) ($data['lowStockCount'] ?? $low->count());
        $lowBlock = 'Stok menipis saat ini (' . $lowCount . '):';
        if ($low->isEmpty()) {
            $lowBlock .= "\n-";
        } else {
            $lowBlock .= "\n" . $low->take(10)->map(
                fn ($r) => '- ' . (is_array($r) ? ($r['name'] ?? '?') : ($r->name ?? '?'))
                    . ' (sisa ' . (is_array($r) ? ($r['qty'] ?? 0) : ($r->qty ?? 0)) . ')'
            )->implode("\n");
            if ($lowCount > 10) {
                $lowBlock .= "\n… +" . ($lowCount - 10) . ' lainnya';
            }
        }

        $days = (int) ($data['days'] ?? 0);
        $rangeLabel = $from === $to ? $from : $from . ' s/d ' . $to;

        return '📊 *DIGEST MINGGUAN* ' . $rangeLabel . "\n"
            . 'Toko: ' . $storeName . "\n"
            . 'Omzet ' . $days . ' hari (' . ($data['trxCount'] ?? 0) . ' trx): ' . $rp($data['omzet'] ?? 0) . "\n"
            . ($metodeLines ? $metodeLines . "\n" : '')
            . 'Diskon: ' . $rp($data['diskon'] ?? 0) . "\n"
            . 'Retur: ' . $rp($data['retur'] ?? 0) . "\n"
            . 'Kas masuk: ' . $rp($data['kasMasuk'] ?? 0) . "\n"
            . 'Kas keluar: ' . $rp($data['kasKeluar'] ?? 0) . "\n"
            . 'Service masuk: ' . ($data['tiketMasuk'] ?? 0)
            . ' • siap: ' . ($data['tiketSiap'] ?? 0)
            . ' • diserahkan: ' . ($data['tiketDiserahkan'] ?? 0) . "\n"
            . $lowBlock;
    }

    /**
     * Booking aktif (menunggu/dikonfirmasi) dengan schedule_date di
     * rentang [$from, $to] — untuk pengingat 7 hari ke depan di digest mingguan.
     */
    public static function upcomingBookings(?int $storeId, string $from, string $to): \Illuminate\Support\Collection
    {
        try {
            return Booking::query()
                ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
                ->whereIn('status', ['menunggu', 'dikonfirmasi'])
                ->whereDate('schedule_date', '>=', $from)
                ->whereDate('schedule_date', '<=', $to)
                ->orderBy('schedule_date')
                ->get();
        } catch (\Throwable $e) {
            return collect();
        }
    }

    /** Blok ringkasan booking 7 hari ke depan untuk ditempel di pesan digest mingguan. */
    public static function bookingUpcomingBlock(\Illuminate\Support\Collection $bookings, string $from, string $to): string
    {
        $tglFrom = \Carbon\Carbon::parse($from)->format('d/m');
        $tglTo = \Carbon\Carbon::parse($to)->format('d/m');
        if ($bookings->isEmpty()) {
            return "Booking 7 hari ke depan ({$tglFrom}–{$tglTo}): -";
        }
        $lines = $bookings->take(20)->map(function ($b) {
            $jadwal = $b->schedule_date ? \Carbon\Carbon::parse($b->schedule_date)->format('d/m H:i') : '-';
            return '- ' . $b->code . ' ' . ($b->customer_name ?? '') . ' ' . $jadwal . ' (' . $b->status . ')';
        })->implode("\n");
        $extra = $bookings->count() > 20 ? "\n… +" . ($bookings->count() - 20) . ' lainnya' : '';

        return "Booking 7 hari ke depan ({$tglFrom}–{$tglTo}, {$bookings->count()}):\n" . $lines . $extra;
    }

    /**
     * Kirim digest mingguan (rekap [$from, $to]) untuk 1/semua toko ke owner.
     * Hanya ringkasan + daftar booking 7 hari ke depan — TIDAK mengirim
     * pengingat harian ke customer (hindari ganda dengan digest harian H-1).
     * Pengecualian: ajakan servis berkala dormant (>6 bulan, maks 1x/90 hari
     * per customer via wa_logs type service_dormant) — frekuensi mingguan
     * (Senin 07:00 via routes/console.php) dipilih agar tidak spam harian.
     *
     * @return array<int, array{store:string,phone:string,ok:bool,from:string,to:string,promos_deactivated:int,promo_codes:array,dormant_sent:int,dormant_skipped:int,dormant_details:array}>
     */
    public static function sendForRange(string $from, string $to, ?int $storeId = null, bool $dryRun = false): array
    {
        $stores = $storeId
            ? Store::whereKey($storeId)->get()
            : Store::orderBy('id')->get();

        if ($stores->isEmpty()) {
            $stores = collect([(object) ['id' => null, 'name' => config('app.name', 'ServiceHP')]]);
        }

        $promoResult = self::deactivateExpiredPromos($dryRun, $storeId);
        $promoLine = self::promoDeactivatedBlock($promoResult, $dryRun);

        // Pengingat booking 7 hari ke depan (real 7 hari dari hari ini).
        $upFrom = \Carbon\Carbon::today()->toDateString();
        $upTo = \Carbon\Carbon::today()->addDays(7)->toDateString();

        $results = [];
        foreach ($stores as $store) {
            $sid = $store->id ?? null;
            $data = self::dataForStoreRange($sid, $from, $to);
            $msg = self::messageForStoreRange($store->name, $from, $to, $data);

            $upcoming = self::upcomingBookings($sid, $upFrom, $upTo);
            $msg .= "\n" . self::bookingUpcomingBlock($upcoming, $upFrom, $upTo);
            $msg .= "\n" . $promoLine;

            // Ringkasan kesehatan data (8 cek, hanya jumlah temuan per cek).
            $health = self::healthSummary($sid);
            $msg .= "\n" . self::healthSummaryBlock($health, $sid);

            // Ajakan servis berkala: customer dormant (tiket terakhir >6 bulan),
            // maks 1x/90 hari per customer. Hanya di digest MINGGUAN agar tidak spam.
            $dorm = self::sendDormantRemindersForStore($sid, $dryRun);
            $msg .= "\n" . self::dormantServiceBlock($dorm, $dryRun);

            [$phone, $label] = self::recipientForStore($sid);
            $ok = false;
            if ($phone !== '' && ! $dryRun) {
                $ok = WaService::send($phone, $msg, 'digest_mingguan', $sid);
            }
            $results[] = [
                'store' => $store->name . ($label ? " → {$label}" : ''),
                'phone' => $phone, 'ok' => $ok,
                'from' => $from, 'to' => $to,
                'upcoming' => $upcoming->count(),
                'health' => $health,
                'promos_deactivated' => $dryRun ? 0 : (int) ($promoResult['count'] ?? 0),
                'promo_codes' => $promoResult['codes'] ?? [],
                'dormant_sent' => $dryRun ? 0 : (int) ($dorm['sent'] ?? 0),
                'dormant_skipped' => (int) ($dorm['skipped'] ?? 0),
                'dormant_details' => $dorm['details'] ?? [],
            ];
        }

        return $results;
    }

    // ---------- Digest pagi operasional (read-only, tanpa WA ke customer) ----------

    /**
     * Status tiket yang dianggap sudah tutup untuk digest pagi
     * (tidak masuk daftar kerja hari ini).
     */
    public const MORNING_CLOSED_STATUSES = ['diserahkan', 'batal'];

    /**
     * Data operasional KHUSUS hari ini untuk 1 toko. 100% READ-ONLY:
     * tidak ada update/insert/delete, tidak ada kirim WA ke customer,
     * hanya dibaca untuk ditempel di pesan digest pagi owner.
     *
     * Isi: jadwal hari ini (booking + estimasi selesai), tiket
     * mendesak/terlambat, stok habis (=0), posisi kas pagi.
     */
    public static function morningDataForStore(?int $storeId, string $date): array
    {
        $bookingsToday = collect();
        try {
            $bookingsToday = Booking::query()
                ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
                ->whereIn('status', ['menunggu', 'dikonfirmasi'])
                ->whereDate('schedule_date', $date)
                ->orderBy('schedule_date')
                ->get();
        } catch (\Throwable $e) {
        }

        $etaToday = collect();
        $overdue = collect();
        $urgent = collect();
        $pickupStagnant = collect();
        $activeCount = 0;
        try {
            if (\Schema::hasTable('service_tickets')) {
                $base = ServiceTicket::query()
                    ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
                    ->whereNotIn('status', self::MORNING_CLOSED_STATUSES);
                $activeCount = (int) (clone $base)->count();
                $etaToday = (clone $base)->whereDate('estimated_done_at', $date)
                    ->orderBy('estimated_done_at')->limit(20)->get();
                $overdue = (clone $base)->whereNotNull('estimated_done_at')
                    ->whereDate('estimated_done_at', '<', $date)
                    ->orderBy('estimated_done_at')->limit(20)->get();
                $overdueCount = (int) (clone $base)->whereNotNull('estimated_done_at')
                    ->whereDate('estimated_done_at', '<', $date)->count();
                $urgent = (clone $base)->where('priority', 'mendesak')
                    ->orderByRaw('COALESCE(estimated_done_at, created_at) ASC')->limit(20)->get();
                $urgentCount = (int) (clone $base)->where('priority', 'mendesak')->count();
                $pickupStagnant = (clone $base)->where('status', 'siap_diambil')
                    ->where('updated_at', '<', \Carbon\Carbon::parse($date)->startOfDay()->subDays(3))
                    ->orderBy('updated_at')->limit(10)->get();
                $pickupCount = (int) (clone $base)->where('status', 'siap_diambil')
                    ->where('updated_at', '<', \Carbon\Carbon::parse($date)->startOfDay()->subDays(3))->count();
            } else {
                $overdueCount = 0;
                $urgentCount = 0;
                $pickupCount = 0;
            }
        } catch (\Throwable $e) {
            $overdueCount = 0;
            $urgentCount = 0;
            $pickupCount = 0;
        }

        // Stok HABIS (=0, bukan sekadar menipis): qty <= 0, scope toko.
        $outOfStocks = collect();
        $outOfStockCount = 0;
        try {
            if (\Schema::hasTable('product_stocks') && \Schema::hasTable('products')) {
                $outBase = \DB::table('product_stocks')
                    ->join('products', 'products.id', '=', 'product_stocks.product_id')
                    ->when($storeId, fn ($q) => $q->where('product_stocks.store_id', $storeId))
                    ->where('product_stocks.qty', '<=', 0);
                $outOfStockCount = (int) (clone $outBase)->count();
                $outOfStocks = (clone $outBase)
                    ->select('products.name', 'product_stocks.qty')
                    ->orderBy('products.name')->limit(10)->get();
            }
        } catch (\Throwable $e) {
        }

        // Posisi kas pagi (saldo per akun kas toko) — info kasir/opening.
        $cashAccounts = collect();
        try {
            if (\Schema::hasTable('cash_accounts')) {
                $cashAccounts = \DB::table('cash_accounts')
                    ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
                    ->select('name', 'balance')
                    ->orderBy('name')->limit(10)->get();
            }
        } catch (\Throwable $e) {
        }

        return [
            'bookingsToday' => $bookingsToday,
            'etaToday' => $etaToday,
            'overdue' => $overdue,
            'overdueCount' => $overdueCount ?? $overdue->count(),
            'urgent' => $urgent,
            'urgentCount' => $urgentCount ?? $urgent->count(),
            'pickupStagnant' => $pickupStagnant,
            'pickupCount' => $pickupCount ?? $pickupStagnant->count(),
            'activeCount' => $activeCount,
            'outOfStocks' => $outOfStocks,
            'outOfStockCount' => $outOfStockCount,
            'cashAccounts' => $cashAccounts,
        ];
    }

    /** Susun pesan WA digest pagi operasional (murni info owner, tanpa ajakan WA ke customer). */
    public static function morningMessageForStore(string $storeName, string $date, array $data): string
    {
        $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');
        $tgl = \Carbon\Carbon::parse($date)->format('d/m/Y');

        $bookings = collect($data['bookingsToday'] ?? []);
        $bookingBlock = '📅 Booking hari ini (' . $bookings->count() . '):';
        $bookingBlock .= $bookings->isEmpty() ? "\n-" : "\n" . $bookings->take(20)->map(function ($b) {
            try {
                $jam = $b->schedule_date ? \Carbon\Carbon::parse($b->schedule_date)->format('H:i') : '-';
            } catch (\Throwable $e) {
                $jam = '-';
            }

            return '- ' . ($b->code ?? '?') . ' ' . ($b->customer_name ?? '') . ' ' . $jam . ' (' . ($b->status ?? '?') . ')';
        })->implode("\n");
        if ($bookings->count() > 20) {
            $bookingBlock .= "\n… +" . ($bookings->count() - 20) . ' lainnya';
        }

        $eta = collect($data['etaToday'] ?? []);
        $etaBlock = '🔧 Estimasi selesai hari ini (' . $eta->count() . '):';
        $etaBlock .= $eta->isEmpty() ? "\n-" : "\n" . $eta->take(20)->map(function ($t) {
            $teknisi = $t->relationLoaded('technician') || isset($t->technician)
                ? ($t->technician->name ?? null) : null;
            $tek = $teknisi ? ' @' . $teknisi : '';

            return '• ' . ($t->ticket_no ?? ('#' . ($t->id ?? '?'))) . ' [' . ($t->status ?? '?') . ']' . $tek;
        })->implode("\n");

        $urgentCount = (int) ($data['urgentCount'] ?? collect($data['urgent'] ?? [])->count());
        $urgentBlock = '🚨 Mendesak aktif (' . $urgentCount . '):';
        $urgent = collect($data['urgent'] ?? []);
        $urgentBlock .= $urgent->isEmpty() ? "\n-" : "\n" . $urgent->take(10)->map(
            fn ($t) => '• ' . ($t->ticket_no ?? ('#' . ($t->id ?? '?'))) . ' [' . ($t->status ?? '?') . ']'
        )->implode("\n");
        if ($urgentCount > 10) {
            $urgentBlock .= "\n… +" . ($urgentCount - 10) . ' lainnya';
        }

        $overdueCount = (int) ($data['overdueCount'] ?? collect($data['overdue'] ?? [])->count());
        $overdueBlock = '⚠ Terlambat (estimasi lewat) (' . $overdueCount . '):';
        $overdue = collect($data['overdue'] ?? []);
        $overdueBlock .= $overdue->isEmpty() ? "\n-" : "\n" . $overdue->take(10)->map(function ($t) use ($date) {
            try {
                $est = $t->estimated_done_at ? \Carbon\Carbon::parse($t->estimated_done_at)->format('d/m') : '-';
            } catch (\Throwable $e) {
                $est = '-';
            }

            return '• ' . ($t->ticket_no ?? ('#' . ($t->id ?? '?'))) . ' (est. ' . $est . ')';
        })->implode("\n");
        if ($overdueCount > 10) {
            $overdueBlock .= "\n… +" . ($overdueCount - 10) . ' lainnya';
        }

        $pickupCount = (int) ($data['pickupCount'] ?? collect($data['pickupStagnant'] ?? [])->count());
        $pickupBlock = '📲 Siap diambil >3 hari (' . $pickupCount . '):';
        $pickup = collect($data['pickupStagnant'] ?? []);
        $pickupBlock .= $pickup->isEmpty() ? "\n-" : "\n" . $pickup->take(10)->map(
            fn ($t) => '• ' . ($t->ticket_no ?? ('#' . ($t->id ?? '?')))
        )->implode("\n");
        if ($pickupCount > 10) {
            $pickupBlock .= "\n… +" . ($pickupCount - 10) . ' lainnya';
        }

        $outCount = (int) ($data['outOfStockCount'] ?? 0);
        $out = collect($data['outOfStocks'] ?? []);
        $stockBlock = '📦 Stok habis (=0) (' . $outCount . '):';
        if ($out->isEmpty()) {
            $stockBlock .= "\n-";
        } else {
            $stockBlock .= "\n" . $out->take(10)->map(
                fn ($r) => '- ' . (is_array($r) ? ($r->name ?? '?') : ($r->name ?? '?'))
                    . ' (sisa ' . (is_array($r) ? ($r->qty ?? 0) : ($r->qty ?? 0)) . ')'
            )->implode("\n");
            if ($outCount > 10) {
                $stockBlock .= "\n… +" . ($outCount - 10) . ' lainnya';
            }
        }

        $cash = collect($data['cashAccounts'] ?? []);
        $cashBlock = '💰 Kas pagi (' . $cash->count() . ' akun):';
        if ($cash->isEmpty()) {
            $cashBlock .= "\n-";
        } else {
            $cashBlock .= "\n" . $cash->map(
                fn ($r) => '- ' . (is_array($r) ? ($r->name ?? '?') : ($r->name ?? '?'))
                    . ': ' . $rp(is_array($r) ? ($r->balance ?? 0) : ($r->balance ?? 0))
            )->implode("\n");
        }

        return '🌅 *DIGEST PAGI OPERASIONAL* ' . $tgl . "\n"
            . 'Toko: ' . $storeName . ' (tiket aktif: ' . (int) ($data['activeCount'] ?? 0) . ")\n"
            . $bookingBlock . "\n"
            . $etaBlock . "\n"
            . $urgentBlock . "\n"
            . $overdueBlock . "\n"
            . $pickupBlock . "\n"
            . $stockBlock . "\n"
            . $cashBlock . "\n"
            . '_Read-only: tanpa pengingat ke customer, tanpa ubah data._';
    }

    /**
     * Kirim digest pagi operasional untuk 1/semua toko ke owner.
     * READ-ONLY: tidak memanggil auto-ubah apa pun (tanpa eskalasi /
     * batal / nonaktif promo / tutup garansi) dan tidak mengirim WA
     * ke customer — hanya 1 pesan info per toko ke owner.
     *
     * @return array<int, array{store:string,phone:string,ok:bool,date:string,bookings:int,eta:int,urgent:int,overdue:int,out_of_stock:int}>
     */
    public static function sendMorningForDate(string $date, ?int $storeId = null, bool $dryRun = false): array
    {
        $stores = $storeId
            ? Store::whereKey($storeId)->get()
            : Store::orderBy('id')->get();

        // Fallback instalasi 1 toko tanpa baris stores: tetap kirim 1 digest global.
        if ($stores->isEmpty()) {
            $stores = collect([(object) ['id' => null, 'name' => config('app.name', 'ServiceHP')]]);
        }

        $results = [];
        foreach ($stores as $store) {
            $sid = $store->id ?? null;
            $data = self::morningDataForStore($sid, $date);
            $msg = self::morningMessageForStore($store->name, $date, $data);

            [$phone, $label] = self::recipientForStore($sid);
            $ok = false;
            if ($phone !== '' && ! $dryRun) {
                $ok = WaService::send($phone, $msg, 'digest_pagi', $sid);
            }
            $results[] = [
                'store' => $store->name . ($label ? " → {$label}" : ''),
                'phone' => $phone, 'ok' => $ok, 'date' => $date,
                'bookings' => collect($data['bookingsToday'] ?? [])->count(),
                'eta' => collect($data['etaToday'] ?? [])->count(),
                'urgent' => (int) ($data['urgentCount'] ?? 0),
                'overdue' => (int) ($data['overdueCount'] ?? 0),
                'out_of_stock' => (int) ($data['outOfStockCount'] ?? 0),
                'message' => $msg,
            ];
        }

        return $results;
    }
}
