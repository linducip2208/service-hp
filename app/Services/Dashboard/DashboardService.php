<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * DashboardService — seluruh agregasi dashboard terpusat di sini.
 * DashboardController hanya resolve store + teruskan ke view.
 *
 * Konvensi bisnis (eksplisit, bukan implisit):
 * - "Omzet" = SUM(sales.total) = nilai invoice (belum tentu kas diterima).
 * - "Kas diterima" = SUM(sale_payments.amount) per tanggal bayar.
 * - "Profit kotor" = SUM(sale_items.profit) produk; jasa service belum termasuk.
 * - Semua widget scope toko aktif; ringkasan cabang hanya untuk owner/superadmin.
 * - Tiap blok dibungkus aman: gagal hitung -> nilai default + $partial=true + Log::warning
 *   (dashboard tak boleh 500, tapi kegagalan harus terlihat di log, bukan ditelan diam-diam).
 */
class DashboardService
{
    public static function forStore(?int $storeId, ?string $role): array
    {
        $svc = new self($storeId, $role);
        return $svc->build();
    }

    private bool $partial = false;

    public function __construct(private ?int $storeId, private ?string $role) {}

    public function build(): array
    {
        $today = now()->toDateString();
        $tomorrow = now()->addDay()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        $data = [
            'stats' => $this->stats($today, $yesterday),
            'recentSales' => $this->safe(fn() => $this->has('sales')
                ? DB::table('sales')->when($this->storeId, fn($q) => $q->where('store_id', $this->storeId))->orderByDesc('id')->limit(8)->get()
                : collect(), collect()),
            'recentService' => $this->safe(fn() => $this->has('service_tickets')
                ? DB::table('service_tickets')->when($this->storeId, fn($q) => $q->where('store_id', $this->storeId))->orderByDesc('id')->limit(8)->get()
                : collect(), collect()),
            'trends' => $this->trends(),
            'payMix' => $this->payMix(),
            'topProducts' => $this->topProducts(),
            'serviceOps' => $this->serviceOps(),
            'lowStocks' => $this->lowStocks(),
            'pendingBookings' => $this->safe(fn() => $this->has('bookings')
                ? DB::table('bookings')->when($this->storeId, fn($q) => $q->where('store_id', $this->storeId))
                    ->whereNotIn('status', ['dikonfirmasi', 'dibatalkan', 'selesai'])->orderByDesc('id')->limit(8)->get()
                : collect(), collect()),
            'alerts' => $this->alerts(),
            'schedules' => $this->schedules($today, $tomorrow),
            'tomorrow' => $tomorrow,
            'targetWidget' => $this->targetWidget(),
            'annualTarget' => $this->annualTarget(),
            'branchSummary' => $this->branchSummary($today),
        ];
        $data['partial'] = $this->partial;

        // Kunci lama agar view existing tetap jalan selama transisi redesign.
        $data['revLabels'] = $data['trends']['labels'];
        $data['revData'] = $data['trends']['revenue'];
        $data['payLabels'] = $data['payMix']['labels'];
        $data['payData'] = $data['payMix']['data'];
        $data['serviceByStatus'] = collect($data['serviceOps']['counts']);
        $data['todayBookings'] = $data['schedules']['todayBookings'];
        $data['todayTickets'] = $data['schedules']['todayTickets'];
        $data['tomorrowBookings'] = $data['schedules']['tomorrowBookings'];
        $data['tomorrowTickets'] = $data['schedules']['tomorrowTickets'];

        return $data;
    }

    private function has(string $table): bool
    {
        try { return Schema::hasTable($table); } catch (\Throwable $e) { return false; }
    }

    private function safe(callable $fn, mixed $fallback): mixed
    {
        try { return $fn(); } catch (\Throwable $e) {
            $this->partial = true;
            Log::warning('Dashboard widget gagal: ' . $e->getMessage());
            return $fallback;
        }
    }

    private function scopeTickets($q)
    {
        return $q->when($this->storeId, fn($qq) => $qq->where('store_id', $this->storeId));
    }

