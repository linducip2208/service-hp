<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $storeId = session('active_store_id') ?? app()->bound('activeStoreId') ? app('activeStoreId') : null;
        $storeId = session('active_store_id');

        $today = now()->toDateString();
        $tomorrow = now()->addDay()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $stats = [
            'penjualan_hari_ini' => 0,
            'penjualan_kemarin' => 0,
            'penjualan_diff' => 0,
            'penjualan_pct' => null,
            'service_aktif' => 0,
            'stok_menipis' => 0,
            'kas_masuk_bulan' => 0,
        ];

        try {
            if (\Schema::hasTable('sales')) {
                $stats['penjualan_hari_ini'] = DB::table('sales')
                    ->when($storeId, fn($q) => $q->where('store_id', $storeId))
                    ->whereDate('created_at', $today)->sum('total');
                $stats['penjualan_kemarin'] = DB::table('sales')
                    ->when($storeId, fn($q) => $q->where('store_id', $storeId))
                    ->whereDate('created_at', $yesterday)->sum('total');
            }
            if (\Schema::hasTable('service_tickets')) {
                $stats['service_aktif'] = DB::table('service_tickets')
                    ->when($storeId, fn($q) => $q->where('store_id', $storeId))
                    ->whereNotIn('status', ['diserahkan', 'batal'])->count();
            }
            if (\Schema::hasTable('product_stocks')) {
                // Min EFEKTIF: product_stocks.min_stock ?? products.min_stock.
                $stats['stok_menipis'] = DB::table('product_stocks')
                    ->join('products', 'products.id', '=', 'product_stocks.product_id')
                    ->when($storeId, fn($q) => $q->where('product_stocks.store_id', $storeId))
                    ->whereRaw('product_stocks.qty <= COALESCE(product_stocks.min_stock, products.min_stock)')->count();
            }
            if (\Schema::hasTable('cash_transactions')) {
                $stats['kas_masuk_bulan'] = DB::table('cash_transactions')
                    ->when($storeId, fn($q) => $q->where('store_id', $storeId))
                    ->where('type', 'masuk')->whereMonth('created_at', now()->month)->sum('amount');
            }
        } catch (\Throwable $e) { }

        $stats['penjualan_diff'] = (float) ($stats['penjualan_hari_ini'] ?? 0) - (float) ($stats['penjualan_kemarin'] ?? 0);
        $stats['penjualan_pct'] = ((float) ($stats['penjualan_kemarin'] ?? 0)) > 0
            ? round($stats['penjualan_diff'] / (float) $stats['penjualan_kemarin'] * 100, 1)
            : null;

        $recentSales = collect();
        $recentService = collect();
        $revLabels = [];
        $revData = [];
        $payLabels = [];
        $payData = [];
        $topProducts = collect();
        $serviceByStatus = collect();
        $lowStocks = collect();
        $pendingBookings = collect();
        try {
            if (\Schema::hasTable('sales')) {
                $recentSales = DB::table('sales')->when($storeId, fn($q) => $q->where('store_id', $storeId))->orderByDesc('id')->limit(8)->get();
            }
            if (\Schema::hasTable('service_tickets')) {
                $recentService = DB::table('service_tickets')->when($storeId, fn($q) => $q->where('store_id', $storeId))->orderByDesc('id')->limit(8)->get();
            }
        } catch (\Throwable $e) { }

        try {
            // Omzet 30 hari terakhir (grafik garis)
            if (\Schema::hasTable('sales')) {
                $rows = DB::table('sales')->selectRaw('DATE(created_at) as d, SUM(total) as t')
                    ->when($storeId, fn($q) => $q->where('store_id', $storeId))
                    ->where('created_at', '>=', now()->subDays(29)->startOfDay())
                    ->groupBy('d')->pluck('t', 'd');
                for ($i = 29; $i >= 0; $i--) {
                    $d = now()->subDays($i)->toDateString();
                    $revLabels[] = substr($d, 5);
                    $revData[] = (float) ($rows[$d] ?? 0);
                }
            }
            // Donat metode pembayaran bulan ini (dari pembayaran yang benar-benar diterima)
            if (\Schema::hasTable('sale_payments') && \Schema::hasTable('sales')) {
                $mix = DB::table('sale_payments')
                    ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
                    ->when($storeId, fn($q) => $q->where('sales.store_id', $storeId))
                    ->whereMonth('sale_payments.created_at', now()->month)
                    ->whereYear('sale_payments.created_at', now()->year)
                    ->groupBy('sale_payments.method')
                    ->selectRaw('sale_payments.method as m, SUM(sale_payments.amount) as t')
                    ->pluck('t', 'm');
                foreach ($mix as $m => $t) { $payLabels[] = $m; $payData[] = (float) $t; }
            }
            // Top 5 produk terlaris 30 hari
            if (\Schema::hasTable('sale_items') && \Schema::hasTable('sales') && \Schema::hasTable('products')) {
                $topProducts = DB::table('sale_items')
                    ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                    ->join('products', 'products.id', '=', 'sale_items.product_id')
                    ->when($storeId, fn($q) => $q->where('sales.store_id', $storeId))
                    ->where('sales.created_at', '>=', now()->subDays(30)->startOfDay())
                    ->groupBy('products.id', 'products.name')
                    ->selectRaw('products.name as name, SUM(sale_items.qty) as qty, SUM(sale_items.subtotal) as omzet')
                    ->orderByDesc('qty')->limit(5)->get();
            }
            // Ringkasan status service
            if (\Schema::hasTable('service_tickets')) {
                $serviceByStatus = DB::table('service_tickets')
                    ->when($storeId, fn($q) => $q->where('store_id', $storeId))
                    ->groupBy('status')->selectRaw('status, COUNT(*) as c')->pluck('c', 'status');
            }
            // Stok menipis (detail 10 terendah) — min efektif per toko.
            if (\Schema::hasTable('product_stocks') && \Schema::hasTable('products')) {
                $lowStocks = DB::table('product_stocks')
                    ->join('products', 'products.id', '=', 'product_stocks.product_id')
                    ->when($storeId, fn($q) => $q->where('product_stocks.store_id', $storeId))
                    ->whereRaw('product_stocks.qty <= COALESCE(product_stocks.min_stock, products.min_stock)')
                    ->select('products.name', 'product_stocks.qty')
                    ->selectRaw('COALESCE(product_stocks.min_stock, products.min_stock) as min_stock')
                    ->orderBy('product_stocks.qty')->limit(10)->get();
            }
            // Booking menunggu konfirmasi
            if (\Schema::hasTable('bookings')) {
                $pendingBookings = DB::table('bookings')
                    ->when($storeId, fn($q) => $q->where('store_id', $storeId))
                    ->whereNotIn('status', ['dikonfirmasi', 'dibatalkan', 'selesai'])
                    ->orderByDesc('id')->limit(8)->get();
            }
        } catch (\Throwable $e) { }

        // Widget "Perlu Perhatian" (owner/superadmin & admin) — tiap query aman try/catch sendiri
        $alerts = [];
        try {
            if (\Schema::hasTable('service_tickets')) {
                $late = DB::table('service_tickets')
                    ->when($storeId, fn($q) => $q->where('store_id', $storeId))
                    ->whereNotIn('status', ['diserahkan', 'batal'])
                    ->where('created_at', '<=', now()->subDays(3))
                    ->count();
                if ($late > 0) $alerts[] = ['icon' => '🔧', 'text' => "$late tiket service terlambat (>3 hari belum selesai)", 'url' => route('services.index'), 'color' => 'red'];
            }
        } catch (\Throwable $e) {}
        try {
            if (\Schema::hasTable('bookings')) {
                $old = DB::table('bookings')
                    ->when($storeId, fn($q) => $q->where('store_id', $storeId))
                    ->where('status', 'menunggu')
                    ->where('created_at', '<=', now()->subHours(24))
                    ->count();
                if ($old > 0) $alerts[] = ['icon' => '⏳', 'text' => "$old booking menunggu >24 jam", 'url' => route('bookings.index'), 'color' => 'yellow'];
            }
        } catch (\Throwable $e) {}
        try {
            if (\Schema::hasTable('debts')) {
                $due = DB::table('debts')
                    ->when($storeId, fn($q) => $q->where('store_id', $storeId))
                    ->where('status', '!=', 'lunas')
                    ->whereNotNull('due_date')
                    ->whereDate('due_date', '<=', now()->addDays(7)->toDateString())
                    ->count();
                if ($due > 0) $alerts[] = ['icon' => '💸', 'text' => "$due hutang/piutang jatuh tempo ≤7 hari (termasuk lewat jatuh tempo)", 'url' => route('debts.index'), 'color' => 'orange'];
            }
        } catch (\Throwable $e) {}
        try {
            if (\Schema::hasTable('wa_logs')) {
                $wa = DB::table('wa_logs')
                    ->when($storeId, fn($q) => $q->where('store_id', $storeId))
                    ->whereIn('status', ['gagal', 'antri'])
                    ->count();
                if ($wa > 5) $alerts[] = ['icon' => '📲', 'text' => "$wa pesan WA gagal/antri", 'url' => route('settings.wa'), 'color' => 'red'];
            }
        } catch (\Throwable $e) {}
        try {
            if (\Schema::hasTable('promos')) {
                $exp = DB::table('promos')
                    ->when($storeId, fn($q) => $q->where(function ($w) use ($storeId) { $w->where('store_id', $storeId)->orWhereNull('store_id'); }))
                    ->where('is_active', true)
                    ->whereNotNull('end_date')
                    ->whereDate('end_date', '>=', now()->toDateString())
                    ->whereDate('end_date', '<=', now()->addDays(7)->toDateString())
                    ->count();
                if ($exp > 0) $alerts[] = ['icon' => '🏷️', 'text' => "$exp promo kedaluwarsa <7 hari", 'url' => route('promos.index'), 'color' => 'yellow'];
            }
        } catch (\Throwable $e) {}
        try {
            if (\Schema::hasTable('warranties')) {
                $wexp = DB::table('warranties')
                    ->when($storeId, function ($q) use ($storeId) {
                        $q->where(function ($w) use ($storeId) {
                            $w->whereIn('customer_id', DB::table('customers')->where('store_id', $storeId)->select('id'))
                                ->orWhere(function ($x) use ($storeId) {
                                    $x->where('ref_type', 'service')
                                        ->whereIn('ref_id', DB::table('service_tickets')->where('store_id', $storeId)->select('id'));
                                })
                                ->orWhereNull('customer_id');
                        });
                    })
                    ->where('status', 'aktif')
                    ->whereNotNull('end_date')
                    ->whereDate('end_date', '<=', now()->addDays(14)->toDateString())
                    ->count();
                if ($wexp > 0) $alerts[] = ['icon' => '🛡️', 'text' => "$wexp garansi berakhir ≤14 hari (status aktif, termasuk yang sudah lewat)", 'url' => route('warranties.index'), 'color' => 'yellow'];
            }
        } catch (\Throwable $e) {}
        // Widget "Jadwal Hari Ini": booking schedule_date hari ini (menunggu/dikonfirmasi)
        // + tiket service estimated_done_at hari ini & belum diserahkan/batal.
        $todayBookings = collect();
        $todayTickets = collect();
        $tomorrowBookings = collect();
        $tomorrowTickets = collect();
        try {
            if (\Schema::hasTable('bookings')) {
                $todayBookings = DB::table('bookings')
                    ->when($storeId, fn($q) => $q->where('store_id', $storeId))
                    ->whereDate('schedule_date', $today)
                    ->whereIn('status', ['menunggu', 'dikonfirmasi'])
                    ->orderBy('schedule_date')->limit(10)->get();
            }
        } catch (\Throwable $e) {}
        try {
            if (\Schema::hasTable('service_tickets')) {
                $todayTickets = DB::table('service_tickets')
                    ->when($storeId, fn($q) => $q->where('store_id', $storeId))
                    ->whereDate('estimated_done_at', $today)
                    ->whereNotIn('status', ['diserahkan', 'batal'])
                    ->orderBy('estimated_done_at')->limit(10)->get();
            }
        } catch (\Throwable $e) {}
        try {
            if (\Schema::hasTable('bookings')) {
                $tomorrowBookings = DB::table('bookings')
                    ->when($storeId, fn($q) => $q->where('store_id', $storeId))
                    ->whereDate('schedule_date', $tomorrow)
                    ->whereIn('status', ['menunggu', 'dikonfirmasi'])
                    ->orderBy('schedule_date')->limit(10)->get();
            }
        } catch (\Throwable $e) {}
        try {
            if (\Schema::hasTable('service_tickets')) {
                $tomorrowTickets = DB::table('service_tickets')
                    ->when($storeId, fn($q) => $q->where('store_id', $storeId))
                    ->whereDate('estimated_done_at', $tomorrow)
                    ->whereNotIn('status', ['diserahkan', 'batal'])
                    ->orderBy('estimated_done_at')->limit(10)->get();
            }
        } catch (\Throwable $e) {}
        // Selisih kasir kemarin: tidak ada tabel persisten (closing dihitung live) → dilewati.

        // Widget target omzet toko aktif bulan ini (owner/superadmin; sumber: storage/app/targets.json).
        $targetWidget = null;
        try {
            if ($storeId && in_array(auth()->user()->role ?? '', ['superadmin', 'owner'])) {
                $monthKey = now()->format('Y-m');
                $targets = \App\Http\Controllers\StoreController::readTargets();
                $target = (float) ($targets[$storeId . ':' . $monthKey] ?? 0);
                $realisasi = 0;
                if (\Schema::hasTable('sales')) {
                    $realisasi = (float) DB::table('sales')->where('store_id', $storeId)
                        ->where('created_at', '>=', now()->startOfMonth()->toDateString())->sum('total');
                }
                $storeName = null;
                try { $storeName = DB::table('stores')->where('id', $storeId)->value('name'); } catch (\Throwable $e) {}
                // Target harian: kejar sisa target bulan dibagi sisa hari (termasuk hari ini).
                $now = now();
                $daysInMonth = (int) $now->copy()->endOfMonth()->format('d');
                $dayToday = (int) $now->format('d');
                $daysLeft = max(1, $daysInMonth - $dayToday + 1);
                $sisaTarget = max(0, $target - $realisasi);
                $todaySales = 0;
                if (\Schema::hasTable('sales')) {
                    try {
                        $todaySales = (float) DB::table('sales')->where('store_id', $storeId)
                            ->whereDate('created_at', $now->toDateString())->sum('total');
                    } catch (\Throwable $e) {}
                }
                $dailyTarget = ($target > 0 && $sisaTarget > 0) ? $sisaTarget / $daysLeft : 0;
                $dailyDiff = $todaySales - $dailyTarget;
                $dailyReached = ($target <= 0) ? null : ($sisaTarget <= 0 || $todaySales >= $dailyTarget);
                $targetWidget = [
                    'store_name' => $storeName, 'target' => $target, 'realisasi' => $realisasi,
                    'pct' => $target > 0 ? min(100, round($realisasi / $target * 100, 1)) : 0,
                    'month' => $now->format('m/Y'),
                    'days_left' => $daysLeft,
                    'sisa_target' => $sisaTarget,
                    'today_sales' => $todaySales,
                    'daily_target' => $dailyTarget,
                    'daily_diff' => $dailyDiff,
                    'daily_reached' => $dailyReached,
                ];
            }
        } catch (\Throwable $e) { $targetWidget = null; }

        // Progres tahunan: sum target bulanan Jan-Des tahun ini (yang sudah diset)
        // vs realisasi YTD (sum sales 1 Jan s/d hari ini) + sisa. Tanpa tabel/route baru.
        $annualTarget = null;
        try {
            if ($storeId && in_array(auth()->user()->role ?? '', ['superadmin', 'owner'])) {
                $year = now()->year;
                $allTargets = \App\Http\Controllers\StoreController::readTargets();
                $months = [];
                $annualTotal = 0;
                $monthsSet = 0;
                for ($m = 1; $m <= 12; $m++) {
                    $key = $storeId . ':' . sprintf('%04d-%02d', $year, $m);
                    $val = (float) ($allTargets[$key] ?? 0);
                    $months[] = [
                        'month' => $m,
                        'key' => sprintf('%04d-%02d', $year, $m),
                        'target' => $val,
                        'is_set' => $val > 0,
                        'is_past' => $m < (int) now()->month,
                        'is_current' => $m === (int) now()->month,
                    ];
                    if ($val > 0) {
                        $annualTotal += $val;
                        $monthsSet++;
                    }
                }
                $ytd = 0;
                if (\Schema::hasTable('sales')) {
                    $ytd = (float) DB::table('sales')
                        ->where('store_id', $storeId)
                        ->whereYear('created_at', $year)
                        ->where('created_at', '<=', now()->endOfDay())
                        ->sum('total');
                }
                $annualPct = $annualTotal > 0
                    ? min(100, round($ytd / $annualTotal * 100, 1))
                    : 0;
                $annualSisa = max(0, $annualTotal - $ytd);
                $annualTarget = [
                    'year' => $year,
                    'total' => $annualTotal,
                    'ytd' => $ytd,
                    'sisa' => $annualSisa,
                    'pct' => $annualPct,
                    'months_set' => $monthsSet,
                    'months' => $months,
                ];
            }
        } catch (\Throwable $e) { $annualTarget = null; }

        return view('dashboard', compact('stats', 'recentSales', 'recentService', 'revLabels', 'revData', 'payLabels', 'payData', 'topProducts', 'serviceByStatus', 'lowStocks', 'pendingBookings', 'alerts', 'todayBookings', 'todayTickets', 'tomorrowBookings', 'tomorrowTickets', 'tomorrow', 'targetWidget', 'annualTarget'));
    }

    public function activity(Request $request)
    {
        $storeId = session('active_store_id');
        $type = $request->query('type'); // sale|service|stock|cash|null
        $events = [];

        try {
            if ((!$type || $type === 'sale') && \Schema::hasTable('sales')) {
                $sales = \App\Models\Sale::with('user:id,name')
                    ->when($storeId, fn($q) => $q->where('store_id', $storeId))
                    ->orderByDesc('id')->limit(15)->get();
                foreach ($sales as $s) {
                    $events[] = [
                        'time' => $s->created_at, 'kind' => 'sale',
                        'icon' => '🛒', 'color' => 'green',
                        'title' => 'Penjualan ' . ($s->invoice_no ?? "#{$s->id}"),
                        'desc' => 'Rp ' . number_format((float) $s->total, 0, ',', '.') . ' • ' . ($s->payment_method ?? '-'),
                        'url' => route('sales.show', $s->id),
                        'user' => $s->user->name ?? '-',
                    ];
                }
            }
        } catch (\Throwable $e) {}
        try {
            if ((!$type || $type === 'service') && \Schema::hasTable('service_logs')) {
                $logs = \App\Models\ServiceLog::with(['ticket:id,ticket_no,store_id', 'user:id,name'])
                    ->when($storeId, fn($q) => $q->whereHas('ticket', fn($t) => $t->where('store_id', $storeId)))
                    ->orderByDesc('id')->limit(15)->get();
                foreach ($logs as $l) {
                    $events[] = [
                        'time' => $l->created_at, 'kind' => 'service',
                        'icon' => '🔧', 'color' => 'blue',
                        'title' => 'Service ' . ($l->ticket->ticket_no ?? "#{$l->service_ticket_id}") . ': ' . ($l->from_status ?? '-') . ' → ' . ($l->to_status ?? '-'),
                        'desc' => $l->note ?: 'Perubahan status tiket service.',
                        'url' => route('services.show', $l->service_ticket_id),
                        'user' => $l->user->name ?? '-',
                    ];
                }
            }
        } catch (\Throwable $e) {}
        try {
            if ((!$type || $type === 'stock') && \Schema::hasTable('stock_movements')) {
                $moves = \App\Models\StockMovement::with(['product:id,name', 'user:id,name'])
                    ->when($storeId, fn($q) => $q->where('store_id', $storeId))
                    ->where(fn($q) => $q->whereIn('type', ['mutasi_masuk', 'mutasi_keluar', 'opname'])->orWhere('qty', '>=', 10))
                    ->orderByDesc('id')->limit(15)->get();
                foreach ($moves as $m) {
                    $events[] = [
                        'time' => $m->created_at, 'kind' => 'stock',
                        'icon' => '📦', 'color' => 'yellow',
                        'title' => 'Stok [' . ($m->type ?? '-') . '] ' . ($m->product->name ?? "produk #{$m->product_id}") . ' × ' . $m->qty,
                        'desc' => $m->note ?: 'Mutasi/opname stok.',
                        'url' => route('stocks.mutations'),
                        'user' => $m->user->name ?? '-',
                    ];
                }
            }
        } catch (\Throwable $e) {}
        try {
            if ((!$type || $type === 'cash') && \Schema::hasTable('cash_transactions')) {
                $txns = \App\Models\CashTransaction::with('user:id,name')
                    ->when($storeId, fn($q) => $q->where('store_id', $storeId))
                    ->where('type', 'keluar')->where('amount', '>=', 500000)
                    ->orderByDesc('id')->limit(15)->get();
                foreach ($txns as $c) {
                    $events[] = [
                        'time' => $c->created_at, 'kind' => 'cash',
                        'icon' => '💸', 'color' => 'red',
                        'title' => 'Kas keluar Rp ' . number_format((float) $c->amount, 0, ',', '.') . ' • ' . ($c->category ?? 'lain'),
                        'desc' => $c->description ?: 'Pengeluaran besar (≥ Rp 500rb).',
                        'url' => route('cash.index'),
                        'user' => $c->user->name ?? '-',
                    ];
                }
            }
        } catch (\Throwable $e) {}

        usort($events, fn($a, $b) => ($b['time']?->timestamp ?? 0) <=> ($a['time']?->timestamp ?? 0));
        $events = array_slice($events, 0, 30);

        return view('dashboard.activity', compact('events', 'type'));
    }

    /**
     * Pencarian global (navbar → GET /search?q=).
     * 1 route, tanpa migration. Scope toko aktif untuk data ber-store_id
     * (Sale, ServiceTicket, Customer, ProductItem); Product adalah master
     * global sehingga tidak di-scope toko (selaras products.index).
     * Tiap kelompok dilimit 5 baris; "Lihat Semua" mengarah ke index
     * masing-masing dengan parameter filter yang sudah didukung index
     * tersebut (services memakai ?search=, sisanya ?q=).
     */
    public function search(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $q = mb_substr($q, 0, 100);
        $storeId = session('active_store_id') ?? auth()->user()->active_store_id ?? null;

        $sales = collect();
        $services = collect();
        $customers = collect();
        $products = collect();
        $units = collect();

        if ($q !== '') {
            $sales = \App\Models\Sale::with(['customer'])
                ->when($storeId, fn($s) => $s->where('store_id', $storeId))
                ->where('invoice_no', 'like', '%' . $q . '%')
                ->orderByDesc('id')
                ->limit(5)
                ->get();

            $services = \App\Models\ServiceTicket::with(['customer'])
                ->when($storeId, fn($s) => $s->where('store_id', $storeId))
                ->where(function ($w) use ($q) {
                    $w->where('ticket_no', 'like', '%' . $q . '%')
                        ->orWhere('imei', 'like', '%' . $q . '%');
                })
                ->orderByDesc('id')
                ->limit(5)
                ->get();

            $customers = \App\Models\Customer::query()
                ->when($storeId, fn($s) => $s->where('store_id', $storeId))
                ->where(function ($w) use ($q) {
                    $w->where('name', 'like', '%' . $q . '%')
                        ->orWhere('phone', 'like', '%' . $q . '%');
                })
                ->orderByDesc('id')
                ->limit(5)
                ->get();

            $products = \App\Models\Product::with(['brand'])
                ->where(function ($w) use ($q) {
                    $w->where('name', 'like', '%' . $q . '%')
                        ->orWhere('sku', 'like', '%' . $q . '%');
                })
                ->orderByDesc('id')
                ->limit(5)
                ->get();

            $units = \App\Models\ProductItem::with(['product', 'store'])
                ->when($storeId, fn($s) => $s->where('product_items.store_id', $storeId))
                ->where(function ($w) use ($q) {
                    $w->where('product_items.imei', 'like', '%' . $q . '%')
                        ->orWhere('product_items.serial', 'like', '%' . $q . '%');
                })
                ->orderByDesc('product_items.id')
                ->limit(5)
                ->get();
        }

        return view('search.index', compact('q', 'sales', 'services', 'customers', 'products', 'units'));
    }

    public function health()
    {
        abort_unless(in_array(auth()->user()->role ?? '', ['superadmin', 'owner']), 403, 'Hanya owner/superadmin.');
        // Scope: semua toko (khusus owner/superadmin). Tiap cek dibungkus try/catch sendiri.

        $unitsSold = collect();
        $negStocks = collect();
        $saleGaps = collect();
        $cashGaps = collect();
        $debtGaps = collect();
        $badCommissions = collect();
        $orphanServiceUnits = collect();
        $expiredWarranties = collect();
        $checkErrors = [];

        // 1. Unit IMEI status 'tersedia' tapi sudah ada di sale_items (terjual).
        //    Aman difix: hanya update status → 'terjual'. Unit yang penjualannya
        //    punya retur dikecualikan (bisa jadi memang kembali tersedia).
        try {
            if (\Schema::hasTable('product_items') && \Schema::hasTable('sale_items') && \Schema::hasTable('sales')) {
                $q = \DB::table('product_items')
                    ->join('sale_items', 'sale_items.product_item_id', '=', 'product_items.id')
                    ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                    ->leftJoin('stores', 'stores.id', '=', 'product_items.store_id')
                    ->where('product_items.status', 'tersedia')
                    ->select(
                        'product_items.id', 'product_items.imei', 'product_items.serial',
                        'product_items.store_id', 'stores.name as store_name',
                        'sales.id as sale_id', 'sales.invoice_no'
                    );
                if (\Schema::hasTable('sale_returns')) {
                    $q->leftJoin('sale_returns', 'sale_returns.sale_id', '=', 'sales.id')
                        ->whereNull('sale_returns.id');
                }
                $unitsSold = $q->orderByDesc('sales.id')->limit(100)->get();
            }
        } catch (\Throwable $e) { $checkErrors[] = 'Cek unit terjual gagal: ' . $e->getMessage(); }

        // 2. Stok negatif — tampilkan saja (perlu opname manual).
        try {
            if (\Schema::hasTable('product_stocks') && \Schema::hasTable('products')) {
                $negStocks = \DB::table('product_stocks')
                    ->join('products', 'products.id', '=', 'product_stocks.product_id')
                    ->leftJoin('stores', 'stores.id', '=', 'product_stocks.store_id')
                    ->where('product_stocks.qty', '<', 0)
                    ->select('product_stocks.id', 'product_stocks.qty', 'products.name as product_name', 'stores.name as store_name')
                    ->orderBy('product_stocks.qty')->limit(100)->get();
            }
        } catch (\Throwable $e) { $checkErrors[] = 'Cek stok negatif gagal: ' . $e->getMessage(); }

        // 3. Sale remaining != total - paid_amount — tampilkan saja (kolom paid
        //    sendiri bisa salah vs tabel pembayaran; butuh telaah manual).
        try {
            if (\Schema::hasTable('sales')) {
                $rows = \DB::table('sales')
                    ->select('id', 'invoice_no', 'store_id', 'total', 'paid_amount', 'remaining')
                    ->orderByDesc('id')->limit(2000)->get();
                $paySums = [];
                if (\Schema::hasTable('sale_payments') && $rows->count()) {
                    $paySums = \DB::table('sale_payments')
                        ->whereIn('sale_id', $rows->pluck('id')->all())
                        ->groupBy('sale_id')->selectRaw('sale_id, SUM(amount) as s')
                        ->pluck('s', 'sale_id');
                }
                foreach ($rows as $s) {
                    $expected = (float) $s->total - (float) $s->paid_amount;
                    if (abs((float) $s->remaining - $expected) > 1) {
                        $s->expected = $expected;
                        $s->diff = (float) $s->remaining - $expected;
                        $s->paid_sum = isset($paySums[$s->id]) ? (float) $paySums[$s->id] : null;
                        $saleGaps->push($s);
                        if ($saleGaps->count() >= 100) break;
                    }
                }
            }
        } catch (\Throwable $e) { $checkErrors[] = 'Cek selisih sale gagal: ' . $e->getMessage(); }

        // 4. CashAccount balance != sum mutasi (masuk - keluar).
        //    Tidak ada tombol hitung-ulang: tabel cash_accounts TIDAK punya kolom
        //    saldo awal, jadi selisih bisa berasal dari opening yang tak terlacak.
        try {
            if (\Schema::hasTable('cash_accounts') && \Schema::hasTable('cash_transactions')) {
                $accounts = \DB::table('cash_accounts')
                    ->leftJoin('stores', 'stores.id', '=', 'cash_accounts.store_id')
                    ->select('cash_accounts.id', 'cash_accounts.name', 'cash_accounts.balance', 'stores.name as store_name')
                    ->orderBy('cash_accounts.id')->limit(200)->get();
                // Batch: 1 query agregat mutasi per akun (dulu 2x sum per akun = N+1).
                $mutasi = collect();
                if ($accounts->isNotEmpty()) {
                    $mutasi = \DB::table('cash_transactions')
                        ->whereIn('cash_account_id', $accounts->pluck('id')->all())
                        ->selectRaw("cash_account_id, SUM(CASE WHEN type = 'masuk' THEN amount ELSE 0 END) as masuk, SUM(CASE WHEN type = 'keluar' THEN amount ELSE 0 END) as keluar")
                        ->groupBy('cash_account_id')->get()->keyBy('cash_account_id');
                }
                foreach ($accounts as $a) {
                    $in = (float) ($mutasi->get($a->id)->masuk ?? 0);
                    $out = (float) ($mutasi->get($a->id)->keluar ?? 0);
                    $calc = $in - $out;
                    if (abs((float) $a->balance - $calc) > 1) {
                        $a->mutasi_masuk = $in;
                        $a->mutasi_keluar = $out;
                        $a->mutasi_neto = $calc;
                        $a->diff = (float) $a->balance - $calc;
                        $cashGaps->push($a);
                    }
                }
            }
        } catch (\Throwable $e) { $checkErrors[] = 'Cek selisih kas gagal: ' . $e->getMessage(); }

        // 5. Debt remaining != total - paid — tampilkan saja.
        try {
            if (\Schema::hasTable('debts')) {
                $rows = \DB::table('debts')
                    ->select('id', 'store_id', 'type', 'party_name', 'total', 'paid', 'remaining', 'status')
                    ->orderByDesc('id')->limit(2000)->get();
                foreach ($rows as $d) {
                    $expected = (float) $d->total - (float) $d->paid;
                    if (abs((float) $d->remaining - $expected) > 1) {
                        $d->expected = $expected;
                        $d->diff = (float) $d->remaining - $expected;
                        $debtGaps->push($d);
                        if ($debtGaps->count() >= 100) break;
                    }
                }
            }
        } catch (\Throwable $e) { $checkErrors[] = 'Cek selisih hutang gagal: ' . $e->getMessage(); }

        // 6. Komisi teknisi untuk tiket batal — aman dihapus bila masih 'menunggu'.
        try {
            if (\Schema::hasTable('technician_commissions') && \Schema::hasTable('service_tickets')) {
                $badCommissions = \DB::table('technician_commissions')
                    ->join('service_tickets', 'service_tickets.id', '=', 'technician_commissions.service_ticket_id')
                    ->leftJoin('users', 'users.id', '=', 'technician_commissions.technician_id')
                    ->where('service_tickets.status', 'batal')
                    ->where('technician_commissions.status', 'menunggu')
                    ->select(
                        'technician_commissions.id', 'technician_commissions.amount',
                        'service_tickets.ticket_no', 'users.name as technician_name'
                    )
                    ->orderByDesc('technician_commissions.id')->limit(100)->get();
            }
        } catch (\Throwable $e) { $checkErrors[] = 'Cek komisi batal gagal: ' . $e->getMessage(); }

        // 7. Unit status 'service' yang tiket aktifnya sudah tidak ada (yatim).
        //    Aman difix: hanya update status → 'tersedia'. Hanya unit ber-IMEI
        //    non-kosong yang IMEI-nya persis tidak punya tiket aktif
        //    (status selain diserahkan/batal). Tanpa IMEI → dikecualikan
        //    (tidak bisa dibuktikan milik tiket mana, periksa manual).
        try {
            if (\Schema::hasTable('product_items') && \Schema::hasTable('service_tickets')) {
                $orphanServiceUnits = \DB::table('product_items as pi')
                    ->leftJoin('stores', 'stores.id', '=', 'pi.store_id')
                    ->leftJoin('products', 'products.id', '=', 'pi.product_id')
                    ->where('pi.status', 'service')
                    ->whereNotNull('pi.imei')->where('pi.imei', '!=', '')
                    ->whereNotExists(function ($q) {
                        $q->select(\DB::raw(1))->from('service_tickets as st')
                            ->whereRaw('st.imei = pi.imei')
                            ->whereNotIn('st.status', ['diserahkan', 'batal']);
                    })
                    ->select(
                        'pi.id', 'pi.imei', 'pi.serial',
                        'pi.store_id', 'stores.name as store_name',
                        'products.name as product_name'
                    )
                    ->orderByDesc('pi.id')->limit(100)->get();
            }
        } catch (\Throwable $e) { $checkErrors[] = 'Cek unit service yatim gagal: ' . $e->getMessage(); }

        // 8. Garansi kedaluwarsa: status 'aktif' tapi end_date < hari ini.
        //    Aman difix: hanya update status → 'selesai' + append marker ke
        //    claim_note. Tanpa hapus baris. Scope: semua toko.
        try {
            if (\Schema::hasTable('warranties')) {
                $expiredWarranties = \DB::table('warranties')
                    ->leftJoin('customers', 'customers.id', '=', 'warranties.customer_id')
                    ->where('warranties.status', 'aktif')
                    ->whereNotNull('warranties.end_date')
                    ->whereDate('warranties.end_date', '<', now()->toDateString())
                    ->select(
                        'warranties.id', 'warranties.ref_type', 'warranties.ref_id',
                        'warranties.end_date', 'warranties.claim_note',
                        'customers.name as customer_name'
                    )
                    ->orderBy('warranties.end_date')->limit(100)->get();
            }
        } catch (\Throwable $e) { $checkErrors[] = 'Cek garansi kedaluwarsa gagal: ' . $e->getMessage(); }

        // 9. Kapasitas penyimpanan (disk) — info saja, tanpa migration.
        $disk = ['total' => null, 'free' => null, 'used' => null, 'pct' => null, 'badge' => 'bg-green-lt', 'label' => 'Tidak terbaca'];
        try {
            $path = storage_path();
            $total = @disk_total_space($path);
            $free = @disk_free_space($path);
            if ($total && $free !== false) {
                $used = $total - $free;
                $pct = $total > 0 ? round($used / $total * 100, 1) : null;
                $badge = $pct === null ? 'bg-green-lt' : ($pct > 90 ? 'bg-red-lt' : ($pct >= 70 ? 'bg-yellow-lt' : 'bg-green-lt'));
                $label = $pct === null ? 'Tidak terbaca' : ($pct > 90 ? 'Penuh' : ($pct >= 70 ? 'Waspada' : 'Aman'));
                $disk = compact('total', 'free', 'used', 'pct', 'badge', 'label');
            }
        } catch (\Throwable $e) { $checkErrors[] = 'Cek kapasitas disk gagal: ' . $e->getMessage(); }

        return view('dashboard.health', compact('unitsSold', 'negStocks', 'saleGaps', 'cashGaps', 'debtGaps', 'badCommissions', 'orphanServiceUnits', 'expiredWarranties', 'checkErrors', 'disk'));
    }

    /**
     * Aksi 100% aman: unit yang terbukti terjual (ada di sale_items, tanpa retur)
     * hanya diupdate statusnya → 'terjual'. Tidak menyentuh stok/tab lain.
     */
    public function fixUnit($id)
    {
        abort_unless(in_array(auth()->user()->role ?? '', ['superadmin', 'owner']), 403, 'Hanya owner/superadmin.');
        $unit = \App\Models\ProductItem::findOrFail($id);
        $this->applyFixUnitSold($unit);
        return back()->with('success', 'Unit ' . ($unit->imei ?: "#{$unit->id}") . ' ditandai terjual.');
    }

    /**
     * Aksi 100% aman: unit status 'service' yang terbukti yatim (tidak ada
     * tiket aktif dengan IMEI sama) hanya diupdate statusnya → 'tersedia'.
     * Tidak menyentuh stok/tab lain, tanpa kolom note baru.
     */
    public function fixServiceUnit($id)
    {
        abort_unless(in_array(auth()->user()->role ?? '', ['superadmin', 'owner']), 403, 'Hanya owner/superadmin.');
        $unit = \App\Models\ProductItem::findOrFail($id);
        $this->applyFixServiceUnit($unit);
        return back()->with('success', 'Unit ' . ($unit->imei ?: "#{$unit->id}") . ' dikembalikan ke tersedia.');
    }

    /**
     * Aksi 100% aman: hapus komisi berstatus 'menunggu' milik tiket 'batal'.
     * Komisi yang sudah 'dibayar' tidak disentuh (butuh koreksi kas manual).
     */
    public function fixCommission($id)
    {
        abort_unless(in_array(auth()->user()->role ?? '', ['superadmin', 'owner']), 403, 'Hanya owner/superadmin.');
        $c = \App\Models\TechnicianCommission::findOrFail($id);
        $this->applyFixCommission($c);
        return back()->with('success', 'Komisi menunggu untuk tiket batal dihapus.');
    }

    /**
     * Aksi 100% aman: garansi 'aktif' yang end_date-nya sudah lewat (< hari ini)
     * hanya diupdate statusnya → 'selesai' + marker di claim_note.
     * Tidak menghapus baris, tidak menyentuh tabel lain.
     */
    public function fixWarranty($id)
    {
        abort_unless(in_array(auth()->user()->role ?? '', ['superadmin', 'owner']), 403, 'Hanya owner/superadmin.');
        $w = \App\Models\Warranty::findOrFail($id);
        $this->applyFixWarranty($w);
        return back()->with('success', "Garansi #{$w->id} ditandai kedaluwarsa (status → selesai).");
    }

    /**
     * 1-KLIK AMAN: jalankan 4 aksi aman berurutan —
     * (1) unit "tersedia" yang terbukti terjual → 'terjual',
     * (2) unit "service" yatim → 'tersedia',
     * (3) komisi 'menunggu' milik tiket 'batal' → hapus,
     * (4) garansi 'aktif' kedaluwarsa → 'selesai'.
     * Tiap item yang gagal dilewati dan dilaporkan, proses lanjut.
     * Tanpa migration, tanpa menyentuh stok/kas/sale/debt.
     */
    public function fixAllSafe()
    {
        abort_unless(in_array(auth()->user()->role ?? '', ['superadmin', 'owner']), 403, 'Hanya owner/superadmin.');

        $done = ['sold' => 0, 'service' => 0, 'commission' => 0, 'warranty' => 0];
        $failed = 0;
        $failMsgs = [];

        // Kumpulkan kandidat dengan kriteria identik seperti health() + fix satuan.
        $soldIds = collect();
        $serviceIds = collect();
        $commissionIds = collect();
        $warrantyIds = collect();

        try {
            if (\Schema::hasTable('product_items') && \Schema::hasTable('sale_items') && \Schema::hasTable('sales')) {
                $q = \DB::table('product_items')
                    ->join('sale_items', 'sale_items.product_item_id', '=', 'product_items.id')
                    ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                    ->where('product_items.status', 'tersedia')
                    ->select('product_items.id as id');
                if (\Schema::hasTable('sale_returns')) {
                    $q->leftJoin('sale_returns', 'sale_returns.sale_id', '=', 'sales.id')
                        ->whereNull('sale_returns.id');
                }
                $soldIds = $q->orderByDesc('sales.id')->limit(100)->pluck('id');
            }
        } catch (\Throwable $e) { $failMsgs[] = 'Kumpul unit terjual gagal: ' . $e->getMessage(); }

        try {
            if (\Schema::hasTable('product_items') && \Schema::hasTable('service_tickets')) {
                $serviceIds = \DB::table('product_items as pi')
                    ->where('pi.status', 'service')
                    ->whereNotNull('pi.imei')->where('pi.imei', '!=', '')
                    ->whereNotExists(function ($q) {
                        $q->select(\DB::raw(1))->from('service_tickets as st')
                            ->whereRaw('st.imei = pi.imei')
                            ->whereNotIn('st.status', ['diserahkan', 'batal']);
                    })
                    ->orderByDesc('pi.id')->limit(100)->pluck('pi.id');
            }
        } catch (\Throwable $e) { $failMsgs[] = 'Kumpul unit yatim gagal: ' . $e->getMessage(); }

        try {
            if (\Schema::hasTable('technician_commissions') && \Schema::hasTable('service_tickets')) {
                $commissionIds = \DB::table('technician_commissions')
                    ->join('service_tickets', 'service_tickets.id', '=', 'technician_commissions.service_ticket_id')
                    ->where('service_tickets.status', 'batal')
                    ->where('technician_commissions.status', 'menunggu')
                    ->orderByDesc('technician_commissions.id')->limit(100)->pluck('technician_commissions.id');
            }
        } catch (\Throwable $e) { $failMsgs[] = 'Kumpul komisi gagal: ' . $e->getMessage(); }

        try {
            if (\Schema::hasTable('warranties')) {
                $warrantyIds = \DB::table('warranties')
                    ->where('warranties.status', 'aktif')
                    ->whereNotNull('warranties.end_date')
                    ->whereDate('warranties.end_date', '<', now()->toDateString())
                    ->orderBy('warranties.end_date')->limit(100)->pluck('warranties.id');
            }
        } catch (\Throwable $e) { $failMsgs[] = 'Kumpul garansi gagal: ' . $e->getMessage(); }

        $fail = function (string $label, $id, \Throwable $e) use (&$failed, &$failMsgs) {
            $failed++;
            $msg = $e->getMessage() ?: 'gagal tanpa pesan';
            $failMsgs[] = $label . ' #' . $id . ': ' . mb_substr($msg, 0, 160);
        };

        foreach ($soldIds as $id) {
            try {
                $unit = \App\Models\ProductItem::findOrFail($id);
                $this->applyFixUnitSold($unit);
                $done['sold']++;
            } catch (\Throwable $e) { $fail('Unit terjual', $id, $e); }
        }

        foreach ($serviceIds as $id) {
            try {
                $unit = \App\Models\ProductItem::findOrFail($id);
                $this->applyFixServiceUnit($unit);
                $done['service']++;
            } catch (\Throwable $e) { $fail('Unit yatim', $id, $e); }
        }

        foreach ($commissionIds as $id) {
            try {
                $c = \App\Models\TechnicianCommission::findOrFail($id);
                $this->applyFixCommission($c);
                $done['commission']++;
            } catch (\Throwable $e) { $fail('Komisi', $id, $e); }
        }

        foreach ($warrantyIds as $id) {
            try {
                $w = \App\Models\Warranty::findOrFail($id);
                $this->applyFixWarranty($w);
                $done['warranty']++;
            } catch (\Throwable $e) { $fail('Garansi', $id, $e); }
        }

        $total = array_sum($done);
        $summary = "Perbaiki Semua selesai: {$done['sold']} unit terjual→terjual, "
            . "{$done['service']} unit yatim→tersedia, "
            . "{$done['commission']} komisi batal dihapus, "
            . "{$done['warranty']} garansi kedaluwarsa ditutup. "
            . "Gagal dilewati: {$failed}.";

        $resp = back()->with('success', $summary);
        if ($failMsgs) {
            $resp->with('warning', 'Rincian yang dilewati (maks 10): ' . implode(' | ', array_slice($failMsgs, 0, 10)));
        }
        return $resp;
    }

    /** Helper inti per item — dipakai fix satuan & fixAllSafe. */
    protected function applyFixUnitSold(\App\Models\ProductItem $unit): void
    {
        abort_unless($unit->status === 'tersedia', 422, 'Status unit sudah bukan tersedia.');
        $saleId = \DB::table('sale_items')->where('product_item_id', $unit->id)->value('sale_id');
        abort_unless($saleId, 422, 'Unit tidak ditemukan di data penjualan.');
        if (\Schema::hasTable('sale_returns') && \DB::table('sale_returns')->where('sale_id', $saleId)->exists()) {
            abort(422, 'Penjualan unit ini memiliki retur — periksa manual.');
        }
        $unit->update(['status' => 'terjual']);
    }

    /** Helper inti per item — dipakai fix satuan & fixAllSafe. */
    protected function applyFixServiceUnit(\App\Models\ProductItem $unit): void
    {
        abort_unless($unit->status === 'service', 422, 'Status unit sudah bukan service.');
        abort_if(empty($unit->imei), 422, 'Unit tanpa IMEI — periksa manual.');
        $active = \DB::table('service_tickets')
            ->where('imei', $unit->imei)
            ->whereNotIn('status', ['diserahkan', 'batal'])
            ->exists();
        abort_if($active, 422, 'Masih ada tiket service aktif untuk IMEI ini.');
        $unit->update(['status' => 'tersedia']);
    }

    /** Helper inti per item — dipakai fix satuan & fixAllSafe. */
    protected function applyFixCommission(\App\Models\TechnicianCommission $c): void
    {
        abort_unless($c->status === 'menunggu', 422, 'Hanya komisi menunggu yang boleh dihapus.');
        $ticketStatus = \DB::table('service_tickets')->where('id', $c->service_ticket_id)->value('status');
        abort_unless($ticketStatus === 'batal', 422, 'Tiket tidak dalam status batal.');
        $c->delete();
    }

    /** Helper inti per item — dipakai fix satuan & fixAllSafe. */
    protected function applyFixWarranty(\App\Models\Warranty $w): void
    {
        abort_unless($w->status === 'aktif', 422, 'Status garansi sudah bukan aktif.');
        abort_unless($w->end_date && $w->end_date->toDateString() < now()->toDateString(), 422, 'Garansi belum kedaluwarsa.');
        $marker = '[KEDALUWARSA otomatis]';
        $note = $w->claim_note ?: '';
        if (!str_contains($note, $marker)) {
            $note = trim($note . ' ' . $marker);
        }
        $w->update(['status' => 'selesai', 'claim_note' => $note ?: null]);
    }

    public function setup()
    {
        abort_unless(in_array(auth()->user()->role ?? '', ['superadmin', 'owner']), 403, 'Hanya owner/superadmin.');

        $steps = [];
        $add = function (string $key, string $label, string $desc, bool $done, string $url, string $cta) use (&$steps) {
            $steps[] = compact('key', 'label', 'desc', 'done', 'url', 'cta');
        };

        // 1. Toko aktif
        try {
            $activeStores = \Schema::hasTable('stores')
                ? (int) \DB::table('stores')->where('is_active', true)->count()
                : 0;
        } catch (\Throwable $e) { $activeStores = 0; }
        $add('toko', 'Toko aktif', $activeStores > 0 ? "$activeStores toko aktif." : 'Belum ada toko aktif. Tambahkan/aktifkan minimal 1 toko.', $activeStores > 0, route('stores.index'), 'Kelola toko');

        // 2. Produk > 0
        try {
            $productCount = \Schema::hasTable('products') ? (int) \DB::table('products')->count() : 0;
        } catch (\Throwable $e) { $productCount = 0; }
        $add('produk', 'Produk', $productCount > 0 ? "$productCount produk terdaftar." : 'Belum ada produk. Tambahkan produk jualan/service dulu.', $productCount > 0, route('products.index'), 'Kelola produk');

        // 3. Stok > 0
        try {
            $stockQty = (\Schema::hasTable('product_stocks')) ? (float) \DB::table('product_stocks')->sum('qty') : 0;
        } catch (\Throwable $e) { $stockQty = 0; }
        $add('stok', 'Stok awal', $stockQty > 0 ? 'Total ' . number_format($stockQty, 0, ',', '.') . ' pcs tercatat.' : 'Stok masih kosong. Isi stok awal via mutasi/opname.', $stockQty > 0, route('stocks.mutations'), 'Isi stok');

        // 4. Akun kas
        try {
            $cashCount = \Schema::hasTable('cash_accounts') ? (int) \DB::table('cash_accounts')->count() : 0;
        } catch (\Throwable $e) { $cashCount = 0; }
        $add('kas', 'Akun kas', $cashCount > 0 ? "$cashCount akun kas tersedia." : 'Belum ada akun kas (tunai/bank). Buat minimal 1 akun.', $cashCount > 0, route('cash.index'), 'Kelola kas');

        // 5. User kasir + teknisi
        try {
            $kasir = \Schema::hasTable('users') ? (int) \DB::table('users')->where('role', 'kasir')->count() : 0;
        } catch (\Throwable $e) { $kasir = 0; }
        try {
            $teknisi = \Schema::hasTable('users') ? (int) \DB::table('users')->where('role', 'teknisi')->count() : 0;
        } catch (\Throwable $e) { $teknisi = 0; }
        $userOk = $kasir > 0 && $teknisi > 0;
        $add(
            'users',
            'User kasir + teknisi',
            $userOk
                ? "Kasir: $kasir, Teknisi: $teknisi."
                : 'Butuh minimal 1 kasir dan 1 teknisi. (Kasir: ' . $kasir . ', Teknisi: ' . $teknisi . ')',
            $userOk,
            route('users.index'),
            'Kelola user'
        );

        // 6. Gateway WA
        try {
            $waCfg = \App\Http\Controllers\SettingController::cfg();
            $waOk = !empty($waCfg['WA_GATEWAY_URL']);
        } catch (\Throwable $e) { $waOk = false; $waCfg = []; }
        $add('wa', 'Gateway WA', !empty($waOk) ? 'URL gateway terisi (' . ($waCfg['WA_SENDER'] ?? 'ServiceHP') . ').' : 'Gateway WA belum dikonfigurasi. Isi URL gateway agar notif berjalan.', !empty($waOk), route('settings.wa'), 'Setting WA');

        // 7. Printer
        try {
            $printerCount = count(\App\Services\EscPosService::all());
        } catch (\Throwable $e) { $printerCount = 0; }
        $add('printer', 'Printer', $printerCount > 0 ? "$printerCount printer terdaftar." : 'Belum ada printer. Tambahkan printer LAN/WiFi atau gunakan cetak browser.', $printerCount > 0, route('settings.printers'), 'Setting printer');

        // 8. Logo / branding struk
        $brandOk = false;
        $brandNote = 'Belum ada branding (logo/header/footer). Isi di halaman printer per toko.';
        try {
            $stores = \Schema::hasTable('stores') ? \DB::table('stores')->select('id', 'name')->orderBy('id')->limit(50)->get() : collect();
            foreach ($stores as $s) {
                $b = \App\Services\EscPosService::branding((int) $s->id);
                if (!empty($b['logo']) || trim((string) ($b['header'] ?? '')) !== '' || trim((string) ($b['footer'] ?? '')) !== '') {
                    $brandOk = true;
                    $brandNote = 'Branding terisi untuk toko ' . ($s->name ?? ('#' . $s->id)) . '.';
                    break;
                }
            }
            if (!$brandOk && $stores->isEmpty()) {
                $brandNote = 'Belum ada toko, branding belum bisa diisi.';
            }
        } catch (\Throwable $e) { $brandOk = false; }
        $add('branding', 'Logo / branding struk', $brandNote, $brandOk, route('settings.printers'), 'Isi branding');

        // 9. Promo pertama
        try {
            $promoCount = \Schema::hasTable('promos') ? (int) \DB::table('promos')->count() : 0;
        } catch (\Throwable $e) { $promoCount = 0; }
        $add('promo', 'Promo pertama', $promoCount > 0 ? "$promoCount promo dibuat." : 'Belum ada promo. Buat promo perdana untuk memancing pembeli.', $promoCount > 0, route('promos.index'), 'Buat promo');

        $done = collect($steps)->where('done', true)->count();
        $total = count($steps);
        $pct = $total > 0 ? round($done / $total * 100) : 0;

        return view('dashboard.setup', compact('steps', 'done', 'total', 'pct'));
    }

    /**
     * Badge notifikasi live (ringan, tanpa migration).
     * GET /notifications/count — auth; scope toko aktif.
     * Menghitung: tiket siap_diambil + tiket overdue aktif
     * (>3 hari belum diserahkan/batal) + booking menunggu.
     * Tiap query dibungkus try/catch sendiri agar tetap 200
     * walau salah satu tabel bermasalah.
     */
    public function notificationCount(Request $request)
    {
        $storeId = session('active_store_id');

        $siapDiambil = 0;
        $overdue = 0;
        $bookingMenunggu = 0;

        try {
            if (\Schema::hasTable('service_tickets')) {
                $siapDiambil = (int) DB::table('service_tickets')
                    ->when($storeId, fn($q) => $q->where('store_id', $storeId))
                    ->where('status', 'siap_diambil')
                    ->count();
            }
        } catch (\Throwable $e) {
            $siapDiambil = 0;
        }

        try {
            if (\Schema::hasTable('service_tickets')) {
                $overdue = (int) DB::table('service_tickets')
                    ->when($storeId, fn($q) => $q->where('store_id', $storeId))
                    ->whereNotIn('status', ['diserahkan', 'batal'])
                    ->where('created_at', '<=', now()->subDays(3))
                    ->count();
            }
        } catch (\Throwable $e) {
            $overdue = 0;
        }

        try {
            if (\Schema::hasTable('bookings')) {
                $bookingMenunggu = (int) DB::table('bookings')
                    ->when($storeId, fn($q) => $q->where('store_id', $storeId))
                    ->where('status', 'menunggu')
                    ->count();
            }
        } catch (\Throwable $e) {
            $bookingMenunggu = 0;
        }

        return response()->json([
            'siap_diambil' => $siapDiambil,
            'overdue' => $overdue,
            'booking_menunggu' => $bookingMenunggu,
            'total' => $siapDiambil + $overdue + $bookingMenunggu,
            'store_id' => $storeId,
        ]);
    }

    public function switchStore(Request $request, $id)
    {
        $user = $request->user();
        $id = (int) $id;
        abort_unless(\App\Models\Store::whereKey($id)->exists(), 404, 'Toko tidak ditemukan.');
        // superadmin/owner boleh ke toko mana saja, lainnya hanya toko yang di-assign
        $allowed = in_array($user->role ?? '', ['superadmin', 'owner'])
            || $user->stores()->where('stores.id', $id)->exists()
            || \App\Models\Store::where('id', $id)->exists() && $user->stores()->count() === 0;
        if (!$allowed) abort(403, 'Tidak punya akses ke toko ini.');
        session(['active_store_id' => (int) $id]);
        try { $user->update(['active_store_id' => (int) $id]); } catch (\Throwable $e) {}
        return back()->with('success', 'Toko aktif diganti.');
    }
}
