<?php

namespace App\Http\Controllers;

use App\Models\Debt;
use App\Models\Expense;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\ServiceSparepart;
use App\Models\ServiceTicket;
use App\Models\Store;
use App\Models\TechnicianCommission;
use App\Support\LookupCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    /**
     * Koreksi profit akibat retur/refund/void pada rentang tanggal (HANYA matematika
     * laporan — tidak mengubah data sale_items).
     * @return array{0:float, 1:float, 2:array<int,array{profit:float, qty:int, omzet:float}>}
     *   [totalNominalRetur, totalReversalProfit, perProduct[product_id => [profit, qty, omzet]].
     * - Retur per item: reversal pro-rata terhadap subtotal item (amount/subtotal, maks 1x).
     * - Retur penuh/void (tanpa sale_item_id): seluruh profit item sale terkait.
     * - Kunci 'omzet' = porsi subtotal yang dibatalkan (untuk agregat omzet bersih per kategori).
     */
    protected function returAdjustment(int $storeId, string $start, string $end): array
    {
        $amount = 0.0;
        $profit = 0.0;
        $perProduct = [];
        try {
            $rows = SaleReturn::with(['saleItem', 'sale.items'])
                ->where('store_id', $storeId)
                ->whereBetween(DB::raw('DATE(created_at)'), [$start, $end])
                ->get();
        } catch (\Throwable $e) {
            report($e);
            return [$amount, $profit, $perProduct];
        }
        foreach ($rows as $r) {
            $amount += (float) $r->amount;
            $targets = [];
            if ($r->sale_item_id && $r->saleItem) {
                $targets[] = $r->saleItem;
            } elseif ($r->sale) {
                foreach ($r->sale->items as $it) $targets[] = $it;
            }
            foreach ($targets as $it) {
                $sub = (float) ($it->subtotal ?? 0);
                $ratio = ($r->sale_item_id && $sub > 0) ? min(1, (float) $r->amount / $sub) : 1;
                $rev = (float) ($it->profit ?? 0) * $ratio;
                $qrev = (int) round((int) ($it->qty ?? 0) * $ratio);
                $orev = (float) ($it->subtotal ?? 0) * $ratio;
                $profit += $rev;
                $pid = (int) $it->product_id;
                $perProduct[$pid]['profit'] = ($perProduct[$pid]['profit'] ?? 0.0) + $rev;
                $perProduct[$pid]['qty'] = ($perProduct[$pid]['qty'] ?? 0) + $qrev;
                $perProduct[$pid]['omzet'] = ($perProduct[$pid]['omzet'] ?? 0.0) + $orev;
            }
        }
        return [$amount, $profit, $perProduct];
    }

    /**
     * Agregat satu bulan kalender (scope toko, laporan saja).
     * @return array{0:float, 1:float, 2:float} [omzetPenjualan, pendapatanService, labaBersih].
     * Rumus laba identik dengan blok MoM: profit jual bersih + jasa + profit sparepart
     * dikurangi pembelian, operasional, dan komisi teknisi.
     */
    protected function monthLaba(int $storeId, string $yyyymm): array
    {
        $mStart = $yyyymm . '-01';
        $mEnd = date('Y-m-t', strtotime($mStart));
        $omzet = (float) Sale::where('store_id', $storeId)
            ->whereBetween(DB::raw('DATE(created_at)'), [$mStart, $mEnd])->sum('total');
        $service = (float) ServiceTicket::where('store_id', $storeId)
            ->whereBetween(DB::raw('DATE(created_at)'), [$mStart, $mEnd])->sum('total_cost');
        $saleIds = Sale::where('store_id', $storeId)
            ->whereBetween(DB::raw('DATE(created_at)'), [$mStart, $mEnd])->pluck('id');
        $profit = $saleIds->isNotEmpty()
            ? (float) SaleItem::whereIn('sale_id', $saleIds)->sum('profit') : 0.0;
        [, $returProfit] = $this->returAdjustment($storeId, $mStart, $mEnd);
        $profitNet = $profit - (float) $returProfit;
        $ticketIds = ServiceTicket::where('store_id', $storeId)
            ->whereBetween(DB::raw('DATE(created_at)'), [$mStart, $mEnd])->pluck('id');
        $spOmzet = 0.0;
        $spHpp = 0.0;
        if ($ticketIds->isNotEmpty()) {
            $rows = ServiceSparepart::whereIn('service_ticket_id', $ticketIds)->with('product')->get();
            foreach ($rows as $r) {
                $spOmzet += (float) $r->subtotal;
                $spHpp += (float) ($r->product?->purchase_price ?? 0) * (int) $r->qty;
            }
        }
        $spProfit = $spOmzet - $spHpp;
        $pembelian = (float) Purchase::where('store_id', $storeId)
            ->whereBetween(DB::raw('DATE(created_at)'), [$mStart, $mEnd])->sum('total');
        $operasional = (float) Expense::where('store_id', $storeId)
            ->whereBetween(DB::raw('DATE(expense_date)'), [$mStart, $mEnd])->sum('amount');
        $komisi = (float) TechnicianCommission::whereIn(
            'service_ticket_id',
            $ticketIds->isNotEmpty() ? $ticketIds : [0]
        )->sum('amount');
        $labaKotor = $profitNet + ($service - $spOmzet) + $spProfit;
        $laba = $labaKotor - $pembelian - $operasional - $komisi;
        return [$omzet, $service, $laba];
    }

    public function profit(Request $request)
    {
        $request->validate([
            'store_id' => 'nullable|exists:stores,id',
            'from' => 'nullable|date_format:Y-m',
            'to' => 'nullable|date_format:Y-m',
        ]);

        $storeId = $request->store_id ?? session('active_store_id') ?? Store::where('is_active', true)->value('id');
        $from = $request->from ?? now()->format('Y-m');
        $to = $request->to ?? now()->format('Y-m');
        [$fy, $fm] = explode('-', $from);
        [$ty, $tm] = explode('-', $to);
        $start = sprintf('%04d-%02d-01', $fy, $fm);
        $end = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $ty, $tm)));

        $inRange = fn ($q) => $q->whereBetween(DB::raw('DATE(created_at)'), [$start, $end]);

        // Pendapatan penjualan (omzet) + profit via sale_items
        $salesQuery = Sale::where('store_id', $storeId)->whereBetween(DB::raw('DATE(created_at)'), [$start, $end]);
        $omzetPenjualan = (clone $salesQuery)->sum('total');
        $saleIds = (clone $salesQuery)->pluck('id');
        $profitPenjualan = $saleIds->isNotEmpty()
            ? SaleItem::whereIn('sale_id', $saleIds)->sum('profit') : 0;

        // Pengurang retur: profit yang dilaporkan dikurangi reversal retur periode ini
        // (HANYA matematika laporan — data sale_items tidak diubah).
        [$returAmount, $returProfit, $returByProduct] = $this->returAdjustment((int) $storeId, $start, $end);
        $profitPenjualanNet = (float) $profitPenjualan - (float) $returProfit;

        // Total diskon periode (laporan saja): diskon per item (sale_items.discount)
        // + diskon header (sales.discount = manual + promo). Keduanya kolom terpisah,
        // tidak tumpang tindih — dijumlahkan agar total diskon periode utuh.
        $diskonHeader = (float) (clone $salesQuery)->sum('discount');
        $diskonItem = $saleIds->isNotEmpty()
            ? (float) SaleItem::whereIn('sale_id', $saleIds)->sum('discount')
            : 0.0;
        $totalDiskon = $diskonHeader + $diskonItem;

        // Pendapatan service
        $serviceQuery = ServiceTicket::where('store_id', $storeId)->whereBetween(DB::raw('DATE(created_at)'), [$start, $end]);
        $pendapatanService = (clone $serviceQuery)->sum('total_cost');

        // Keuntungan sparepart service
        $ticketIds = (clone $serviceQuery)->pluck('id');
        $sparepartOmzet = 0;
        $sparepartHpp = 0;
        if ($ticketIds->isNotEmpty()) {
            $rows = ServiceSparepart::whereIn('service_ticket_id', $ticketIds)->with('product')->get();
            foreach ($rows as $r) {
                $sparepartOmzet += (float) $r->subtotal;
                $sparepartHpp += (float) ($r->product?->purchase_price ?? 0) * (int) $r->qty;
            }
        }
        $profitSparepart = $sparepartOmzet - $sparepartHpp;

        // HPP / pembelian
        $pembelian = Purchase::where('store_id', $storeId)->whereBetween(DB::raw('DATE(created_at)'), [$start, $end])->sum('total');

        // Operasional
        $operasional = Expense::where('store_id', $storeId)->whereBetween(DB::raw('DATE(expense_date)'), [$start, $end])->sum('amount');

        // Gaji / komisi teknisi
        $komisi = TechnicianCommission::whereIn('service_ticket_id', $ticketIds->isNotEmpty() ? $ticketIds : [0])->sum('amount');

        // Hutang & piutang outstanding (sisa, semua waktu, toko ini)
        $hutangOutstanding = Debt::where('store_id', $storeId)->where('type', 'hutang')->where('status', '!=', 'lunas')->sum('remaining');
        $piutangOutstanding = Debt::where('store_id', $storeId)->where('type', 'piutang')->where('status', '!=', 'lunas')->sum('remaining');

        // Laba kotor: profit jual bersih + jasa service + profit sparepart.
        // total_cost sudah mencakup omzet sparepart, jadi kurangi dulu agar omzet sparepart tidak terhitung ganda.
        $labaKotor = $profitPenjualanNet + ($pendapatanService - $sparepartOmzet) + $profitSparepart;
        $labaBersih = $labaKotor - $pembelian - $operasional - $komisi;

        // --- Modal tertanam persediaan vs hasil periode (kartu ROI, laporan saja) ---
        // Modal = stok toko (qty x harga beli produk) + unit IMEI tersedia
        // (harga beli unit bila > 0, fallback harga beli produk). Scope toko aktif.
        $modalStok = 0.0; $modalUnit = 0.0; $modalTertanam = 0.0;
        try {
            $modalStok = (float) DB::table('product_stocks')
                ->join('products', 'products.id', '=', 'product_stocks.product_id')
                ->where('product_stocks.store_id', $storeId)
                ->selectRaw('COALESCE(SUM(product_stocks.qty * COALESCE(products.purchase_price, 0)), 0) as v')
                ->value('v');
            $modalUnit = (float) DB::table('product_items')
                ->leftJoin('products', 'products.id', '=', 'product_items.product_id')
                ->where('product_items.store_id', $storeId)
                ->where('product_items.status', 'tersedia')
                ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(product_items.purchase_price, 0) > 0 THEN product_items.purchase_price ELSE COALESCE(products.purchase_price, 0) END), 0) as v')
                ->value('v');
            $modalTertanam = (float) $modalStok + (float) $modalUnit;
        } catch (\Throwable $e) {
            report($e);
        }
        // "Rp X profit per Rp 1.000 modal" + persen ROI.
        $roiPerSeribu = $modalTertanam > 0 ? (float) $labaBersih / (float) $modalTertanam * 1000 : 0;
        $roiPersen = $modalTertanam > 0 ? (float) $labaBersih / (float) $modalTertanam * 100 : 0;

        // --- Neraca sederhana (posisi kini, laporan saja, scope toko aktif) ---
        // ASET = kas total + modal persediaan (stok + unit) + piutang outstanding.
        // KEWAJIBAN = hutang outstanding.
        // MODAL = plug (aset - kewajiban), BUKAN akuntansi double-entry.
        $neracaTanggal = now()->toDateString();
        $neracaKas = 0.0;
        $neracaAset = 0.0;
        $neracaKewajiban = 0.0;
        $neracaModalPlug = 0.0;
        try {
            $neracaKas = (float) DB::table('cash_accounts')
                ->where('store_id', $storeId)
                ->sum('balance');
            $neracaAset = $neracaKas + (float) $modalTertanam + (float) $piutangOutstanding;
            $neracaKewajiban = (float) $hutangOutstanding;
            $neracaModalPlug = $neracaAset - $neracaKewajiban;
        } catch (\Throwable $e) {
            report($e);
        }

        // --- Data grafik (dibungkus try/catch agar laporan tetap tampil bila agregasi gagal) ---
        $trendLabels = []; $trendOmzet = []; $trendProfit = [];
        $donutPenjualan = (float) $omzetPenjualan;
        $donutService = (float) $pendapatanService;
        $donutSparepart = (float) $sparepartOmzet;
        $topProducts = collect();
        $profitByCategory = collect();
        $topCashiers = collect();
        $expenseByCat = collect();
        try {
            for ($i = 11; $i >= 0; $i--) {
                $wStart = now()->subWeeks($i)->startOfWeek()->toDateString();
                $wEnd = now()->subWeeks($i)->endOfWeek()->toDateString();
                $wOmzet = (float) Sale::where('store_id', $storeId)
                    ->whereBetween(DB::raw('DATE(created_at)'), [$wStart, $wEnd])->sum('total')
                    + (float) ServiceTicket::where('store_id', $storeId)
                    ->whereBetween(DB::raw('DATE(created_at)'), [$wStart, $wEnd])->sum('total_cost');
                $wSaleIds = Sale::where('store_id', $storeId)
                    ->whereBetween(DB::raw('DATE(created_at)'), [$wStart, $wEnd])->pluck('id');
                $wProfit = $wSaleIds->isNotEmpty()
                    ? (float) SaleItem::whereIn('sale_id', $wSaleIds)->sum('profit') : 0;
                // Kurangi reversal retur minggu berjalan (laporan saja).
                [, $wReturProfit] = $this->returAdjustment((int) $storeId, $wStart, $wEnd);
                $wProfit -= (float) $wReturProfit;
                $wTicketIds = ServiceTicket::where('store_id', $storeId)
                    ->whereBetween(DB::raw('DATE(created_at)'), [$wStart, $wEnd])->pluck('id');
                if ($wTicketIds->isNotEmpty()) {
                    $rows = ServiceSparepart::whereIn('service_ticket_id', $wTicketIds)->with('product')->get();
                    foreach ($rows as $r) {
                        $wProfit += (float) $r->subtotal - (float) ($r->product?->purchase_price ?? 0) * (int) $r->qty;
                    }
                }
                $trendLabels[] = date('d/m', strtotime($wStart));
                $trendOmzet[] = $wOmzet;
                $trendProfit[] = $wProfit;
            }
            $topProducts = SaleItem::with('product')->select('sale_items.product_id', DB::raw('SUM(sale_items.profit) as total_profit'), DB::raw('SUM(sale_items.qty) as total_qty'))
                ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
                ->where('sales.store_id', $storeId)
                ->whereBetween(DB::raw('DATE(sales.created_at)'), [$start, $end])
                ->groupBy('sale_items.product_id')
                ->orderByDesc('total_profit')
                ->limit(10)->get()
                ->map(function ($r) use ($returByProduct) {
                    $adj = $returByProduct[(int) $r->product_id] ?? ['profit' => 0.0, 'qty' => 0];
                    return [
                        'name' => $r->product->name ?? ('Produk #' . $r->product_id),
                        'qty' => max(0, (int) $r->total_qty - (int) ($adj['qty'] ?? 0)),
                        'profit' => (float) $r->total_profit - (float) ($adj['profit'] ?? 0),
                    ];
                });
            $topCashiers = Sale::select('sales.user_id', DB::raw('COALESCE(users.name, CONCAT("Kasir #", sales.user_id)) as name'), DB::raw('SUM(sales.total) as omzet'), DB::raw('COUNT(sales.id) as transaksi'))
                ->leftJoin('users', 'users.id', '=', 'sales.user_id')
                ->where('sales.store_id', $storeId)
                ->whereBetween(DB::raw('DATE(sales.created_at)'), [$start, $end])
                ->groupBy('sales.user_id')
                ->orderByDesc('omzet')
                ->limit(10)->get()
                ->map(fn ($r) => [
                    'name' => $r->name,
                    'omzet' => (float) $r->omzet,
                    'transaksi' => (int) $r->transaksi,
                ]);
            $expenseByCat = Expense::select('category', DB::raw('SUM(amount) as total'))
                ->where('store_id', $storeId)
                ->whereBetween(DB::raw('DATE(expense_date)'), [$start, $end])
                ->groupBy('category')->orderByDesc('total')->get();
            // --- Profit per kategori produk (omzet + profit + margin %, bersih setelah retur) ---
            // Agregat per produk dulu agar koreksi retur per produk ($returByProduct)
            // bisa dikurangkan tepat ke kategorinya, lalu dikelompokkan per kategori di PHP.
            $prodRows = SaleItem::select(
                    'sale_items.product_id',
                    DB::raw("COALESCE(categories.name, 'Tanpa Kategori') as cat_name"),
                    DB::raw('SUM(sale_items.profit) as cat_profit'),
                    DB::raw('SUM(sale_items.subtotal) as cat_omzet'),
                    DB::raw('SUM(sale_items.qty) as cat_qty')
                )
                ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
                ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
                ->where('sales.store_id', $storeId)
                ->whereBetween(DB::raw('DATE(sales.created_at)'), [$start, $end])
                ->groupBy('sale_items.product_id', 'categories.name')
                ->get();
            $profitByCategory = $prodRows->groupBy('cat_name')->map(function ($g, $cat) use ($returByProduct) {
                $omzet = (float) $g->sum('cat_omzet');
                $profit = (float) $g->sum('cat_profit');
                $qty = (int) $g->sum('cat_qty');
                $rProfit = 0.0; $rOmzet = 0.0; $rQty = 0;
                foreach ($g as $row) {
                    $adj = $returByProduct[(int) $row->product_id] ?? null;
                    if ($adj) {
                        $rProfit += (float) ($adj['profit'] ?? 0);
                        $rOmzet += (float) ($adj['omzet'] ?? 0);
                        $rQty += (int) ($adj['qty'] ?? 0);
                    }
                }
                $omzetNet = $omzet - $rOmzet;
                $profitNet = $profit - $rProfit;
                return [
                    'category' => $cat ?: 'Tanpa Kategori',
                    'qty' => max(0, $qty - $rQty),
                    'omzet' => max(0.0, $omzetNet),
                    'profit' => $profitNet,
                    'margin' => $omzetNet > 0 ? $profitNet / $omzetNet * 100 : 0.0,
                ];
            })->sortByDesc('profit')->values();
        } catch (\Throwable $e) {
            report($e);
        }

        // --- Sering Dibeli Bersamaan: top 10 pasangan produk dalam 1 invoice (90 hari, scope toko) ---
        // Rumus: untuk tiap invoice ambil daftar product_id unik, bentuk semua pasangan
        // tak-berurutan (A,B), hitung frekuensi per pasangan; persen = frekuensi / total transaksi x 100.
        // Kombinasi dihitung di PHP (portabel MySQL/SQLite); invoice dengan > 20 item unik
        // dilewati agar pasangan tidak meledak (C(20,2) = 190 sudah cukup besar).
        $seringEnd = now()->toDateString();
        $seringStart = now()->subDays(90)->toDateString();
        $seringBersamaan = collect();
        $seringTotal = 0;
        try {
            $rows = SaleItem::select('sale_items.sale_id', 'sale_items.product_id', 'products.name as product_name')
                ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
                ->where('sales.store_id', $storeId)
                ->whereBetween(DB::raw('DATE(sales.created_at)'), [$seringStart, $seringEnd])
                ->orderBy('sale_items.sale_id')
                ->get();
            $perInvoice = $rows->groupBy('sale_id');
            $seringTotal = $perInvoice->count();
            $pairCounts = [];
            $pairNames = [];
            foreach ($perInvoice as $items) {
                $ids = $items->pluck('product_id')->unique()->values();
                if ($ids->count() < 2 || $ids->count() > 20) {
                    continue;
                }
                $sorted = $ids->sort()->values();
                for ($i = 0; $i < $sorted->count(); $i++) {
                    for ($j = $i + 1; $j < $sorted->count(); $j++) {
                        $key = $sorted[$i] . '|' . $sorted[$j];
                        $pairCounts[$key] = ($pairCounts[$key] ?? 0) + 1;
                        if (!isset($pairNames[$key])) {
                            $names = [];
                            foreach ([$sorted[$i], $sorted[$j]] as $pid) {
                                $nm = $items->firstWhere('product_id', $pid)->product_name
                                    ?? ('Produk #' . $pid);
                                $names[] = $nm;
                            }
                            $pairNames[$key] = $names;
                        }
                    }
                }
            }
            arsort($pairCounts);
            $seringBersamaan = collect(array_slice($pairCounts, 0, 10, true))->map(function ($freq, $key) use ($pairNames, $seringTotal) {
                [$a, $b] = $pairNames[$key] ?? [$key, ''];
                return [
                    'pasangan' => $a . ' + ' . $b,
                    'produk_a' => $a,
                    'produk_b' => $b,
                    'frekuensi' => (int) $freq,
                    'persen' => $seringTotal > 0 ? (float) $freq / (float) $seringTotal * 100 : 0.0,
                ];
            })->values();
        } catch (\Throwable $e) {
            report($e);
        }
        // Agregasi di PHP agar portabel MySQL/SQLite (tanpa HOUR()/strftime di SQL).
        $hourLabels = [];
        $hourCounts = array_fill(0, 24, 0);
        $busyHour = null;
        $busyHourCount = 0;
        $staffTip = 'Belum ada transaksi 30 hari terakhir.';
        $quietHours = [];
        $quietTip = 'Belum ada data jam sepi.';
        for ($h = 0; $h < 24; $h++) {
            $hourLabels[] = sprintf('%02d:00', $h);
        }
        try {
            $hStart = now()->subDays(30)->toDateString();
            $hEnd = now()->toDateString();
            $times = Sale::where('store_id', $storeId)
                ->whereBetween(DB::raw('DATE(created_at)'), [$hStart, $hEnd])
                ->pluck('created_at');
            foreach ($times as $t) {
                $h = (int) date('G', strtotime((string) $t));
                if ($h >= 0 && $h <= 23) {
                    $hourCounts[$h]++;
                }
            }
            $max = max($hourCounts);
            if ($max > 0) {
                $busyHour = array_search($max, $hourCounts);
                $busyHourCount = $max;
                $staffTip = 'Jam tersibuk pukul '
                    . sprintf('%02d:00', $busyHour)
                    . ' (' . $busyHourCount . ' transaksi 30 hari) — siagakan kasir penuh 1 jam sebelum & sesudahnya.';
                // --- Jam sepi: 3 jam dengan transaksi tersedikit (untuk opname/bersih-bersih) ---
                $sorted = $hourCounts;
                asort($sorted);
                $quietHours = array_slice(array_keys($sorted), 0, 3);
                sort($quietHours);
                $labels = array_map(fn ($h) => sprintf('%02d:00', $h), $quietHours);
                $quietTip = 'Jam sepi pukul ' . implode(', ', $labels)
                    . ' — manfaatkan untuk opname stok & bersih-bersih.';
            }
        } catch (\Throwable $e) {
            report($e);
        }

        // --- MoM: bulan acuan ($to) vs bulan kalender sebelumnya (scope toko, laporan saja) ---
        // $to default = bulan berjalan, sehingga kartu = bulan berjalan vs bulan lalu.
        $momPrevMonth = date('Y-m', strtotime(substr($to, 0, 7) . '-01 -1 month'));
        $momPrevStart = $momPrevMonth . '-01';
        $momPrevEnd = date('Y-m-t', strtotime($momPrevStart));
        $momCurMonth = substr($to, 0, 7);
        $momPrevOmzet = 0.0; $momPrevService = 0.0; $momPrevLaba = 0.0;
        try {
            $momPrevOmzet = (float) Sale::where('store_id', $storeId)
                ->whereBetween(DB::raw('DATE(created_at)'), [$momPrevStart, $momPrevEnd])->sum('total');
            $momPrevService = (float) ServiceTicket::where('store_id', $storeId)
                ->whereBetween(DB::raw('DATE(created_at)'), [$momPrevStart, $momPrevEnd])->sum('total_cost');
            $momPrevSaleIds = Sale::where('store_id', $storeId)
                ->whereBetween(DB::raw('DATE(created_at)'), [$momPrevStart, $momPrevEnd])->pluck('id');
            $momPrevProfit = $momPrevSaleIds->isNotEmpty()
                ? (float) SaleItem::whereIn('sale_id', $momPrevSaleIds)->sum('profit') : 0.0;
            [, $momPrevReturProfit] = $this->returAdjustment((int) $storeId, $momPrevStart, $momPrevEnd);
            $momPrevProfitNet = $momPrevProfit - (float) $momPrevReturProfit;
            $momPrevTicketIds = ServiceTicket::where('store_id', $storeId)
                ->whereBetween(DB::raw('DATE(created_at)'), [$momPrevStart, $momPrevEnd])->pluck('id');
            $momPrevSpOmzet = 0.0; $momPrevSpHpp = 0.0;
            if ($momPrevTicketIds->isNotEmpty()) {
                $momRows = ServiceSparepart::whereIn('service_ticket_id', $momPrevTicketIds)->with('product')->get();
                foreach ($momRows as $r) {
                    $momPrevSpOmzet += (float) $r->subtotal;
                    $momPrevSpHpp += (float) ($r->product?->purchase_price ?? 0) * (int) $r->qty;
                }
            }
            $momPrevSpProfit = $momPrevSpOmzet - $momPrevSpHpp;
            $momPrevPembelian = (float) Purchase::where('store_id', $storeId)
                ->whereBetween(DB::raw('DATE(created_at)'), [$momPrevStart, $momPrevEnd])->sum('total');
            $momPrevOperasional = (float) Expense::where('store_id', $storeId)
                ->whereBetween(DB::raw('DATE(expense_date)'), [$momPrevStart, $momPrevEnd])->sum('amount');
            $momPrevKomisi = (float) TechnicianCommission::whereIn(
                'service_ticket_id', $momPrevTicketIds->isNotEmpty() ? $momPrevTicketIds : [0]
            )->sum('amount');
            $momPrevLabaKotor = $momPrevProfitNet + ($momPrevService - $momPrevSpOmzet) + $momPrevSpProfit;
            $momPrevLaba = $momPrevLabaKotor - $momPrevPembelian - $momPrevOperasional - $momPrevKomisi;
        } catch (\Throwable $e) {
            report($e);
        }
        // Bulan acuan untuk pembanding: bila rentang = 1 bulan ($from == $to), pakai angka periode ini;
        // bila rentang multi-bulan, bandingkan ulang khusus bulan $to agar apel-ke-apel per bulan kalender.
        $momCurOmzet = (float) $omzetPenjualan;
        $momCurService = (float) $pendapatanService;
        $momCurLaba = (float) $labaBersih;
        if ($from !== $to) {
            try {
                $momCurStart = substr($to, 0, 7) . '-01';
                $momCurEnd = date('Y-m-t', strtotime($momCurStart));
                $momCurOmzet = (float) Sale::where('store_id', $storeId)
                    ->whereBetween(DB::raw('DATE(created_at)'), [$momCurStart, $momCurEnd])->sum('total');
                $momCurService = (float) ServiceTicket::where('store_id', $storeId)
                    ->whereBetween(DB::raw('DATE(created_at)'), [$momCurStart, $momCurEnd])->sum('total_cost');
                $momCurSaleIds = Sale::where('store_id', $storeId)
                    ->whereBetween(DB::raw('DATE(created_at)'), [$momCurStart, $momCurEnd])->pluck('id');
                $momCurProfit = $momCurSaleIds->isNotEmpty()
                    ? (float) SaleItem::whereIn('sale_id', $momCurSaleIds)->sum('profit') : 0.0;
                [, $momCurReturProfit] = $this->returAdjustment((int) $storeId, $momCurStart, $momCurEnd);
                $momCurProfitNet = $momCurProfit - (float) $momCurReturProfit;
                $momCurTicketIds = ServiceTicket::where('store_id', $storeId)
                    ->whereBetween(DB::raw('DATE(created_at)'), [$momCurStart, $momCurEnd])->pluck('id');
                $momCurSpOmzet = 0.0; $momCurSpHpp = 0.0;
                if ($momCurTicketIds->isNotEmpty()) {
                    $momCurRows = ServiceSparepart::whereIn('service_ticket_id', $momCurTicketIds)->with('product')->get();
                    foreach ($momCurRows as $r) {
                        $momCurSpOmzet += (float) $r->subtotal;
                        $momCurSpHpp += (float) ($r->product?->purchase_price ?? 0) * (int) $r->qty;
                    }
                }
                $momCurSpProfit = $momCurSpOmzet - $momCurSpHpp;
                $momCurPembelian = (float) Purchase::where('store_id', $storeId)
                    ->whereBetween(DB::raw('DATE(created_at)'), [$momCurStart, $momCurEnd])->sum('total');
                $momCurOperasional = (float) Expense::where('store_id', $storeId)
                    ->whereBetween(DB::raw('DATE(expense_date)'), [$momCurStart, $momCurEnd])->sum('amount');
                $momCurKomisi = (float) TechnicianCommission::whereIn(
                    'service_ticket_id', $momCurTicketIds->isNotEmpty() ? $momCurTicketIds : [0]
                )->sum('amount');
                $momCurLabaKotor = $momCurProfitNet + ($momCurService - $momCurSpOmzet) + $momCurSpProfit;
                $momCurLaba = $momCurLabaKotor - $momCurPembelian - $momCurOperasional - $momCurKomisi;
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $momPct = function ($cur, $prev) {
            $cur = (float) $cur; $prev = (float) $prev;
            if ($prev == 0.0) return $cur > 0 ? 100.0 : ($cur < 0 ? -100.0 : 0.0);
            return ($cur - $prev) / abs($prev) * 100;
        };
        $momOmzetPct = $momPct($momCurOmzet, $momPrevOmzet);
        $momServicePct = $momPct($momCurService, $momPrevService);
        $momLabaPct = $momPct($momCurLaba, $momPrevLaba);

        // --- YoY: bulan yang sama tahun lalu (scope toko, laporan saja) ---
        // Acuan bulan kalender $momCurMonth (YYYY-MM); pembanding = YYYY-1 dengan MM sama.
        $yoyMonth = ((int) substr($momCurMonth, 0, 4) - 1) . '-' . substr($momCurMonth, 5, 2);
        $yoyOmzet = 0.0;
        $yoyService = 0.0;
        $yoyLaba = 0.0;
        try {
            [$yoyOmzet, $yoyService, $yoyLaba] = $this->monthLaba((int) $storeId, $yoyMonth);
        } catch (\Throwable $e) {
            report($e);
        }
        $yoyOmzetPct = $momPct($momCurOmzet, $yoyOmzet);
        $yoyLabaPct = $momPct($momCurLaba, $yoyLaba);

        // --- Estimasi PPh Final UMKM 0,5% x omzet (laporan saja, bukan perhitungan pajak resmi) ---
        // Omzet mengikuti definisi existing: penjualan (sales.total) + service (service_tickets.total_cost).
        $pphTarif = 0.005;
        $omzetPeriode = (float) $omzetPenjualan + (float) $pendapatanService;
        $pphPeriode = $omzetPeriode * $pphTarif;
        $tahunBerjalan = substr($to, 0, 4);
        $yearStart = $tahunBerjalan . '-01-01';
        $yearEnd = $tahunBerjalan . '-12-31';
        $omzetTahunBerjalan = 0.0;
        $pphTahunBerjalan = 0.0;
        try {
            $omzetTahunBerjalan = (float) Sale::where('store_id', $storeId)
                ->whereBetween(DB::raw('DATE(created_at)'), [$yearStart, $yearEnd])->sum('total')
                + (float) ServiceTicket::where('store_id', $storeId)
                ->whereBetween(DB::raw('DATE(created_at)'), [$yearStart, $yearEnd])->sum('total_cost');
            $pphTahunBerjalan = $omzetTahunBerjalan * $pphTarif;
        } catch (\Throwable $e) {
            report($e);
        }

        // --- Tren tahunan: 12 bulan kalender tahun berjalan (scope toko, laporan saja) ---
        // Rumus per bulan identik MoM/YoY via monthLaba(): omzet = penjualan + service,
        // laba = laba bersih bulanan. Dibungkus try/catch agar laporan tetap tampil.
        $yearTrendLabels = [];
        $yearTrendOmzet = [];
        $yearTrendLaba = [];
        $yearTrendRows = [];
        try {
            $namaBulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
            for ($m = 1; $m <= 12; $m++) {
                $ym = sprintf('%s-%02d', $tahunBerjalan, $m);
                [$bOmzetJual, $bService, $bLaba] = $this->monthLaba((int) $storeId, $ym);
                $bOmzet = (float) $bOmzetJual + (float) $bService;
                $bLaba = (float) $bLaba;
                $yearTrendLabels[] = $namaBulan[$m - 1];
                $yearTrendOmzet[] = $bOmzet;
                $yearTrendLaba[] = $bLaba;
                $yearTrendRows[] = [
                    'bulan' => $ym,
                    'label' => $namaBulan[$m - 1] . ' ' . $tahunBerjalan,
                    'omzet' => $bOmzet,
                    'laba' => $bLaba,
                ];
            }
        } catch (\Throwable $e) {
            report($e);
        }

        // --- Ringkasan otomatis (rule-based, laporan saja, tanpa AI eksternal) ---
        // 3-5 kalimat: pertumbuhan MoM, kategori terbaik, kas, peringatan stok/hutang bila ada.
        $ringkasan = '';
        $stokMenipis = 0;
        $hutangJatuhTempo = 0;
        try {
            try {
                // Min EFEKTIF per toko: COALESCE(product_stocks.min_stock, products.min_stock).
                $stokMenipis = (int) DB::table('product_stocks')
                    ->join('products', 'products.id', '=', 'product_stocks.product_id')
                    ->where('product_stocks.store_id', $storeId)
                    ->whereRaw('product_stocks.qty <= COALESCE(product_stocks.min_stock, products.min_stock)')
                    ->count();
            } catch (\Throwable $e) {
                report($e);
            }
            try {
                $hutangJatuhTempo = (int) Debt::where('store_id', $storeId)
                    ->where('type', 'hutang')
                    ->where('status', '!=', 'lunas')
                    ->whereNotNull('due_date')
                    ->where('due_date', '<=', now()->addDays(7)->toDateString())
                    ->count();
            } catch (\Throwable $e) {
                report($e);
            }
            $fmtRp = fn ($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
            $fmtPct = fn ($v) => number_format(abs((float) $v), 1, ',', '.') . '%';
            $arah = fn ($v) => $v > 0.5 ? 'naik' : ($v < -0.5 ? 'turun' : 'relatif stabil');
            $kal = [];
            $kal[] = 'Pada ' . $momCurMonth . ', laba bersih tercatat ' . $fmtRp($momCurLaba)
                . ' (' . $arah($momLabaPct) . ' ' . $fmtPct($momLabaPct)
                . ' dibanding bulan lalu ' . $fmtRp($momPrevLaba) . '),'
                . ' dengan omzet penjualan ' . $fmtRp($momCurOmzet)
                . ' (' . $arah($momOmzetPct) . ' ' . $fmtPct($momOmzetPct) . ' MoM)'
                . ' dan pendapatan service ' . $fmtRp($momCurService)
                . ' (' . $arah($momServicePct) . ' ' . $fmtPct($momServicePct) . ' MoM).';
            $best = $profitByCategory->first();
            if (is_array($best) && ($best['category'] ?? '') !== '') {
                $kal[] = 'Kategori terbaik periode ini adalah ' . $best['category']
                    . ' dengan profit bersih ' . $fmtRp($best['profit'] ?? 0)
                    . ' dari omzet ' . $fmtRp($best['omzet'] ?? 0)
                    . ' (margin ' . number_format((float) ($best['margin'] ?? 0), 1, ',', '.') . '%).';
            } elseif ($topProducts->isNotEmpty()) {
                $tp = $topProducts->first();
                $kal[] = 'Produk penyumbang profit terbesar adalah ' . ($tp['name'] ?? '—')
                    . ' dengan profit ' . $fmtRp($tp['profit'] ?? 0) . '.';
            } else {
                $kal[] = 'Belum ada penjualan produk pada periode ini sehingga belum ada kategori terbaik.';
            }
            $kal[] = 'Posisi kas kini ' . $fmtRp($neracaKas)
                . ' dengan hutang outstanding ' . $fmtRp($hutangOutstanding)
                . ' dan piutang outstanding ' . $fmtRp($piutangOutstanding)
                . ', serta ROI persediaan ' . number_format((float) $roiPersen, 1, ',', '.') . '%.';
            $peringatan = [];
            if ((float) $labaBersih < 0) {
                $peringatan[] = 'periode ini rugi (' . $fmtRp($labaBersih) . '), tekan pembelian dan operasional';
            }
            if ($stokMenipis > 0) {
                $peringatan[] = $stokMenipis . ' produk stoknya menipis (<= min. stok), segera reorder';
            }
            if ($hutangJatuhTempo > 0) {
                $peringatan[] = $hutangJatuhTempo . ' hutang jatuh tempo dalam 7 hari, siapkan kas';
            } elseif ((float) $hutangOutstanding > (float) $neracaKas && (float) $hutangOutstanding > 0) {
                $peringatan[] = 'hutang melebihi kas, jaga likuiditas';
            }
            if ((float) $returAmount > 0 && ((float) $omzetPenjualan + (float) $pendapatanService) > 0) {
                $rasio = (float) $returAmount / ((float) $omzetPenjualan + (float) $pendapatanService) * 100;
                if ($rasio >= 5) {
                    $peringatan[] = 'retur mencapai ' . number_format($rasio, 1, ',', '.')
                        . '% dari omzet (' . $fmtRp($returAmount) . '), cek kualitas/QC';
                }
            }
            if (!empty($peringatan)) {
                $kal[] = 'Perhatian: ' . implode('; ', $peringatan) . '.';
            } else {
                $kal[] = 'Tidak ada peringatan stok maupun hutang yang mendesak pada periode ini.';
            }
            $ringkasan = implode(' ', array_slice($kal, 0, 5));
        } catch (\Throwable $e) {
            report($e);
            $ringkasan = 'Ringkasan otomatis belum dapat disusun karena sebagian data gagal dimuat.';
        }

        $data = array_merge(compact(
            'storeId', 'from', 'to', 'start', 'end',
            'omzetPenjualan', 'profitPenjualan', 'profitPenjualanNet', 'returAmount', 'returProfit',
            'diskonHeader', 'diskonItem', 'totalDiskon',
            'pendapatanService',
            'sparepartOmzet', 'sparepartHpp', 'profitSparepart',
            'pembelian', 'operasional', 'komisi',
            'hutangOutstanding', 'piutangOutstanding',
            'labaKotor', 'labaBersih',
            'modalStok', 'modalUnit', 'modalTertanam', 'roiPerSeribu', 'roiPersen',
            'neracaTanggal', 'neracaKas', 'neracaAset', 'neracaKewajiban', 'neracaModalPlug',
            'ringkasan', 'stokMenipis', 'hutangJatuhTempo'
        ), compact(
            'trendLabels', 'trendOmzet', 'trendProfit',
            'donutPenjualan', 'donutService', 'donutSparepart',
            'topProducts', 'expenseByCat', 'topCashiers', 'profitByCategory',
            'seringBersamaan', 'seringTotal', 'seringStart', 'seringEnd',
            'hourLabels', 'hourCounts', 'busyHour', 'busyHourCount', 'staffTip',
            'quietHours', 'quietTip',
            'momCurMonth', 'momPrevMonth',
            'momCurOmzet', 'momPrevOmzet', 'momOmzetPct',
            'momCurService', 'momPrevService', 'momServicePct',
            'momCurLaba', 'momPrevLaba', 'momLabaPct',
            'yoyMonth', 'yoyOmzet', 'yoyService', 'yoyLaba',
            'yoyOmzetPct', 'yoyLabaPct'
        ), compact(
            'pphTarif', 'omzetPeriode', 'pphPeriode',
            'tahunBerjalan', 'yearStart', 'yearEnd',
            'omzetTahunBerjalan', 'pphTahunBerjalan',
            'yearTrendLabels', 'yearTrendOmzet', 'yearTrendLaba', 'yearTrendRows'
        ));

        if ($request->query('export') === 'csv') {
            $filename = "laba-rugi-{$storeId}-{$from}-sd-{$to}.csv";
            return new StreamedResponse(function () use ($data) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['id', 'komponen', 'nominal_rp'], ';');
                $i = 0;
                foreach ([
                    'Omzet Penjualan' => $data['omzetPenjualan'],
                    'Profit Penjualan (via sale_items)' => $data['profitPenjualan'],
                    'Retur/refund periode ini (nominal)' => $data['returAmount'],
                    'Koreksi profit retur (pengurang)' => $data['returProfit'],
                    'Profit Penjualan BERSIH (setelah retur)' => $data['profitPenjualanNet'],
                    'Diskon item (sale_items, periode ini)' => $data['diskonItem'] ?? 0,
                    'Diskon header/manual+promo (sales, periode ini)' => $data['diskonHeader'] ?? 0,
                    'TOTAL DISKON periode (item + header)' => $data['totalDiskon'] ?? 0,
                    'Pendapatan Service' => $data['pendapatanService'],
                    'Omzet Sparepart Service' => $data['sparepartOmzet'],
                    'HPP Sparepart' => $data['sparepartHpp'],
                    'Keuntungan Sparepart' => $data['profitSparepart'],
                    'Pembelian (HPP barang)' => $data['pembelian'],
                    'Operasional (expenses)' => $data['operasional'],
                    'Gaji/Komisi Teknisi' => $data['komisi'],
                    'Hutang Outstanding' => $data['hutangOutstanding'],
                    'Piutang Outstanding' => $data['piutangOutstanding'],
                    'Laba Kotor' => $data['labaKotor'],
                    'LABA BERSIH' => $data['labaBersih'],
                    'Modal tertanam - stok (qty x harga beli)' => $data['modalStok'],
                    'Modal tertanam - unit tersedia' => $data['modalUnit'],
                    'Modal tertanam persediaan (total)' => $data['modalTertanam'] ?? 0,
                    'ROI: profit per Rp 1.000 modal' => $data['roiPerSeribu'] ?? 0,
                    '--- Neraca sederhana (posisi kini) ---' => $data['neracaTanggal'] ?? '',
                    'Neraca: kas total' => $data['neracaKas'] ?? 0,
                    'Neraca: modal persediaan (stok+unit)' => $data['modalTertanam'] ?? 0,
                    'Neraca: piutang outstanding' => $data['piutangOutstanding'],
                    'Neraca: TOTAL ASET' => $data['neracaAset'] ?? 0,
                    'Neraca: hutang outstanding (kewajiban)' => $data['hutangOutstanding'],
                    'Neraca: selisih/modal pemilik (plug)' => $data['neracaModalPlug'] ?? 0,
                    '--- Estimasi PPh Final UMKM 0,5% x omzet (info saja) ---' => '',
                    'Omzet periode (penjualan + service)' => $data['omzetPeriode'] ?? 0,
                    'Estimasi PPh Final periode (0,5% x omzet periode)' => $data['pphPeriode'] ?? 0,
                    'Omzet Jan-Des ' . ($data['tahunBerjalan'] ?? '') . ' (info)' => $data['omzetTahunBerjalan'] ?? 0,
                    'Estimasi PPh Final Jan-Des (info)' => $data['pphTahunBerjalan'] ?? 0,
                ] as $k => $v) fputcsv($out, [++$i, $k, $v], ';');
                fputcsv($out, ['', '--- Top Kasir (omzet periode ini) ---', ''], ';');
                foreach (($data['topCashiers'] ?? collect()) as $c) fputcsv($out, [++$i, 'Kasir: ' . $c['name'] . ' (' . $c['transaksi'] . ' transaksi)', $c['omzet']], ';');
                fputcsv($out, ['', '--- Profit per Kategori Produk (bersih setelah retur) ---', ''], ';');
                foreach (($data['profitByCategory'] ?? collect()) as $k) {
                    fputcsv($out, [++$i, 'Kategori [' . $k['category'] . '] omzet bersih (' . $k['qty'] . ' pcs)', $k['omzet']], ';');
                    fputcsv($out, [++$i, 'Kategori [' . $k['category'] . '] profit bersih (margin ' . number_format((float) $k['margin'], 1, ',', '.') . '%)', $k['profit']], ';');
                }
                fputcsv($out, ['', '--- Sering Dibeli Bersamaan (90 hari, ' . ($data['seringStart'] ?? '') . ' s/d ' . ($data['seringEnd'] ?? '') . ', ' . ($data['seringTotal'] ?? 0) . ' transaksi) ---', ''], ';');
                foreach (($data['seringBersamaan'] ?? collect()) as $p) {
                    fputcsv($out, [++$i, 'Pasangan [' . $p['pasangan'] . '] frekuensi (' . number_format((float) ($p['persen'] ?? 0), 1, ',', '.') . '% dari transaksi)', $p['frekuensi']], ';');
                }
                fclose($out);
            }, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename={$filename}",
            ]);
        }

        $stores = LookupCache::activeStores();

        // --- Perbandingan per toko (khusus superadmin/owner, laporan saja) ---
        // Agregat ringan periode [$start, $end]: omzet, profit bersih, transaksi.
        // Rumus laba identik dengan angka utama (profit jual bersih + jasa
        // + sparepart − pembelian − operasional − komisi).
        $storeCompare = collect();
        $storeCompareTotal = ['omzet' => 0.0, 'laba' => 0.0, 'transaksi' => 0];
        if (in_array(auth()->user()->role ?? '', ['superadmin', 'owner'])) {
            try {
                $storeCompare = $stores->map(function ($s) use ($start, $end) {
                    $sid = (int) $s->id;
                    $omzetJual = (float) Sale::where('store_id', $sid)
                        ->whereBetween(DB::raw('DATE(created_at)'), [$start, $end])
                        ->sum('total');
                    $omzetService = (float) ServiceTicket::where('store_id', $sid)
                        ->whereBetween(DB::raw('DATE(created_at)'), [$start, $end])
                        ->sum('total_cost');
                    $saleIds = Sale::where('store_id', $sid)
                        ->whereBetween(DB::raw('DATE(created_at)'), [$start, $end])
                        ->pluck('id');
                    $profit = $saleIds->isNotEmpty()
                        ? (float) SaleItem::whereIn('sale_id', $saleIds)->sum('profit')
                        : 0.0;
                    [, $returProfit] = $this->returAdjustment($sid, $start, $end);
                    $profitNet = $profit - (float) $returProfit;
                    $ticketIds = ServiceTicket::where('store_id', $sid)
                        ->whereBetween(DB::raw('DATE(created_at)'), [$start, $end])
                        ->pluck('id');
                    $spOmzet = 0.0;
                    $spHpp = 0.0;
                    if ($ticketIds->isNotEmpty()) {
                        $rows = ServiceSparepart::whereIn('service_ticket_id', $ticketIds)
                            ->with('product')
                            ->get();
                        foreach ($rows as $r) {
                            $spOmzet += (float) $r->subtotal;
                            $spHpp += (float) ($r->product?->purchase_price ?? 0) * (int) $r->qty;
                        }
                    }
                    $spProfit = $spOmzet - $spHpp;
                    $pembelian = (float) Purchase::where('store_id', $sid)
                        ->whereBetween(DB::raw('DATE(created_at)'), [$start, $end])
                        ->sum('total');
                    $operasional = (float) Expense::where('store_id', $sid)
                        ->whereBetween(DB::raw('DATE(expense_date)'), [$start, $end])
                        ->sum('amount');
                    $komisi = (float) TechnicianCommission::whereIn(
                        'service_ticket_id',
                        $ticketIds->isNotEmpty() ? $ticketIds : [0]
                    )->sum('amount');
                    $labaKotor = $profitNet + ($omzetService - $spOmzet) + $spProfit;
                    $laba = $labaKotor - $pembelian - $operasional - $komisi;
                    $transaksi = $saleIds->count() + $ticketIds->count();
                    return [
                        'id' => $sid,
                        'name' => $s->name,
                        'omzet' => $omzetJual + $omzetService,
                        'laba' => $laba,
                        'transaksi' => $transaksi,
                    ];
                })->sortByDesc('laba')->values();
                $storeCompareTotal = [
                    'omzet' => (float) $storeCompare->sum('omzet'),
                    'laba' => (float) $storeCompare->sum('laba'),
                    'transaksi' => (int) $storeCompare->sum('transaksi'),
                ];
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $data['storeCompare'] = $storeCompare;
        $data['storeCompareTotal'] = $storeCompareTotal;

        return view('reports.profit', array_merge($data, ['stores' => $stores]));
    }
}