    private function stats(string $today, string $yesterday): array
    {
        $s = [
            'penjualan_hari_ini' => 0, 'penjualan_kemarin' => 0,
            'penjualan_diff' => 0, 'penjualan_pct' => null,
            'service_aktif' => 0, 'stok_menipis' => 0, 'kas_masuk_bulan' => 0,
            'trx_hari_ini' => 0, 'profit_kotor_hari_ini' => 0,
            'kas_diterima_hari_ini' => 0, 'siap_diambil' => 0,
            'pelanggan_baru_30h' => 0, 'piutang_outstanding' => 0,
        ];
        $s['penjualan_hari_ini'] = $this->safe(fn() => $this->has('sales')
            ? (float) DB::table('sales')->when($this->storeId, fn($q) => $q->where('store_id', $this->storeId))->whereDate('created_at', $today)->sum('total') : 0, 0);
        $s['penjualan_kemarin'] = $this->safe(fn() => $this->has('sales')
            ? (float) DB::table('sales')->when($this->storeId, fn($q) => $q->where('store_id', $this->storeId))->whereDate('created_at', $yesterday)->sum('total') : 0, 0);
        $s['service_aktif'] = $this->safe(fn() => $this->has('service_tickets')
            ? DB::table('service_tickets')->when($this->storeId, fn($q) => $q->where('store_id', $this->storeId))->whereNotIn('status', ['diserahkan', 'batal'])->count() : 0, 0);
        $s['stok_menipis'] = $this->safe(fn() => ($this->has('product_stocks') && $this->has('products'))
            ? DB::table('product_stocks')->join('products', 'products.id', '=', 'product_stocks.product_id')
                ->when($this->storeId, fn($q) => $q->where('product_stocks.store_id', $this->storeId))
                ->whereRaw('product_stocks.qty <= COALESCE(product_stocks.min_stock, products.min_stock)')->count() : 0, 0);
        $s['kas_masuk_bulan'] = $this->safe(fn() => $this->has('cash_transactions')
            ? (float) DB::table('cash_transactions')->when($this->storeId, fn($q) => $q->where('store_id', $this->storeId))->where('type', 'masuk')->whereMonth('created_at', now()->month)->sum('amount') : 0, 0);
        $s['trx_hari_ini'] = $this->safe(fn() => $this->has('sales')
            ? DB::table('sales')->when($this->storeId, fn($q) => $q->where('store_id', $this->storeId))->whereDate('created_at', $today)->count() : 0, 0);
        $s['profit_kotor_hari_ini'] = $this->safe(fn() => ($this->has('sale_items') && $this->has('sales'))
            ? (float) DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                ->when($this->storeId, fn($q) => $q->where('sales.store_id', $this->storeId))
                ->whereDate('sales.created_at', $today)->sum('sale_items.profit') : 0, 0);
        $s['kas_diterima_hari_ini'] = $this->safe(fn() => ($this->has('sale_payments') && $this->has('sales'))
            ? (float) DB::table('sale_payments')->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
                ->when($this->storeId, fn($q) => $q->where('sales.store_id', $this->storeId))
                ->whereDate('sale_payments.created_at', $today)->sum('sale_payments.amount') : 0, 0);
        $s['siap_diambil'] = $this->safe(fn() => $this->has('service_tickets')
            ? DB::table('service_tickets')->when($this->storeId, fn($q) => $q->where('store_id', $this->storeId))->where('status', 'siap_diambil')->count() : 0, 0);
        $s['pelanggan_baru_30h'] = $this->safe(fn() => $this->has('customers')
            ? DB::table('customers')->when($this->storeId, fn($q) => $q->where('store_id', $this->storeId))->where('created_at', '>=', now()->subDays(30)->startOfDay())->count() : 0, 0);
        $s['piutang_outstanding'] = $this->safe(fn() => $this->has('debts')
            ? (float) DB::table('debts')->when($this->storeId, fn($q) => $q->where('store_id', $this->storeId))->where('type', 'piutang')->where('status', '!=', 'lunas')->sum('remaining') : 0, 0);

        $s['penjualan_diff'] = (float) $s['penjualan_hari_ini'] - (float) $s['penjualan_kemarin'];
        $s['penjualan_pct'] = ((float) $s['penjualan_kemarin']) > 0
            ? round($s['penjualan_diff'] / (float) $s['penjualan_kemarin'] * 100, 1) : null;
        return $s;
    }

