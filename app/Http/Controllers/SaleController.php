<?php

namespace App\Http\Controllers;

use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\Customer;
use App\Models\Debt;
use App\Models\Sale;
use App\Models\SalePayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SaleController extends Controller
{
    protected function scopeStore(Request $request): array
    {
        $storeId = (int) session('active_store_id');
        $showAll = $request->boolean('all') && (auth()->user()->role ?? '') === 'superadmin';
        return [$storeId, $showAll];
    }

    public function index(Request $request)
    {
        [$storeId, $showAll] = $this->scopeStore($request);
        $query = Sale::with(['customer', 'store', 'user'])
            ->withSum('items as profit_total', 'profit')
            ->when(!$showAll && $storeId, fn($q) => $q->where('store_id', $storeId))
            ->when($request->filled('from'), fn($q) => $q->whereDate('created_at', '>=', $request->from))
            ->when($request->filled('to'), fn($q) => $q->whereDate('created_at', '<=', $request->to))
            ->when($request->filled('status'), fn($q) => $q->where('payment_status', $request->status))
            ->when($request->filled('method'), fn($q) => $q->where('payment_method', $request->method))
            ->when($request->filled('customer_id'), function ($q) use ($request) {
                if ($request->customer_id === 'umum') {
                    $q->whereNull('customer_id');
                } elseif (is_numeric($request->customer_id)) {
                    $q->where('customer_id', (int) $request->customer_id);
                }
            })
            ->when($request->filled('q'), fn($q) => $q->where('invoice_no', 'like', '%' . $request->q . '%'))
            ->orderByDesc('id');

        // Export CSV dari filter yang sedang aktif
        if ($request->get('export') === 'csv') {
            $rows = (clone $query)->with('customer')->limit(5000)->get();
            $filename = 'penjualan-' . now()->format('Ymd-His') . '.csv';
            return response()->stream(function () use ($rows) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['id', 'invoice', 'tanggal', 'toko', 'pelanggan', 'total', 'diskon', 'profit', 'metode', 'status'], ';');
                foreach ($rows as $s) {
                    fputcsv($out, [
                        $s->id, $s->invoice_no, $s->created_at->format('Y-m-d H:i'), $s->store->name ?? '',
                        $s->customer->name ?? 'Umum', (float) $s->total, (float) $s->discount,
                        (float) ($s->profit_total ?? 0), $s->payment_method, $s->payment_status,
                    ], ';');
                }
                fclose($out);
            }, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"{$filename}\""]);
        }

        // Export CSV rincian per item (satu baris per SaleItem, hormati filter + scope toko)
        if ($request->get('export') === 'items') {
            $sales = (clone $query)->with(['customer', 'items.product', 'items.productItem'])->limit(5000)->get();
            $filename = 'penjualan-items-' . now()->format('Ymd-His') . '.csv';
            return response()->stream(function () use ($sales) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['invoice', 'tanggal', 'pelanggan', 'produk', 'imei', 'qty', 'harga', 'diskon', 'subtotal', 'profit'], ';');
                foreach ($sales as $s) {
                    foreach ($s->items as $it) {
                        fputcsv($out, [
                            $s->invoice_no,
                            $s->created_at->format('Y-m-d H:i'),
                            $s->customer->name ?? 'Umum',
                            $it->product->name ?? $it->description ?? '',
                            $it->productItem->imei ?? '',
                            (int) $it->qty,
                            (float) $it->price,
                            (float) $it->discount,
                            (float) $it->subtotal,
                            (float) $it->profit,
                        ], ';');
                    }
                }
                fclose($out);
            }, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"{$filename}\""]);
        }

        $sales = $query->paginate(20)->withQueryString();
        $customers = Customer::query()
            ->when(!$showAll && $storeId, fn($q) => $q->where('store_id', $storeId))
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name', 'phone']);
        return view('sales.index', compact('sales', 'showAll', 'customers'));
    }

    public function show(Request $request, $sale)
    {
        // Pengaman ganda: closing sudah didaftarkan sebelum resource, tapi
        // delegasi ini dipertahankan bila route di-cache versi lama.
        if ($sale === 'closing') return $this->closing($request);
        if (!$sale instanceof Sale) $sale = Sale::findOrFail($sale);
        [$storeId, $showAll] = $this->scopeStore($request);
        if (!$showAll && $storeId) abort_if($sale->store_id !== $storeId, 403, 'Bukan transaksi toko aktif.');
        $sale->load(['items.product', 'items.productItem', 'payments.user', 'returns', 'customer', 'store', 'promo', 'user', 'tradeIns']);
        // Jadwal angsuran (tampilan saja, tanpa ubah data): khusus payment_method
        // cicilan + tenor N > 0. Basis = total invoice; DP awal ikut tercatat
        // sebagai payments sehingga otomatis mengurangi kumulatif.
        $installmentSchedule = [];
        $tenor = max(0, (int) $sale->installment_tenor);
        $grandTotal = (float) $sale->total;
        if ($sale->payment_method === 'cicilan' && $tenor > 0 && $grandTotal > 0) {
            $base = (int) round($grandTotal / $tenor);
            $paidCum = (float) $sale->payments->sum('amount');
            $running = 0;
            for ($i = 1; $i <= $tenor; $i++) {
                $nominal = $i < $tenor
                    ? $base
                    : max(0, (int) round($grandTotal - $base * ($tenor - 1)));
                $running += $nominal;
                $due = (clone $sale->created_at)->addMonthsNoOverflow($i);
                $installmentSchedule[] = [
                    'n' => $i,
                    'due' => $due,
                    'nominal' => $nominal,
                    'cumulative' => $running,
                    'status' => $paidCum >= $running ? 'lunas' : 'belum',
                ];
            }
        }
        return view('sales.show', compact('sale', 'installmentSchedule'));
    }

    /** Invoice formal A4 — untuk printer kantor (inkjet/laser) & arsip PDF. */
    public function invoice(Request $request, Sale $sale)
    {
        [$storeId, $showAll] = $this->scopeStore($request);
        if (!$showAll && $storeId) abort_if($sale->store_id !== $storeId, 403, 'Bukan transaksi toko aktif.');
        $sale->load(['items.product', 'customer', 'store', 'promo', 'user', 'payments']);
        $branding = \App\Services\EscPosService::branding($sale->store_id);
        $brandingLogo = \App\Services\EscPosService::logoUrl($sale->store_id);
        return view('pos.invoice_a4', compact('sale', 'branding', 'brandingLogo'));
    }

    /** Cetak massal invoice ringkas: gabungkan ≤50 invoice terpilih dalam 1 halaman cetak + page-break. */
    public function bulkInvoices(Request $request)
    {
        [$storeId, $showAll] = $this->scopeStore($request);
        $ids = array_values(array_unique(array_map('intval', (array) $request->query('ids', $request->input('ids', [])))));
        $ids = array_values(array_filter($ids, fn ($v) => $v > 0));
        if (empty($ids)) {
            return back()->with('error', 'Pilih minimal 1 transaksi untuk dicetak.');
        }
        if (count($ids) > 50) {
            return back()->with('error', 'Maksimal 50 invoice per cetak massal.');
        }

        $sales = Sale::with(['items.product', 'customer', 'store', 'user'])
            ->when(!$showAll && $storeId, fn ($q) => $q->where('store_id', $storeId))
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();
        if ($sales->isEmpty()) {
            return back()->with('error', 'Tidak ada transaksi valid yang dipilih.');
        }

        $store = $showAll
            ? (object) ['name' => 'Semua toko', 'address' => '', 'phone' => '']
            : \App\Models\Store::whereKey($sales->first()->store_id)->first();

        return view('sales.bulk_invoices', compact('sales', 'store'));
    }

    /** Cetak massal surat perjanjian kredit: gabungkan ≤20 transaksi cicilan/DP
     *  terpilih dalam 1 halaman cetak + page-break per perjanjian. Tanpa tabel baru. */
    public function bulkAgreements(Request $request)
    {
        [$storeId, $showAll] = $this->scopeStore($request);
        $ids = array_values(array_unique(array_map('intval', (array) $request->query('ids', $request->input('ids', [])))));
        $ids = array_values(array_filter($ids, fn ($v) => $v > 0));
        if (empty($ids)) {
            return back()->with('error', 'Pilih minimal 1 transaksi untuk dicetak.');
        }
        if (count($ids) > 20) {
            return back()->with('error', 'Maksimal 20 perjanjian per cetak massal.');
        }

        $sales = Sale::with(['items.product', 'items.productItem', 'customer', 'store', 'user', 'payments'])
            ->when(!$showAll && $storeId, fn ($q) => $q->where('store_id', $storeId))
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get()
            ->filter(fn ($s) => in_array($s->payment_method, ['cicilan', 'dp'], true)
                || in_array($s->payment_status, ['cicilan', 'dp'], true))
            ->values();
        if ($sales->isEmpty()) {
            return back()->with('error', 'Tidak ada transaksi cicilan/DP valid yang dipilih.');
        }

        $rows = $sales->map(function ($sale) {
            $tenor = max(0, (int) $sale->installment_tenor);
            $total = (float) $sale->total;
            $paid = (float) $sale->paid_amount;
            $remaining = (float) $sale->remaining;
            $angsuran = ($tenor > 0 && $total > 0) ? round($total / $tenor) : $remaining;
            $sigPath = null;
            if (preg_match('/\[SIG:([^\]]+)\]/', (string) $sale->note, $sigm)) {
                $sigPath = trim($sigm[1]);
            }
            $noteBersih = trim(preg_replace('/\s*\[SIG:[^\]]+\]/', '', (string) $sale->note ?? ''));
            return compact('sale', 'tenor', 'total', 'paid', 'remaining', 'angsuran', 'sigPath', 'noteBersih');
        });

        $store = $showAll
            ? (object) ['name' => 'Semua toko', 'address' => '', 'phone' => '']
            : \App\Models\Store::whereKey($sales->first()->store_id)->first();

        return view('sales.bulk_agreements', compact('rows', 'store'));
    }

    /** Rekap tutup kasir per tanggal + kasir: omzet, per metode, diskon, retur, kas masuk/keluar. */
    public function closing(Request $request)
    {
        [$storeId, $showAll] = $this->scopeStore($request);
        $date = $request->get('date', now()->toDateString());
        $kasirId = $request->get('kasir_id');

        $data = $this->closingData($storeId, $showAll, $date, $kasirId);

        return view('sales.closing', array_merge($data, ['date' => $date, 'kasirId' => $kasirId, 'showAll' => $showAll]));
    }

    /** Kirim rekap tutup kasir via WA ke owner (nomor owner pertama / telepon toko). Tanpa tabel baru. */
    public function closingWa(Request $request)
    {
        $request->validate([
            'date' => 'nullable|date',
            'kasir_id' => 'nullable|integer',
        ]);
        [$storeId, $showAll] = $this->scopeStore($request);
        $date = $request->get('date', now()->toDateString());
        $kasirId = $request->get('kasir_id');
        $data = $this->closingData($storeId, $showAll, $date, $kasirId);

        $ownerPhone = \App\Models\User::where('role', 'owner')->orderBy('id')->value('phone') ?? '';
        if (!$ownerPhone && $storeId) {
            $ownerPhone = \App\Models\Store::whereKey($storeId)->value('phone') ?? '';
        }
        abort_if(!$ownerPhone, 422, 'Nomor owner belum diisi (user role owner / telepon toko).');

        $kasirUser = $kasirId ? $data['cashiers']->firstWhere('id', (int) $kasirId) : null;
        $kasirName = $kasirUser ? $kasirUser->name : ($kasirId ? '#' . $kasirId : 'Semua kasir');
        $metodeLines = collect($data['perMetode'])
            ->map(fn($t, $m) => '- ' . strtoupper($m) . ': Rp ' . number_format($t, 0, ',', '.'))
            ->implode("\n");
        $msg = "🧾 *REKAP HARIAN* {$date}\n"
            . 'Kasir: ' . $kasirName . "\n"
            . 'Omzet (' . $data['trxCount'] . ' trx): Rp ' . number_format($data['omzet'], 0, ',', '.') . "\n"
            . ($metodeLines ? $metodeLines . "\n" : '')
            . 'Diskon: Rp ' . number_format($data['diskon'], 0, ',', '.') . "\n"
            . 'Retur: Rp ' . number_format($data['retur'], 0, ',', '.') . "\n"
            . 'Kas masuk: Rp ' . number_format($data['kasMasuk'], 0, ',', '.') . "\n"
            . 'Kas keluar: Rp ' . number_format($data['kasKeluar'], 0, ',', '.');

        $ok = \App\Services\WaService::send($ownerPhone, $msg, 'rekap_harian', $storeId ?: null);

        return back()->with($ok ? 'success' : 'error', $ok
            ? 'Rekap harian dikirim ke WA owner (' . $ownerPhone . ').'
            : 'Gagal mengirim rekap ke WA owner. Cek konfigurasi gateway WA.');
    }

    /** Digest manual dari halaman closing: kirim rekap tanggal filter ke WA owner. Tanpa tabel baru. */
    public function closingDigest(Request $request)
    {
        $request->validate([
            'date' => 'nullable|date',
            'kasir_id' => 'nullable|integer',
        ]);
        [$storeId, $showAll] = $this->scopeStore($request);
        $date = $request->get('date', now()->subDay()->toDateString());

        // Pakai ulang closingData sesuai filter tanggal (+ kasir bila dipilih),
        // lalu lengkapi statistik service via DailyDigestService agar konsisten dgn digest:daily.
        $kasirId = $request->get('kasir_id');
        $data = $this->closingData($showAll ? null : ($storeId ?: null), $showAll, $date, $kasirId);
        $svc = \App\Services\DailyDigestService::dataForStore(
            $showAll ? null : ($storeId ?: null), $date
        );
        $data['tiketMasuk'] = $svc['tiketMasuk'];
        $data['tiketSiap'] = $svc['tiketSiap'];
        $data['tiketDiserahkan'] = $svc['tiketDiserahkan'];
        $data['lowStocks'] = $svc['lowStocks'] ?? collect();
        $data['lowStockCount'] = $svc['lowStockCount'] ?? 0;
        $data['debtDues'] = $svc['debtDues'] ?? collect();
        $data['debtDueCount'] = $svc['debtDueCount'] ?? 0;

        [$phone, $label] = \App\Services\DailyDigestService::recipientForStore($showAll ? null : ($storeId ?: null));
        abort_if(!$phone, 422, 'Nomor owner belum diisi (user role owner / telepon toko).');

        $storeName = $showAll
            ? 'Semua toko'
            : (\App\Models\Store::whereKey($storeId)->value('name') ?? 'Toko');
        $msg = \App\Services\DailyDigestService::messageForStore($storeName, $date, $data);

        $ok = \App\Services\WaService::send($phone, $msg, 'digest_harian', $storeId ?: null);

        return back()->with($ok ? 'success' : 'error', $ok
            ? 'Digest ' . $date . ' dikirim ke WA ' . $label . ' (' . $phone . ').'
            : 'Gagal mengirim digest ke WA. Cek konfigurasi gateway WA.');
    }

    private function closingData(?int $storeId, bool $showAll, string $date, $kasirId): array
    {

        $saleScope = Sale::query()
            ->when(!$showAll && $storeId, fn($q) => $q->where('store_id', $storeId))
            ->whereDate('created_at', $date)
            ->when($kasirId, fn($q) => $q->where('user_id', $kasirId));

        $omzet = (clone $saleScope)->sum('total');
        $diskon = (clone $saleScope)->sum('discount');
        $trxCount = (clone $saleScope)->count();

        $perMetode = SalePayment::query()
            ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->when(!$showAll && $storeId, fn($q) => $q->where('sales.store_id', $storeId))
            ->whereDate('sale_payments.created_at', $date)
            ->when($kasirId, fn($q) => $q->where('sale_payments.user_id', $kasirId))
            ->groupBy('sale_payments.method')
            ->selectRaw('sale_payments.method, SUM(sale_payments.amount) as total')
            ->pluck('total', 'method');

        $retur = 0;
        try {
            if (\Schema::hasTable('sale_returns')) {
                $retur = \DB::table('sale_returns')
                    ->when(!$showAll && $storeId, fn($q) => $q->where('store_id', $storeId))
                    ->whereDate('created_at', $date)->sum('amount');
            }
        } catch (\Throwable $e) { }

        $kasMasuk = $kasKeluar = 0;
        try {
            if (\Schema::hasTable('cash_transactions')) {
                $kasMasuk = \DB::table('cash_transactions')
                    ->when(!$showAll && $storeId, fn($q) => $q->where('store_id', $storeId))
                    ->where('type', 'masuk')->whereDate('created_at', $date)->sum('amount');
                $kasKeluar = \DB::table('cash_transactions')
                    ->when(!$showAll && $storeId, fn($q) => $q->where('store_id', $storeId))
                    ->where('type', 'keluar')->whereDate('created_at', $date)->sum('amount');
            }
        } catch (\Throwable $e) { }

        $cashiers = \App\Models\User::whereIn('id', function ($q) use ($storeId, $showAll, $date) {
            $q->select('user_id')->from('sales')->whereDate('created_at', $date)
                ->when(!$showAll && $storeId, fn($qq) => $qq->where('store_id', $storeId));
        })->orderBy('name')->get();

        // Rekap SEMUA kasir per tanggal terpilih (omzet, trx, diskon, retur per user_id).
        // Scope toko+tanggal sama seperti rekap tunggal, tapi TANPA filter kasir_id.
        $perKasir = collect();
        try {
            $salesRows = Sale::query()
                ->when(!$showAll && $storeId, fn($q) => $q->where('store_id', $storeId))
                ->whereDate('created_at', $date)
                ->groupBy('user_id')
                ->selectRaw('user_id, COUNT(*) as trx, COALESCE(SUM(total),0) as omzet, COALESCE(SUM(discount),0) as diskon')
                ->get()
                ->keyBy('user_id');

            $returRows = collect();
            if (\Schema::hasTable('sale_returns')) {
                $returRows = \DB::table('sale_returns')
                    ->when(!$showAll && $storeId, fn($q) => $q->where('store_id', $storeId))
                    ->whereDate('created_at', $date)
                    ->groupBy('user_id')
                    ->selectRaw('user_id, COALESCE(SUM(amount),0) as retur')
                    ->get()
                    ->keyBy('user_id');
            }

            $userIds = collect();
            $profitRows = collect();
            try {
                if (\Schema::hasTable('sale_items')) {
                    $profitRows = \DB::table('sale_items')
                        ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                        ->when(!$showAll && $storeId, fn($q) => $q->where('sales.store_id', $storeId))
                        ->whereDate('sales.created_at', $date)
                        ->groupBy('sales.user_id')
                        ->selectRaw('sales.user_id, COALESCE(SUM(sale_items.profit),0) as profit')
                        ->get()
                        ->keyBy('user_id');
                }
            } catch (\Throwable $e) { }

            $userIds = $salesRows->keys()->merge($returRows->keys())->merge($profitRows->keys())->unique()->filter()->values();
            $names = \App\Models\User::whereIn('id', $userIds)->pluck('name', 'id');
            foreach ($userIds as $uid) {
                $s = $salesRows->get($uid);
                $r = $returRows->get($uid);
                $rowOmzet = (float) ($s->omzet ?? 0);
                $rowDiskon = (float) ($s->diskon ?? 0);
                $perKasir->push([
                    'user_id' => $uid,
                    'name' => $names->get($uid, 'Kasir #' . $uid),
                    'trx' => (int) ($s->trx ?? 0),
                    'omzet' => $rowOmzet,
                    'diskon' => $rowDiskon,
                    // Rasio diskon/omzet (%) untuk audit anti-fraud per kasir.
                    'rasio' => $rowOmzet > 0 ? round($rowDiskon / $rowOmzet * 100, 1) : 0,
                    'retur' => (float) ($r->retur ?? 0),
                    'profit' => (float) ($profitRows->get($uid)->profit ?? 0),
                ]);
            }
            $perKasir = $perKasir->sortByDesc('omzet')->values();
        } catch (\Throwable $e) { }

        return compact('cashiers', 'omzet', 'diskon', 'trxCount', 'perMetode', 'retur', 'kasMasuk', 'kasKeluar', 'perKasir');
    }

    /** Surat perjanjian kredit/cicilan: para pihak, barang, tenor, angsuran/bulan,
     *  aturan main standar (tanpa denda), TTD kedua pihak + catatan materai. Tanpa tabel baru. */
    public function agreement(Request $request, Sale $sale)
    {
        [$storeId, $showAll] = $this->scopeStore($request);
        if (!$showAll && $storeId) abort_if($sale->store_id !== $storeId, 403, 'Bukan transaksi toko aktif.');
        $isAngsuran = in_array($sale->payment_method, ['cicilan', 'dp'], true)
            || in_array($sale->payment_status, ['cicilan', 'dp'], true);
        abort_if(!$isAngsuran, 404, 'Surat perjanjian hanya tersedia untuk transaksi cicilan/DP.');
        $sale->load(['items.product', 'items.productItem', 'customer', 'store', 'promo', 'user', 'payments']);
        $tenor = max(0, (int) $sale->installment_tenor);
        $total = (float) $sale->total;
        $paid = (float) $sale->paid_amount;
        $remaining = (float) $sale->remaining;
        $angsuran = ($tenor > 0 && $total > 0) ? round($total / $tenor) : $remaining;
        $sigPath = null;
        if (preg_match('/\[SIG:([^\]]+)\]/', (string) $sale->note, $sigm)) {
            $sigPath = trim($sigm[1]);
        }
        $noteBersih = trim(preg_replace('/\s*\[SIG:[^\]]+\]/', '', (string) $sale->note ?? ''));
        return view('sales.agreement', compact('sale', 'tenor', 'total', 'paid', 'remaining', 'angsuran', 'sigPath', 'noteBersih'));
    }

    /** Kwitansi kecil per baris pembayaran: no kwitansi, invoice, pelanggan, jumlah, metode, tanggal, sisa setelah pembayaran ini + tanda tangan. */
    public function paymentReceipt(Request $request, SalePayment $payment)
    {
        $payment->load(['sale.customer', 'sale.store', 'sale.payments', 'user']);
        $sale = $payment->sale;
        abort_if(!$sale, 404, 'Transaksi tidak ditemukan.');
        [$storeId, $showAll] = $this->scopeStore($request);
        if (!$showAll && $storeId) abort_if($sale->store_id !== $storeId, 403, 'Bukan transaksi toko aktif.');

        $orderedIds = $sale->payments()->orderBy('id')->pluck('id')->values();
        $seq = max(1, array_search($payment->id, $orderedIds->all()) + 1);
        $cumulative = (float) $sale->payments()->where('id', '<=', $payment->id)->sum('amount');
        $sisaSetelah = max(0, (float) $sale->total - $cumulative);
        $receiptNo = 'KWT-' . $sale->invoice_no . '-' . $seq;

        return view('sales.payment_receipt', compact('payment', 'sale', 'seq', 'cumulative', 'sisaSetelah', 'receiptNo'));
    }

    public function pay(Request $request, Sale $sale)    {
        [$storeId] = $this->scopeStore($request);
        if ($storeId) abort_if($sale->store_id !== $storeId, 403, 'Bukan transaksi toko aktif.');
        // Kunci Periode: pembayaran bertanggal hari ini — tolak bila hari ini < batas.
        \App\Support\PeriodLock::check($sale->store_id, now()->toDateString(), 'Tanggal pembayaran');

        $data = $request->validate([
            'amount' => 'required|numeric|min:1|max:' . max(1, (float) $sale->remaining),
            'method' => 'required|in:tunai,transfer,qris,cicilan,dp',
            'cash_account_id' => 'nullable|exists:cash_accounts,id',
            'note' => 'nullable|string|max:500',
            'bukti' => 'nullable|image|max:2048',
        ], [
            'amount.required' => 'Nominal pembayaran wajib diisi.',
            'amount.numeric' => 'Nominal pembayaran harus berupa angka.',
            'amount.min' => 'Nominal pembayaran minimal Rp 1.',
            'amount.max' => 'Nominal melebihi sisa tagihan Rp ' . number_format((float) $sale->remaining, 0, ',', '.') . '.',
            'method.required' => 'Metode pembayaran wajib dipilih.',
            'method.in' => 'Metode pembayaran tidak valid.',
            'cash_account_id.exists' => 'Akun kas tidak ditemukan.',
        ]);

        $note = $data['note'] ?? 'Cicilan ' . $sale->invoice_no;
        if ($request->hasFile('bukti')) {
            $path = $request->file('bukti')->store('bukti-pembayaran', 'public');
            $note = trim($note . ' [BUKTI:' . $path . ']');
        }

        $sale->payments()->create([
            'amount' => $data['amount'], 'method' => $data['method'],
            'note' => $note, 'user_id' => auth()->id(),
        ]);

        $accountId = $data['cash_account_id'] ?? CashAccount::where('store_id', $sale->store_id)->orderByDesc('is_default')->value('id');
        CashTransaction::create([
            'store_id' => $sale->store_id, 'cash_account_id' => $accountId, 'type' => 'masuk',
            'category' => 'piutang', 'amount' => $data['amount'],
            'ref_type' => 'sale', 'ref_id' => $sale->id,
            'description' => 'Cicilan ' . $sale->invoice_no, 'user_id' => auth()->id(),
        ]);

        $sale->paid_amount = (float) $sale->paid_amount + (float) $data['amount'];
        $sale->remaining = max(0, (float) $sale->total - (float) $sale->paid_amount);
        $sale->payment_status = $sale->remaining <= 0 ? 'lunas' : ($sale->payment_method === 'cicilan' ? 'cicilan' : 'dp');
        $sale->save();

        $debt = Debt::where('ref_type', 'sale')->where('ref_id', $sale->id)->first();
        if ($debt) {
            $debt->paid = (float) $debt->paid + (float) $data['amount'];
            $debt->remaining = max(0, (float) $debt->total - (float) $debt->paid);
            $debt->status = $debt->remaining <= 0 ? 'lunas' : 'sebagian';
            $debt->save();
        }

        return back()->with('success', 'Pembayaran Rp ' . number_format($data['amount'], 0, ',', '.') . ' dicatat.');
    }

    public function destroy(Request $request, Sale $sale)
    {
        [$storeId] = $this->scopeStore($request);
        if ($storeId) abort_if($sale->store_id !== $storeId, 403, 'Bukan transaksi toko aktif.');
        abort_if($sale->payments()->count() > 1 || (float) $sale->paid_amount > 0 && $sale->payment_status === 'lunas' && $sale->payments()->count() > 0 && $sale->created_at->lt(now()->subDay()), 422, 'Transaksi lunas yang lama tidak bisa dihapus, gunakan Retur.');
        // Transaksi yang sudah punya retur = koreksi resmi sudah tercatat (stok sudah
        // dikembalikan ReturnController@store). Hapus diblokir agar stok tidak ganda.
        abort_if($sale->returns()->exists(), 422, 'Transaksi ini sudah punya Retur/Refund/Void, tidak bisa dihapus.');
        // Kunci Periode: dokumen di periode terkunci tidak boleh dihapus (koreksi via Retur di periode berjalan).
        \App\Support\PeriodLock::check($sale->store_id, $sale->created_at, 'Tanggal transaksi');
        // Hapus = koreksi total: kembalikan stok/unit, bersihkan kas & hutang terkait, baru hapus sale.
        \DB::transaction(function () use ($sale) {
            $sale->loadMissing(['items.productItem']);
            foreach ($sale->items as $it) {
                if ($it->product_item_id) {
                    $unit = \App\Models\ProductItem::whereKey($it->product_item_id)->lockForUpdate()->first();
                    if ($unit && $unit->status === 'terjual') $unit->update(['status' => 'tersedia']);
                } elseif ($it->product_id) {
                    $st = \App\Models\ProductStock::where('product_id', $it->product_id)
                        ->where('store_id', $sale->store_id)->lockForUpdate()->first();
                    if ($st) $st->increment('qty', (int) $it->qty);
                }
            }
            \App\Models\StockMovement::where('ref_type', 'sale')->where('ref_id', $sale->id)->delete();
            CashTransaction::where('ref_type', 'sale')->where('ref_id', $sale->id)->delete();
            Debt::where('ref_type', 'sale')->where('ref_id', $sale->id)->delete();
            \App\Models\TradeIn::where('sale_id', $sale->id)->update(['sale_id' => null]);
            if ($sale->promo_id) \App\Models\Promo::whereKey($sale->promo_id)->decrement('used');
            // Hapus file TTD persetujuan cicilan/DP ([SIG:path] di note) — cermin ServiceController@destroy.
            if (preg_match('/\[SIG:([^\]]+)\]/', (string) $sale->note, $sigm)) {
                try { Storage::disk('public')->delete(trim($sigm[1])); } catch (\Throwable $e) { report($e); }
            }
            // Sinkron total_belanja pelanggan (definisi = sum sales.total, sama dgn CustomerController@show)
            if ($sale->customer_id) {
                $cust = Customer::whereKey($sale->customer_id)->first();
                if ($cust) {
                    $cust->update(['total_belanja' => max(0, (float) $cust->total_belanja - (float) $sale->total)]);
                }
            }
            $sale->delete();
        });
        // Sinkron saldo akun kas yang terkena (hitung ulang dari mutasi)
        try {
            foreach (CashAccount::where('store_id', $sale->store_id)->get() as $acc) {
                $masuk = CashTransaction::where('cash_account_id', $acc->id)->where('type', 'masuk')->sum('amount');
                $keluar = CashTransaction::where('cash_account_id', $acc->id)->where('type', 'keluar')->sum('amount');
                $acc->update(['balance' => $masuk - $keluar]);
            }
        } catch (\Throwable $e) { report($e); }
        return redirect()->route('sales.index')->with('success', 'Transaksi dihapus + stok/kas/hutang dikembalikan.');
    }
}