    private function trends(): array
    {
        $labels = []; $revenue = []; $profit = [];
        $this->safe(function () use (&$labels, &$revenue, &$profit) {
            $rev = $this->has('sales') ? DB::table('sales')->selectRaw('DATE(created_at) as d, SUM(total) as t')
                ->when($this->storeId, fn($q) => $q->where('store_id', $this->storeId))
                ->where('created_at', '>=', now()->subDays(29)->startOfDay())->groupBy('d')->pluck('t', 'd') : collect();
            $prf = ($this->has('sale_items') && $this->has('sales')) ? DB::table('sale_items')
                ->join('sales', 'sales.id', '=', 'sale_items.sale_id')->selectRaw('DATE(sales.created_at) as d, SUM(sale_items.profit) as p')
                ->when($this->storeId, fn($q) => $q->where('sales.store_id', $this->storeId))
                ->where('sales.created_at', '>=', now()->subDays(29)->startOfDay())->groupBy('d')->pluck('p', 'd') : collect();
            for ($i = 29; $i >= 0; $i--) {
                $d = now()->subDays($i)->toDateString();
                $labels[] = substr($d, 5);
                $revenue[] = (float) ($rev[$d] ?? 0);
                $profit[] = (float) ($prf[$d] ?? 0);
            }
        }, null);
        return compact('labels', 'revenue', 'profit');
    }

    private function payMix(): array
    {
        $labels = []; $data = [];
        $this->safe(function () use (&$labels, &$data) {
            if (!($this->has('sale_payments') && $this->has('sales'))) return;
            $mix = DB::table('sale_payments')->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
                ->when($this->storeId, fn($q) => $q->where('sales.store_id', $this->storeId))
                ->whereMonth('sale_payments.created_at', now()->month)->whereYear('sale_payments.created_at', now()->year)
                ->groupBy('sale_payments.method')->selectRaw('sale_payments.method as m, SUM(sale_payments.amount) as t')->pluck('t', 'm');
            foreach ($mix as $m => $t) { $labels[] = $m; $data[] = (float) $t; }
        }, null);
        return compact('labels', 'data');
    }

    private function topProducts(): mixed
    {
        return $this->safe(fn() => ($this->has('sale_items') && $this->has('sales') && $this->has('products'))
            ? DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                ->join('products', 'products.id', '=', 'sale_items.product_id')
                ->when($this->storeId, fn($q) => $q->where('sales.store_id', $this->storeId))
                ->where('sales.created_at', '>=', now()->subDays(30)->startOfDay())
                ->groupBy('products.id', 'products.name')
                ->selectRaw('products.name as name, SUM(sale_items.qty) as qty, SUM(sale_items.subtotal) as omzet')
                ->orderByDesc('qty')->limit(5)->get() : collect(), collect());
    }

    /** Status operasional berurutan alur kerja; status tak dikenal di akhir. */
    public const SERVICE_FLOW = ['diterima', 'diagnosa', 'menunggu_persetujuan', 'dikerjakan', 'qc', 'siap_diambil', 'diserahkan', 'batal'];

    private function serviceOps(): array
    {
        $counts = $this->safe(fn() => $this->has('service_tickets')
            ? DB::table('service_tickets')->when($this->storeId, fn($q) => $q->where('store_id', $this->storeId))
                ->groupBy('status')->selectRaw('status, COUNT(*) as c')->pluck('c', 'status') : collect(), collect());
        $counts = collect($counts);
        $ordered = [];
        foreach (self::SERVICE_FLOW as $st) {
            if ($counts->has($st)) $ordered[$st] = (int) $counts[$st];
        }
        foreach ($counts as $st => $c) {
            if (!array_key_exists($st, $ordered)) $ordered[$st] = (int) $c;
        }
        $active = $counts->except(['diserahkan', 'batal'])->sum();
        return ['counts' => $ordered, 'active' => (int) $active, 'ready' => (int) ($counts['siap_diambil'] ?? 0)];
    }

    private function lowStocks(): mixed
    {
        return $this->safe(fn() => ($this->has('product_stocks') && $this->has('products'))
            ? DB::table('product_stocks')->join('products', 'products.id', '=', 'product_stocks.product_id')
                ->when($this->storeId, fn($q) => $q->where('product_stocks.store_id', $this->storeId))
                ->whereRaw('product_stocks.qty <= COALESCE(product_stocks.min_stock, products.min_stock)')
                ->select('products.name', 'product_stocks.qty')
                ->selectRaw('COALESCE(product_stocks.min_stock, products.min_stock) as min_stock')
                ->orderBy('product_stocks.qty')->limit(10)->get() : collect(), collect());
    }

    private function alerts(): array
    {
        $alerts = [];
        $push = function (string $level, string $icon, string $text, string $url) use (&$alerts) {
            $alerts[] = compact('level', 'icon', 'text', 'url');
        };
        $this->safe(function () use ($push) {
            if ($this->has('service_tickets')) {
                $late = DB::table('service_tickets')->when($this->storeId, fn($q) => $q->where('store_id', $this->storeId))
                    ->whereNotIn('status', ['diserahkan', 'batal'])->where('created_at', '<=', now()->subDays(3))->count();
                if ($late > 0) $push('mendesak', 'ti-tool', "$late tiket service terlambat (>3 hari belum selesai)", route('services.index'));
                $ready = DB::table('service_tickets')->when($this->storeId, fn($q) => $q->where('store_id', $this->storeId))
                    ->where('status', 'siap_diambil')->where('updated_at', '<=', now()->subDays(3))->count();
                if ($ready > 0) $push('hari_ini', 'ti-package', "$ready unit siap diambil >3 hari — ingatkan pelanggan", route('services.index'));
            }
        }, null);
        $this->safe(function () use ($push) {
            if ($this->has('bookings')) {
                $old = DB::table('bookings')->when($this->storeId, fn($q) => $q->where('store_id', $this->storeId))
                    ->where('status', 'menunggu')->where('created_at', '<=', now()->subHours(24))->count();
                if ($old > 0) $push('hari_ini', 'ti-calendar', "$old booking menunggu >24 jam", route('bookings.index'));
            }
        }, null);
        $this->safe(function () use ($push) {
            if ($this->has('debts')) {
                $due = DB::table('debts')->when($this->storeId, fn($q) => $q->where('store_id', $this->storeId))
                    ->where('status', '!=', 'lunas')->whereNotNull('due_date')
                    ->whereDate('due_date', '<=', now()->addDays(7)->toDateString())->count();
                if ($due > 0) $push('rutin', 'ti-cash', "$due hutang/piutang jatuh tempo ≤7 hari", route('debts.index'));
            }
        }, null);
        $this->safe(function () use ($push) {
            if ($this->has('wa_logs')) {
                $wa = DB::table('wa_logs')->when($this->storeId, fn($q) => $q->where('store_id', $this->storeId))->whereIn('status', ['gagal', 'antri'])->count();
                if ($wa > 5) $push('rutin', 'ti-brand-whatsapp', "$wa pesan WA gagal/antri", route('settings.wa'));
            }
        }, null);
        $this->safe(function () use ($push) {
            if ($this->has('promos')) {
                $exp = DB::table('promos')->when($this->storeId, fn($q) => $q->where(fn($w) => $w->where('store_id', $this->storeId)->orWhereNull('store_id')))
                    ->where('is_active', true)->whereNotNull('end_date')
                    ->whereDate('end_date', '>=', now()->toDateString())->whereDate('end_date', '<=', now()->addDays(7)->toDateString())->count();
                if ($exp > 0) $push('rutin', 'ti-tag', "$exp promo kedaluwarsa <7 hari", route('promos.index'));
            }
        }, null);
        $this->safe(function () use ($push) {
            if ($this->has('warranties')) {
                $wexp = DB::table('warranties')->when($this->storeId, function ($q) {
                    $q->where(fn($w) => $w
                        ->whereIn('customer_id', DB::table('customers')->where('store_id', $this->storeId)->select('id'))
                        ->orWhere(fn($x) => $x->where('ref_type', 'service')
                            ->whereIn('ref_id', DB::table('service_tickets')->where('store_id', $this->storeId)->select('id')))
                        ->orWhereNull('customer_id'));
                })->where('status', 'aktif')->whereNotNull('end_date')
                    ->whereDate('end_date', '<=', now()->addDays(14)->toDateString())->count();
                if ($wexp > 0) $push('rutin', 'ti-shield', "$wexp garansi berakhir ≤14 hari", route('warranties.index'));
            }
        }, null);
        // Kunci lama 'color'/'icon' emoji dipertahankan untuk kompatibilitas view lama.
        $color = ['mendesak' => 'red', 'hari_ini' => 'orange', 'rutin' => 'yellow'];
        foreach ($alerts as &$a) { $a['color'] = $color[$a['level']] ?? 'yellow'; $a['icon'] = $a['icon']; }
        return $alerts;
    }

    private function schedules(string $today, string $tomorrow): array
    {
        $out = ['todayBookings' => collect(), 'todayTickets' => collect(), 'tomorrowBookings' => collect(), 'tomorrowTickets' => collect()];
        $out['todayBookings'] = $this->safe(fn() => $this->has('bookings')
            ? DB::table('bookings')->when($this->storeId, fn($q) => $q->where('store_id', $this->storeId))
                ->whereDate('schedule_date', $today)->whereIn('status', ['menunggu', 'dikonfirmasi'])->orderBy('schedule_date')->limit(10)->get() : collect(), collect());
        $out['todayTickets'] = $this->safe(fn() => $this->has('service_tickets')
            ? DB::table('service_tickets')->when($this->storeId, fn($q) => $q->where('store_id', $this->storeId))
                ->whereDate('estimated_done_at', $today)->whereNotIn('status', ['diserahkan', 'batal'])->orderBy('estimated_done_at')->limit(10)->get() : collect(), collect());
        $out['tomorrowBookings'] = $this->safe(fn() => $this->has('bookings')
            ? DB::table('bookings')->when($this->storeId, fn($q) => $q->where('store_id', $this->storeId))
                ->whereDate('schedule_date', $tomorrow)->whereIn('status', ['menunggu', 'dikonfirmasi'])->orderBy('schedule_date')->limit(10)->get() : collect(), collect());
        $out['tomorrowTickets'] = $this->safe(fn() => $this->has('service_tickets')
            ? DB::table('service_tickets')->when($this->storeId, fn($q) => $q->where('store_id', $this->storeId))
                ->whereDate('estimated_done_at', $tomorrow)->whereNotIn('status', ['diserahkan', 'batal'])->orderBy('estimated_done_at')->limit(10)->get() : collect(), collect());
        return $out;
    }

    private function canSeeTargets(): bool
    {
        return in_array($this->role ?? '', ['superadmin', 'owner'], true);
    }

    private function targetWidget(): ?array
    {
        try {
            if (!$this->storeId || !$this->canSeeTargets()) return null;
            $monthKey = now()->format('Y-m');
            $targets = \App\Http\Controllers\StoreController::readTargets();
            $target = (float) ($targets[$this->storeId . ':' . $monthKey] ?? 0);
            $realisasi = $this->has('sales') ? (float) DB::table('sales')->where('store_id', $this->storeId)
                ->where('created_at', '>=', now()->startOfMonth()->toDateString())->sum('total') : 0;
            $storeName = null;
            try { $storeName = DB::table('stores')->where('id', $this->storeId)->value('name'); } catch (\Throwable $e) {}
            $now = now();
            $daysLeft = max(1, (int) $now->copy()->endOfMonth()->format('d') - (int) $now->format('d') + 1);
            $sisaTarget = max(0, $target - $realisasi);
            $todaySales = $this->has('sales') ? (float) DB::table('sales')->where('store_id', $this->storeId)
                ->whereDate('created_at', $now->toDateString())->sum('total') : 0;
            $dailyTarget = ($target > 0 && $sisaTarget > 0) ? $sisaTarget / $daysLeft : 0;
            return [
                'store_name' => $storeName, 'target' => $target, 'realisasi' => $realisasi,
                'pct' => $target > 0 ? min(100, round($realisasi / $target * 100, 1)) : 0,
                'month' => $now->format('m/Y'), 'days_left' => $daysLeft, 'sisa_target' => $sisaTarget,
                'today_sales' => $todaySales, 'daily_target' => $dailyTarget,
                'daily_diff' => $todaySales - $dailyTarget,
                'daily_reached' => ($target <= 0) ? null : ($sisaTarget <= 0 || $todaySales >= $dailyTarget),
            ];
        } catch (\Throwable $e) {
            $this->partial = true;
            Log::warning('Dashboard target gagal: ' . $e->getMessage());
            return null;
        }
    }

    private function annualTarget(): ?array
    {
        try {
            if (!$this->storeId || !$this->canSeeTargets()) return null;
            $year = now()->year;
            $allTargets = \App\Http\Controllers\StoreController::readTargets();
            $months = []; $annualTotal = 0; $monthsSet = 0;
            for ($m = 1; $m <= 12; $m++) {
                $key = $this->storeId . ':' . sprintf('%04d-%02d', $year, $m);
                $val = (float) ($allTargets[$key] ?? 0);
                $months[] = ['month' => $m, 'key' => sprintf('%04d-%02d', $year, $m), 'target' => $val,
                    'is_set' => $val > 0, 'is_past' => $m < (int) now()->month, 'is_current' => $m === (int) now()->month];
                if ($val > 0) { $annualTotal += $val; $monthsSet++; }
            }
            $ytd = $this->has('sales') ? (float) DB::table('sales')->where('store_id', $this->storeId)
                ->whereYear('created_at', $year)->where('created_at', '<=', now()->endOfDay())->sum('total') : 0;
            return ['year' => $year, 'total' => $annualTotal, 'ytd' => $ytd, 'sisa' => max(0, $annualTotal - $ytd),
                'pct' => $annualTotal > 0 ? min(100, round($ytd / $annualTotal * 100, 1)) : 0,
                'months_set' => $monthsSet, 'months' => $months];
        } catch (\Throwable $e) {
            $this->partial = true;
            Log::warning('Dashboard annual gagal: ' . $e->getMessage());
            return null;
        }
    }

    private function branchSummary(string $today): ?array
    {
        try {
            if (!$this->canSeeTargets() || !$this->has('stores')) return null;
            $stores = DB::table('stores')->orderBy('is_center', 'desc')->orderBy('name')->get();
            $rows = [];
            foreach ($stores as $st) {
                $row = ['store' => $st, 'omzet' => 0, 'trx' => 0, 'service_aktif' => 0, 'stok_kritis' => 0];
                try {
                    if ($this->has('sales')) {
                        $row['omzet'] = (float) DB::table('sales')->where('store_id', $st->id)->whereDate('created_at', $today)->sum('total');
                        $row['trx'] = DB::table('sales')->where('store_id', $st->id)->whereDate('created_at', $today)->count();
                    }
                    if ($this->has('service_tickets')) {
                        $row['service_aktif'] = DB::table('service_tickets')->where('store_id', $st->id)->whereNotIn('status', ['diserahkan', 'batal'])->count();
                    }
                    if ($this->has('product_stocks') && $this->has('products')) {
                        $row['stok_kritis'] = DB::table('product_stocks')->join('products', 'products.id', '=', 'product_stocks.product_id')
                            ->where('product_stocks.store_id', $st->id)
                            ->whereRaw('product_stocks.qty <= COALESCE(product_stocks.min_stock, products.min_stock)')->count();
                    }
                } catch (\Throwable $e) {
                    $this->partial = true;
                    Log::warning('Dashboard cabang gagal: ' . $e->getMessage());
                }
                $rows[] = $row;
            }
            return $rows;
        } catch (\Throwable $e) {
            $this->partial = true;
            Log::warning('Dashboard branch gagal: ' . $e->getMessage());
            return null;
        }
    }
}
