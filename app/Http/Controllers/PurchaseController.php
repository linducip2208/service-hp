<?php

namespace App\Http\Controllers;

use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\Debt;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseController extends Controller
{
    private function activeStoreId(Request $request): ?int
    {
        return session('active_store_id')
            ?? $request->user()?->active_store_id
            ?? \App\Models\Store::where('is_active', true)->value('id');
    }

    public function index(Request $request)
    {
        $storeId = $this->activeStoreId($request);
        $base = Purchase::with(['supplier', 'user'])
            ->where('store_id', $storeId)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('q'), fn ($q) => $q->where('invoice_no', 'like', '%' . $request->q . '%'))
            // Filter bulan (?bulan=YYYY-MM): kolom tanggal yang dipakai
            // created_at (tanggal pembelian dicatat).
            ->when(
                $request->filled('bulan')
                    && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $request->bulan),
                function ($q) use ($request) {
                    [$py, $pm] = explode('-', (string) $request->bulan);
                    $q->whereYear('purchases.created_at', (int) $py)
                      ->whereMonth('purchases.created_at', (int) $pm);
                }
            )
            ->orderByDesc('id');
        $purchases = (clone $base)->paginate(20)->withQueryString();
        // Export CSV (?export=csv) — hormati filter aktif + scope toko
        if ($request->get('export') === 'csv') {
            $rows = (clone $base)->with('supplier')->limit(5000)->get();
            $filename = 'pembelian-' . now()->format('Ymd-His') . '.csv';
            return response()->streamDownload(function () use ($rows) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['id', 'invoice', 'tanggal', 'supplier', 'total', 'dibayar', 'sisa', 'status'], ';');
                foreach ($rows as $p) {
                    fputcsv($out, [$p->id, $p->invoice_no, $p->created_at?->format('Y-m-d H:i') ?? '', $p->supplier->name ?? '', (float) $p->total, (float) $p->paid, (float) $p->total - (float) $p->paid, $p->status], ';');
                }
                fclose($out);
            }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }
        // Export CSV rincian per item (?export=items): satu baris per PurchaseItem,
        // cermin SaleController@index export=items — hormati filter + scope toko.
        if ($request->get('export') === 'items') {
            $rows = (clone $base)->with(['supplier', 'items.product'])->limit(5000)->get();
            $filename = 'pembelian-items-' . now()->format('Ymd-His') . '.csv';
            return response()->streamDownload(function () use ($rows) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['invoice', 'tanggal', 'supplier', 'produk', 'qty', 'harga', 'subtotal'], ';');
                foreach ($rows as $p) {
                    foreach ($p->items as $it) {
                        fputcsv($out, [
                            $p->invoice_no,
                            $p->created_at?->format('Y-m-d H:i') ?? '',
                            $p->supplier->name ?? '',
                            $it->product->name ?? ('Produk #' . $it->product_id),
                            (int) $it->qty,
                            (float) $it->price,
                            (float) $it->subtotal,
                        ], ';');
                    }
                }
                fclose($out);
            }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }
        $suppliers = Supplier::orderBy('name')->get();
        $products = Product::where('is_active', true)->orderBy('name')->get();
        // Prefill dari Saran Belanja: ?supplier_id=&product_id[]=&qty[]=&price[]=
        $prefill = ['supplier_id' => $request->get('supplier_id'), 'items' => []];
        foreach ((array) $request->get('product_id', []) as $i => $pid) {
            if (!$pid) continue;
            $prefill['items'][] = [
                'product_id' => (int) $pid,
                'qty' => max(1, (int) ($request->get('qty', [])[$i] ?? 1)),
                'price' => max(0, (float) ($request->get('price', [])[$i] ?? 0)),
            ];
        }
        return view('purchases.index', compact('purchases', 'storeId', 'suppliers', 'products', 'prefill'));
    }

    public function show(Purchase $purchase)
    {
        $storeId = session('active_store_id') ?? $purchase->store_id;
        abort_if($purchase->store_id !== (int) $storeId && (auth()->user()->role ?? '') !== 'superadmin', 403, 'Bukan data pembelian toko aktif.');
        $purchase->load(['items.product', 'supplier', 'user']);
        $debt = Debt::where('ref_type', 'purchase')->where('ref_id', $purchase->id)->first();
        $accounts = CashAccount::where('store_id', $purchase->store_id)->orderBy('name')->get();
        // Status penerimaan TANPA migration: penanda "[DITERIMA ...]" di dalam kolom note.
        $received = str_contains((string) $purchase->note, '[DITERIMA');
        // Riwayat harga beli per produk sebagai referensi margin: harga terakhir + rata-rata.
        // + ALERT KENAIKAN HARGA: last > avg * (1 + threshold) => indikasi supplier menaikkan harga.
        // Ambang: 15% di atas rata-rata (last > avg * 1.15). Tanpa migration/route baru.
        $priceStats = [];
        $priceSpikeThreshold = 0.15;
        try {
            $pids = $purchase->items->pluck('product_id')->unique()->values();
            $products = Product::whereIn('id', $pids)->get()->keyBy('id');

            // Supplier termurah per produk (pola existing ProductController@reorder:
            // harga terbaru per supplier 12 bulan terakhir, lalu ambil min).
            $cheapestMap = [];
            if ($pids->isNotEmpty()) {
                $cheapHist = DB::table('purchase_items')
                    ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
                    ->leftJoin('suppliers', 'suppliers.id', '=', 'purchases.supplier_id')
                    ->whereIn('purchase_items.product_id', $pids->all())
                    ->where('purchases.created_at', '>=', now()->subMonths(12))
                    ->orderByDesc('purchase_items.id')
                    ->limit(2000)
                    ->get([
                        'purchase_items.product_id',
                        'purchase_items.price',
                        'purchases.supplier_id',
                        'suppliers.name as supplier_name',
                        'purchases.created_at as tanggal',
                    ]);
                $latestPerSup = [];
                foreach ($cheapHist as $h) {
                    $cpid = (int) $h->product_id;
                    $csid = $h->supplier_id !== null ? (int) $h->supplier_id : 0;
                    if (! isset($latestPerSup[$cpid][$csid])) {
                        $latestPerSup[$cpid][$csid] = $h;
                    }
                }
                foreach ($latestPerSup as $cpid => $bySup) {
                    $best = null;
                    foreach ($bySup as $csid => $row) {
                        if ($csid === 0 || $row->supplier_name === null) {
                            continue;
                        }
                        if ($best === null || (float) $row->price < (float) $best->price) {
                            $best = $row;
                        }
                    }
                    if ($best) {
                        $cheapestMap[$cpid] = $best;
                    }
                }
            }

            foreach ($pids as $pid) {
                $history = \App\Models\PurchaseItem::with('purchase:id,invoice_no,created_at')
                    ->where('product_id', $pid)
                    ->orderBy('id')
                    ->limit(30)
                    ->get()
                    ->map(fn ($row) => [
                        'label' => $row->purchase?->created_at?->format('d/m/y')
                            ?? ('#' . $row->purchase_id),
                        'invoice' => $row->purchase->invoice_no ?? ('#' . $row->purchase_id),
                        'price' => (float) $row->price,
                    ])
                    ->values()
                    ->all();
                $last = \App\Models\PurchaseItem::where('product_id', $pid)->orderByDesc('id')->value('price');
                $avg = \App\Models\PurchaseItem::where('product_id', $pid)->avg('price');
                $lastF = $last !== null ? (float) $last : null;
                $avgF = $avg !== null ? (float) $avg : null;
                $limitF = ($avgF !== null && $avgF > 0) ? $avgF * (1 + $priceSpikeThreshold) : null;
                $isSpike = $lastF !== null && $limitF !== null && $lastF > $limitF;
                $spikePct = ($isSpike && $avgF > 0) ? (($lastF - $avgF) / $avgF * 100) : null;
                $cheap = $cheapestMap[(int) $pid] ?? null;
                $priceStats[$pid] = [
                    'product' => $products->get($pid),
                    'last' => $last,
                    'avg' => $avg,
                    'count' => \App\Models\PurchaseItem::where('product_id', $pid)->count(),
                    'history' => $history,
                    'threshold' => $priceSpikeThreshold,
                    'limit' => $limitF,
                    'is_spike' => $isSpike,
                    'spike_pct' => $spikePct,
                    'cheapest_supplier_id' => $cheap?->supplier_id,
                    'cheapest_supplier' => $cheap?->supplier_name,
                    'cheapest_price' => $cheap !== null ? (float) $cheap->price : null,
                    'cheapest_date' => $cheap?->tanggal,
                ];
            }
        } catch (\Throwable $e) {
            report($e);
        }
        // Riwayat pembayaran hutang supplier (kas keluar ref purchase ini) + bukti transfer opsional.
        // Tanpa migration: path bukti dilacak via marker [BUKTI:path] di kolom description CashTransaction.
        $payHistory = CashTransaction::with(['cashAccount', 'user'])
            ->where('ref_type', 'purchase')
            ->where('ref_id', $purchase->id)
            ->where('store_id', $purchase->store_id)
            ->orderByDesc('id')
            ->get();
        return view('purchases.show', compact('purchase', 'debt', 'accounts', 'received', 'priceStats', 'priceSpikeThreshold', 'payHistory'));
    }

    /**
     * Cetak PO massal: gabungkan PO ringkas terpilih dalam 1 halaman cetak.
     * Cermin pola debts bulkStatements: checkbox + halaman gabungan page-break.
     * GET /purchases/bulk-print?ids[]=1&ids[]=2 — tanpa migration, maks 50.
     */
    public function printBulk(Request $request)
    {
        $storeId = $this->activeStoreId($request);
        $ids = array_values(
            array_unique(
                array_map(
                    'intval',
                    (array) $request->query('ids', $request->input('ids', []))
                )
            )
        );
        $ids = array_values(array_filter($ids, fn ($v) => $v > 0));
        if (empty($ids)) {
            return back()->with('error', 'Pilih minimal 1 pembelian untuk dicetak.');
        }
        if (count($ids) > 50) {
            return back()->with('error', 'Maksimal 50 PO per cetak massal.');
        }

        $purchases = Purchase::with(['items.product', 'supplier'])
            ->where('store_id', $storeId)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();
        if ($purchases->isEmpty()) {
            return back()->with('error', 'Tidak ada pembelian valid yang dipilih.');
        }

        $store = \App\Models\Store::whereKey($storeId)->first();

        return view('purchases.bulk_print', compact('purchases', 'store'));
    }

    public function store(Request $request)
    {
        $storeId = $this->activeStoreId($request);

        // Kunci Periode: pembelian baru bertanggal hari ini — tolak bila hari ini < batas.
        \App\Support\PeriodLock::check($storeId, now()->toDateString(), 'Tanggal pembelian');

        // Retur pembelian sederhana (pakai route store yang sudah ada): kurangi stok + sesuaikan debt/kas.
        if ($request->filled('retur_purchase_id')) {
            return $this->retur($request, $storeId);
        }

        $data = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'cash_account_id' => 'required|exists:cash_accounts,id',
            'paid' => 'nullable|numeric|min:0',
            'note' => 'nullable|string|max:1000',
            'nota_foto' => 'nullable|image|max:2048',
            'due_date' => 'nullable|date',
            'product_id' => 'required|array|min:1',
            'product_id.*' => 'required|exists:products,id',
            'qty' => 'required|array|min:1',
            'qty.*' => 'required|integer|min:1',
            'price' => 'required|array|min:1',
            'price.*' => 'required|numeric|min:0',
        ], [
            'supplier_id.required' => 'Pilih supplier dulu.',
            'supplier_id.exists' => 'Supplier tidak ditemukan.',
            'cash_account_id.required' => 'Pilih akun kas dulu.',
            'cash_account_id.exists' => 'Akun kas tidak ditemukan.',
            'product_id.required' => 'Tambahkan minimal 1 barang.',
            'product_id.*.exists' => 'Ada produk yang tidak ditemukan.',
            'qty.*.min' => 'Qty minimal 1.',
            'price.*.required' => 'Harga beli wajib diisi.',
            'due_date.date' => 'Jatuh tempo harus tanggal yang valid.',
        ]);

        $paid = (float) ($data['paid'] ?? 0);

        // KEPUTUSAN BISNIS: foto nota WAJIB untuk pembelian > Rp5.000.000 (tanpa migration).
        $totalPreview = 0;
        foreach ($data['product_id'] as $i => $pid) {
            $totalPreview += (int) $data['qty'][$i] * (float) $data['price'][$i];
        }
        if ($totalPreview > 5000000 && ! $request->hasFile('nota_foto')) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'nota_foto' => 'Foto nota wajib dilampirkan untuk pembelian di atas Rp5.000.000 (total Rp ' . number_format($totalPreview, 0, ',', '.') . '). Lampirkan foto nota/faktur dari supplier.',
            ]);
        }

        // GATE KAS KELUAR (Continuous 168): samakan CashController@store —
        // bayar langsung di muka pembelian = kas keluar. Paid > Rp10.000.000
        // hanya owner/admin/superadmin (bukti sudah wajib via nota_foto >5jt).
        if ($paid > 10000000) {
            $roleNow = $request->user()?->role ?? auth()->user()->role ?? '';
            if (! in_array($roleNow, ['superadmin', 'owner', 'admin'], true)) {
                abort(422, 'Pembayaran langsung pembelian di atas Rp10.000.000 hanya boleh diproses owner/admin/superadmin. Minta owner/admin/superadmin yang login lalu ulangi transaksi (atau simpan sebagai hutang dulu).');
            }
        }

        // Pola foto = SaleController@pay: file ke disk public,
        // path dilacak via marker [BUKTI:path] di kolom note (tanpa migration).
        $note = $data['note'] ?? null;
        if ($request->hasFile('nota_foto')) {
            $path = $request->file('nota_foto')->store('nota-supplier', 'public');
            $note = trim((string) $note . ' [BUKTI:' . $path . ']');
            $note = mb_substr($note, 0, 1000);
        }

        $items = [];
        foreach ($data['product_id'] as $i => $pid) {
            $items[] = [
                'product_id' => (int) $pid,
                'qty' => (int) $data['qty'][$i],
                'price' => (float) $data['price'][$i],
            ];
        }

        // Logika inti SAMA dengan import CSV (recordPurchase): stok+kas+debt atomik.
        $purchase = $this->recordPurchase(
            $storeId,
            $request->user()?->id,
            (int) $data['supplier_id'],
            (int) $data['cash_account_id'],
            $paid,
            $data['due_date'] ?? null,
            $note,
            $items
        );

        // Notifikasi stok otomatis: cek apakah produk masih di bawah min EFEKTIF
        // (override toko bila ada, kalau NULL ikut global) sesudah pembelian.
        $purchase->load('items');
        $lowStock = [];
        try {
            $pids = $purchase->items->pluck('product_id')->unique();
            $products = Product::whereIn('id', $pids)->get()->keyBy('id');
            $storeMins = ProductStock::where('store_id', $storeId)->whereIn('product_id', $pids)
                ->whereNotNull('min_stock')->pluck('min_stock', 'product_id');
            foreach ($purchase->items as $item) {
                $product = $products->get($item->product_id);
                if (!$product) continue;
                $qty = (int) (ProductStock::where('store_id', $storeId)->where('product_id', $item->product_id)->value('qty') ?? 0);
                $effMin = $storeMins->has($item->product_id) ? (int) $storeMins[$item->product_id] : (int) ($product->min_stock ?? 0);
                if ($qty < $effMin) {
                    $lowStock[] = $product->name . ' (sisa ' . $qty . ' < min ' . $effMin . ')';
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }

        $redirect = redirect()->route('purchases.show', $purchase)
            ->with('success', 'Pembelian tersimpan.');
        if ($lowStock) {
            $redirect->with('warning', 'Perhatian: stok masih di bawah minimum — ' . implode('; ', array_unique($lowStock)) . '. Segera pesan lagi ke supplier.');
        }
        return $redirect;
    }

    /**
     * Inti pencatatan pembelian — dipakai BERSAMA oleh store() manual & import() CSV
     * agar efek samping identik: stok+masuk, kas keluar, debt+supplier.hutang (atomik).
     *
     * @param  array<int,array{product_id:int,qty:int,price:float}>  $items
     */
    private function recordPurchase(
        int $storeId,
        ?int $userId,
        int $supplierId,
        int $cashAccountId,
        float $paid,
        ?string $dueDate,
        ?string $note,
        array $items,
        ?string $createdAt = null
    ): Purchase {
        return DB::transaction(function () use ($storeId, $userId, $supplierId, $cashAccountId, $paid, $dueDate, $note, $items, $createdAt) {
            $supplier = Supplier::whereKey($supplierId)->firstOrFail();

            $total = 0;
            foreach ($items as $it) {
                $total += (int) $it['qty'] * (float) $it['price'];
            }
            if ($total <= 0) abort(422, 'Total pembelian harus lebih dari 0.');
            if ($paid > $total) $paid = $total;

            $status = $paid <= 0 ? 'belum' : ($paid >= $total ? 'lunas' : 'sebagian');

            $account = CashAccount::whereKey($cashAccountId)
                ->where('store_id', $storeId)->lockForUpdate()->firstOrFail();
            if ($paid > 0 && (float) $account->balance < $paid) {
                abort(422, 'Saldo akun kas tidak mencukupi untuk pembayaran ini.');
            }

            $purchase = Purchase::create([
                // PO unik: {KODETOKO-}PB-YYYYMMDDHHMMSS-NNN (prefix kode toko
                // di depan bila stores.code terisi, fallback format lama).
                'invoice_no' => \App\Support\DocNumber::prefixForStore($storeId) . 'PB-' . now()->format('YmdHis') . '-' . str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT),
                'store_id' => $storeId,
                'supplier_id' => $supplier->id,
                'total' => $total,
                'paid' => $paid,
                'status' => $status,
                'user_id' => $userId,
                'note' => $note,
            ]);

            // Tanggal dokumen backdate (import CSV): tabel purchases tidak punya
            // kolom tanggal — tanggal pembelian = created_at (filter bulan index
            // memakai created_at). Tanpa migration: override timestamp manual.
            if ($createdAt !== null) {
                $purchase->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();
            }

            foreach ($items as $it) {
                $qty = (int) $it['qty'];
                $price = (float) $it['price'];
                $purchase->items()->create([
                    'product_id' => $it['product_id'],
                    'qty' => $qty,
                    'price' => $price,
                    'subtotal' => $qty * $price,
                ]);

                // Tambah stok + movement masuk (atomik)
                $stock = ProductStock::firstOrCreate(
                    ['store_id' => $storeId, 'product_id' => $it['product_id']],
                    ['qty' => 0]
                );
                $stock->increment('qty', $qty);

                StockMovement::create([
                    'store_id' => $storeId,
                    'product_id' => $it['product_id'],
                    'type' => 'masuk',
                    'qty' => $qty,
                    'ref_type' => 'purchase',
                    'ref_id' => $purchase->id,
                    'note' => 'Pembelian ' . $purchase->invoice_no,
                    'user_id' => $userId,
                ]);
            }

            // Kas keluar sejumlah paid
            if ($paid > 0) {
                $account->decrement('balance', $paid);
                CashTransaction::create([
                    'store_id' => $storeId,
                    'cash_account_id' => $account->id,
                    'type' => 'keluar',
                    'category' => 'pembelian',
                    'amount' => $paid,
                    'ref_type' => 'purchase',
                    'ref_id' => $purchase->id,
                    'description' => 'Pembelian ' . $purchase->invoice_no . ' dari ' . $supplier->name,
                    'user_id' => $userId,
                ]);
            }

            // Belum lunas -> buat hutang otomatis
            if ($paid < $total) {
                Debt::create([
                    'store_id' => $storeId,
                    'type' => 'hutang',
                    'party_name' => $supplier->name,
                    'ref_type' => 'purchase',
                    'ref_id' => $purchase->id,
                    'total' => $total,
                    'paid' => $paid,
                    'remaining' => $total - $paid,
                    'due_date' => $dueDate ?? now()->addDays(14)->toDateString(),
                    'status' => $paid <= 0 ? 'belum' : 'sebagian',
                ]);
                $supplier->increment('hutang', $total - $paid);
            }

            return $purchase;
        });
    }

    /**
     * UNDUH TEMPLATE CSV pembelian: header + 1 baris contoh, delimiter ;, BOM.
     * Kolom cocok dengan @import (supplier,tanggal,jatuh_tempo,dibayar,akun_kas,items).
     * GET /purchases/template — didaftarkan SEBELUM resource agar tak tertabrak {purchase}.
     */
    public function template()
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv(
                $out,
                ['supplier', 'tanggal', 'jatuh_tempo', 'dibayar', 'akun_kas', 'items'],
                ';'
            );
            fputcsv(
                $out,
                ['Contoh Supplier', date('Y-m-d'), '', '0', 'Kas Utama', 'SKU001:2:150000;SKU002:1:75000'],
                ';'
            );
            fclose($out);
        }, 'template_pembelian.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * IMPORT CSV pembelian massal. Pola ExpenseController@import
     * (delimiter auto ;/, + BOM strip, validasi per baris, laporan baris gagal).
     *
     * Format kolom: supplier,tanggal,jatuh_tempo,dibayar,akun_kas,items.
     * - supplier: NAMA supplier (case-insensitive, harus sudah terdaftar).
     * - tanggal kosong = hari ini (format Y-m-d / d-m-Y / d/m/Y / Y/m/d);
     *   disimpan sebagai created_at pembelian (filter bulan index memakai created_at).
     * - jatuh_tempo kosong = tanggal+14 hari (cermin default store manual).
     * - dibayar: angka >= 0, default 0 (0 = full hutang).
     * - akun_kas: NAMA akun kas toko aktif (case-insensitive); kosong = akun pertama.
     * - items: `SKU:qty:harga` dipisah `;` — mis. `SKU001:2:150000;SKU002:1:75000`.
     *   SKU dicocokkan ke products.sku; qty integer >= 1; harga numeric >= 0.
     *
     * Efek per baris valid = recordPurchase() yang SAMA dengan store manual:
     * stok+masuk per item, kas keluar sejumlah dibayar, debt + supplier.hutang
     * bila belum lunas — 1 invoice PB-xxx per baris. Ini transaksi berjalan,
     * BUKAN saldo awal — jangan import saldo awal lewat sini.
     *
     * Aturan bisnis per baris: kunci periode (tanggal), gate dibayar > Rp10jt
     * hanya owner/admin/superadmin, saldo kas mencukupi. Aturan foto nota
     * > Rp5jt (store manual) TIDAK berlaku di import karena CSV tanpa file —
     * baris besar tercatat tanpa marker bukti.
     */
    public function import(Request $request)
    {
        $storeId = $this->activeStoreId($request);
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:2048']);

        $accounts = CashAccount::where('store_id', $storeId)->orderBy('name')->get();
        if ($accounts->isEmpty()) {
            return back()->withErrors(['file' => 'Belum ada akun kas di toko aktif — buat akun kas dulu sebelum import.']);
        }
        $accountMap = [];
        foreach ($accounts as $a) {
            $accountMap[mb_strtolower(trim((string) $a->name))] = $a;
        }
        $defaultAccount = $accounts->first();

        $supplierMap = [];
        foreach (Supplier::orderBy('name')->get() as $s) {
            $supplierMap[mb_strtolower(trim((string) $s->name))] = $s;
        }
        if (empty($supplierMap)) {
            return back()->withErrors(['file' => 'Belum ada supplier — tambah supplier dulu sebelum import.']);
        }

        $productMap = [];
        foreach (Product::select('id', 'sku', 'name')->get() as $p) {
            $sku = mb_strtolower(trim((string) $p->sku));
            if ($sku !== '') $productMap[$sku] = $p;
        }
        if (empty($productMap)) {
            return back()->withErrors(['file' => 'Belum ada produk ber-SKU — lengkapi SKU produk dulu sebelum import.']);
        }

        $canBig = in_array($request->user()?->role ?? '', ['superadmin', 'owner', 'admin'], true);
        $parseDate = function (string $raw): ?string {
            $raw = trim($raw);
            if ($raw === '') return null;
            foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'Y/m/d'] as $fmt) {
                try {
                    $dt = \Carbon\Carbon::createFromFormat($fmt, $raw);
                    if ($dt && $dt->format($fmt) === $raw) return $dt->format('Y-m-d');
                } catch (\Throwable $e) { /* coba format berikut */ }
            }
            $ts = strtotime($raw);
            return $ts ? date('Y-m-d', $ts) : null;
        };

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        // Deteksi delimiter: template/export memakai ';' (Excel Indonesia), file manual umumnya ','.
        $firstLine = fgets($handle) ?: '';
        rewind($handle);
        $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';
        $readCsv = function () use ($handle, $delimiter) {
            $cols = fgetcsv($handle, 0, $delimiter);
            if (is_array($cols)) $cols = array_map(fn($c) => is_string($c) ? ltrim($c, "\xEF\xBB\xBF") : $c, $cols);
            return $cols;
        };
        $header = array_map(fn($h) => strtolower(trim((string) $h)), $readCsv() ?: []);
        $ok = 0; $fail = []; $line = 1;
        while (($cols = $readCsv()) !== false) {
            $line++;
            if (count($cols) === 1 && trim((string) $cols[0]) === '') continue;
            $row = array_combine(array_slice(array_merge($header, range(0, count($cols) - 1)), 0, count($cols)), $cols);
            $g = fn($k) => trim((string) ($row[$k] ?? ''));

            $err = null;
            $supplierRaw = $g('supplier');
            $tanggalRaw = $g('tanggal');
            $tempoRaw = $g('jatuh_tempo');
            $dibayarRaw = str_replace([' ', '_'], '', $g('dibayar'));
            $akunKasRaw = $g('akun_kas');
            $itemsRaw = $g('items');

            $tanggal = $tanggalRaw === '' ? date('Y-m-d') : $parseDate($tanggalRaw);
            $tempo = $tempoRaw === '' ? null : $parseDate($tempoRaw);
            $dibayar = $dibayarRaw === '' ? 0.0 : (is_numeric($dibayarRaw) ? (float) $dibayarRaw : null);

            $supplier = $supplierMap[mb_strtolower($supplierRaw)] ?? null;
            $account = $akunKasRaw === ''
                ? $defaultAccount
                : ($accountMap[mb_strtolower($akunKasRaw)] ?? null);

            // Parse items: `SKU:qty:harga` dipisah `;`.
            $items = [];
            $itemsErr = null;
            if ($itemsRaw === '') {
                $itemsErr = 'items kosong (format SKU:qty:harga;SKU2:qty:harga)';
            } else {
                foreach (explode(';', $itemsRaw) as $chunk) {
                    $chunk = trim($chunk);
                    if ($chunk === '') continue;
                    $parts = array_map('trim', explode(':', $chunk));
                    if (count($parts) !== 3) { $itemsErr = 'item tidak valid: "' . $chunk . '" (format SKU:qty:harga)'; break; }
                    [$sku, $qtyRaw, $priceRaw] = $parts;
                    $product = $productMap[mb_strtolower($sku)] ?? null;
                    $priceRaw = str_replace([' ', '_'], '', $priceRaw);
                    if ($product === null) { $itemsErr = 'SKU tidak dikenal: ' . $sku; break; }
                    if (!ctype_digit($qtyRaw) || (int) $qtyRaw < 1) { $itemsErr = 'qty tidak valid pada ' . $sku . ': ' . $qtyRaw; break; }
                    if (!is_numeric($priceRaw) || (float) $priceRaw < 0) { $itemsErr = 'harga tidak valid pada ' . $sku . ': ' . $qtyRaw . '/' . $priceRaw; break; }
                    $items[] = ['product_id' => (int) $product->id, 'qty' => (int) $qtyRaw, 'price' => (float) $priceRaw];
                }
                if ($itemsErr === null && empty($items)) $itemsErr = 'items kosong (format SKU:qty:harga;SKU2:qty:harga)';
            }

            if ($supplier === null) $err = 'supplier tidak dikenal: ' . $supplierRaw;
            elseif ($tanggal === null) $err = 'tanggal tidak valid: ' . $tanggalRaw;
            elseif ($tempoRaw !== '' && $tempo === null) $err = 'jatuh_tempo tidak valid: ' . $tempoRaw;
            elseif ($dibayar === null) $err = 'dibayar bukan angka';
            elseif ($dibayar < 0) $err = 'dibayar minimal 0';
            elseif ($account === null) $err = 'akun_kas tidak dikenal: ' . $akunKasRaw;
            elseif ($itemsErr !== null) $err = $itemsErr;
            elseif ($dibayar > 10000000 && !$canBig) $err = 'dibayar di atas Rp10.000.000 hanya boleh dicatat owner/admin';
            if ($err) { $fail[] = "Baris $line: $err"; continue; }

            // Kunci periode: tolak baris bertanggal < batas (tanpa abort agar baris lain lanjut).
            if (\App\Support\PeriodLock::isLocked($storeId, $tanggal)) {
                $fail[] = "Baris $line: periode terkunci (tutup buku per "
                    . \App\Support\PeriodLock::get($storeId) . ')';
                continue;
            }

            try {
                $this->recordPurchase(
                    $storeId,
                    $request->user()?->id,
                    (int) $supplier->id,
                    (int) $account->id,
                    $dibayar,
                    $tempo ?? \Carbon\Carbon::parse($tanggal)->addDays(14)->toDateString(),
                    null,
                    $items,
                    $tanggal
                );
                $ok++;
            } catch (\Throwable $e) {
                $fail[] = "Baris $line: " . $e->getMessage();
            }
        }
        fclose($handle);

        return redirect()->route('purchases.index')
            ->with('success', "Import selesai: $ok baris berhasil, " . count($fail) . ' gagal.'
                . ' Setiap baris = 1 pembelian (stok + kas + hutang otomatis, efek sama dengan input manual).')
            ->with('import_fail', $fail);
    }

    /** Bayar hutang supplier dari pembelian: kurangi Debt terkait + kas keluar + purchase.paid/status + supplier.hutang.
     *  Konsisten dengan SaleController@pay (POST /sales/{sale}/pay, name sales.pay). */
    public function paySupplier(Request $request, Purchase $purchase)
    {
        $storeId = session('active_store_id') ?? $purchase->store_id;
        abort_if($purchase->store_id !== (int) $storeId && (auth()->user()->role ?? '') !== 'superadmin', 403, 'Bukan data pembelian toko aktif.');

        $sisa = (float) $purchase->total - (float) $purchase->paid;
        abort_if($sisa <= 0, 422, 'Pembelian ini sudah lunas.');
        // Kunci Periode: pembayaran hutang supplier bertanggal hari ini.
        \App\Support\PeriodLock::check($purchase->store_id, now()->toDateString(), 'Tanggal pembayaran');

        $data = $request->validate([
            'amount' => 'required|numeric|min:1|max:' . max(1, $sisa),
            'cash_account_id' => 'required|exists:cash_accounts,id',
            'bukti' => 'nullable|image|max:2048',
        ], [
            'amount.required' => 'Nominal pembayaran wajib diisi.',
            'amount.numeric' => 'Nominal pembayaran harus berupa angka.',
            'amount.min' => 'Nominal pembayaran minimal Rp 1.',
            'amount.max' => 'Nominal melebihi sisa hutang Rp ' . number_format($sisa, 0, ',', '.') . '.',
            'cash_account_id.required' => 'Pilih akun kas dulu.',
            'cash_account_id.exists' => 'Akun kas tidak ditemukan.',
            'bukti.image' => 'Bukti harus berupa gambar (JPG/PNG).',
            'bukti.max' => 'Ukuran bukti maksimal 2MB.',
        ]);

        // GATE KAS KELUAR (Continuous 168): samakan CashController@store —
        // bayar hutang supplier = kas keluar langsung. Nominal > Rp10.000.000
        // hanya owner/admin/superadmin + wajib foto bukti. Tanpa migration.
        if ((float) $data['amount'] > 10000000) {
            $roleNow = $request->user()?->role ?? auth()->user()->role ?? '';
            if (! in_array($roleNow, ['superadmin', 'owner', 'admin'], true)) {
                abort(422, 'Pembayaran hutang supplier di atas Rp10.000.000 hanya boleh diproses owner/admin/superadmin. Minta owner/admin/superadmin yang login lalu ulangi pembayaran.');
            }
            if (! $request->hasFile('bukti')) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'bukti' => 'Pembayaran hutang supplier di atas Rp10.000.000 wajib melampirkan foto bukti transfer (maks 2 MB).',
                ]);
            }
        }

        // Bukti transfer opsional — pola SaleController@pay: file ke disk public,
        // path dilacak via marker [BUKTI:path] di description kas (tanpa migration).
        $buktiPath = null;
        if ($request->hasFile('bukti')) {
            $buktiPath = $request->file('bukti')->store('bukti-supplier', 'public');
        }

        DB::transaction(function () use ($data, $purchase, $storeId, $request, $buktiPath) {
            $purchase = Purchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            $sisa = (float) $purchase->total - (float) $purchase->paid;
            $bayar = min((float) $data['amount'], $sisa);
            abort_if($bayar <= 0, 422, 'Pembelian ini sudah lunas.');

            $account = CashAccount::whereKey($data['cash_account_id'])
                ->where('store_id', $storeId)->lockForUpdate()->firstOrFail();
            if ((float) $account->balance < $bayar) {
                abort(422, 'Saldo akun kas tidak mencukupi untuk pembayaran ini.');
            }
            $account->decrement('balance', $bayar);

            CashTransaction::create([
                'store_id' => $storeId,
                'cash_account_id' => $account->id,
                'type' => 'keluar',
                'category' => 'hutang',
                'amount' => $bayar,
                'ref_type' => 'purchase',
                'ref_id' => $purchase->id,
                'description' => 'Bayar hutang pembelian ' . $purchase->invoice_no
                    . ($buktiPath ? ' [BUKTI:' . $buktiPath . ']' : ''),
                'user_id' => $request->user()?->id,
            ]);

            $newPaid = (float) $purchase->paid + $bayar;
            $purchase->update([
                'paid' => $newPaid,
                'status' => $newPaid <= 0 ? 'belum' : ($newPaid >= (float) $purchase->total ? 'lunas' : 'sebagian'),
            ]);

            $debt = Debt::where('ref_type', 'purchase')->where('ref_id', $purchase->id)->lockForUpdate()->first();
            if ($debt) {
                $newDebtPaid = (float) $debt->paid + $bayar;
                $debt->update([
                    'paid' => $newDebtPaid,
                    'remaining' => max(0, (float) $debt->total - $newDebtPaid),
                    'status' => $newDebtPaid <= 0 ? 'belum' : ($newDebtPaid >= (float) $debt->total ? 'lunas' : 'sebagian'),
                ]);
            }

            if ($purchase->supplier_id) {
                $supplier = Supplier::whereKey($purchase->supplier_id)->lockForUpdate()->first();
                if ($supplier) $supplier->update(['hutang' => max(0, (float) $supplier->hutang - $bayar)]);
            }
        });

        return back()->with('success', 'Pembayaran hutang Rp ' . number_format((float) $data['amount'], 0, ',', '.') . ' dicatat (kas, hutang, & status pembelian diperbarui).');
    }

    /** Tandai barang sudah diterima — TANPA migration: penanda "[DITERIMA ...]" disimpan di kolom note. */
    public function markReceived(Request $request, Purchase $purchase)
    {
        $storeId = session('active_store_id') ?? $purchase->store_id;
        abort_if($purchase->store_id !== (int) $storeId && (auth()->user()->role ?? '') !== 'superadmin', 403, 'Bukan data pembelian toko aktif.');
        if (!str_contains((string) $purchase->note, '[DITERIMA')) {
            $purchase->update(['note' => trim((((string) $purchase->note !== '' ? $purchase->note . "\n" : '')) . '[DITERIMA ' . now()->format('d/m/Y H:i') . '] oleh ' . ($request->user()?->name ?? 'kasir'))]);
        }
        return back()->with('success', 'Pembelian ditandai sudah diterima.');
    }

    /** Pesan barang via WA ke supplier: susun teks pesanan + kirim via WaService::send type 'purchase_order'. */
    public function sendWa(Request $request, Purchase $purchase)
    {
        $storeId = session('active_store_id') ?? $purchase->store_id;
        abort_if($purchase->store_id !== (int) $storeId && (auth()->user()->role ?? '') !== 'superadmin', 403, 'Bukan data pembelian toko aktif.');
        $purchase->load(['items.product', 'supplier']);

        $rawPhone = (string) ($purchase->supplier->phone ?? '');
        $phone = \App\Services\WaService::normalize($rawPhone);
        if ($phone === '' || strlen($phone) < 9) {
            return back()->with('error', 'Nomor WA supplier kosong/tidak valid — lengkapi nomor HP supplier dulu.');
        }

        $lines = [];
        $lines[] = 'Halo *' . ($purchase->supplier->name ?? 'Supplier') . '*, kami dari ServiceHP ingin memesan barang:';
        $lines[] = 'No. Invoice ref: *' . $purchase->invoice_no . '*';
        $lines[] = '';
        $totalQty = 0;
        foreach ($purchase->items as $i => $it) {
            $nm = $it->product->name ?? ('Produk #' . $it->product_id);
            $lines[] = ($i + 1) . '. ' . $nm . ' x' . (int) $it->qty;
            $totalQty += (int) $it->qty;
        }
        $lines[] = '';
        $lines[] = 'Total item: ' . $totalQty . ' pcs (' . $purchase->items->count() . ' jenis barang).';
        $lines[] = 'Mohon konfirmasi ketersediaan & harga. Terima kasih.';
        $msg = implode("\n", $lines);

        $ok = \App\Services\WaService::send($phone, $msg, 'purchase_order', (int) $purchase->store_id);

        return $ok
            ? back()->with('success', 'Pesanan dikirim via WA ke ' . $purchase->supplier->name . ' (' . $phone . ').')
            : back()->with('error', 'Gagal mengirim WA ke supplier (' . $phone . '). Cek koneksi gateway / menu Pengaturan WA.');
    }

    /** Retur pembelian: stok keluar (retur_beli), total pembelian & hutang berkurang, kelebihan bayar kembali ke kas. */
    private function retur(Request $request, int $storeId)
    {
        // Kunci Periode: retur bertanggal hari ini; dokumen asal di periode terkunci tidak boleh dikoreksi via retur.
        \App\Support\PeriodLock::check($storeId, now()->toDateString(), 'Tanggal retur');
        $data = $request->validate([
            'retur_purchase_id' => 'required|exists:purchases,id',
            'purchase_item_id' => 'required|exists:purchase_items,id',
            'retur_qty' => 'required|integer|min:1',
            'cash_account_id' => 'required|exists:cash_accounts,id',
            'bukti' => 'nullable|image|max:2048',
        ]);

        DB::transaction(function () use ($data, $storeId, $request) {
            $purchase = Purchase::whereKey($data['retur_purchase_id'])->where('store_id', $storeId)->lockForUpdate()->firstOrFail();
            // Kunci Periode: pembelian asal di periode terkunci tidak boleh dikoreksi via retur.
            \App\Support\PeriodLock::check($storeId, $purchase->created_at, 'Tanggal pembelian asal');
            $item = $purchase->items()->whereKey($data['purchase_item_id'])->firstOrFail();
            $qty = min((int) $data['retur_qty'], (int) $item->qty);
            if ($qty <= 0) abort(422, 'Qty retur tidak valid.');
            $refund = $qty * (float) $item->price;

            $stock = ProductStock::where('store_id', $storeId)->where('product_id', $item->product_id)->lockForUpdate()->first();
            abort_if(!$stock || (int) $stock->qty < $qty, 422, 'Stok ' . ($item->product->name ?? 'barang') . ' tidak mencukupi untuk retur (sisa ' . (int) ($stock->qty ?? 0) . ', diminta ' . $qty . ').');
            $stock->decrement('qty', $qty);

            StockMovement::create([
                'store_id' => $storeId, 'product_id' => $item->product_id,
                'type' => 'keluar', 'qty' => $qty,
                'ref_type' => 'retur_beli', 'ref_id' => $purchase->id,
                'note' => 'Retur pembelian ' . $purchase->invoice_no,
                'user_id' => $request->user()?->id,
            ]);

            $newItemQty = (int) $item->qty - $qty;
            if ($newItemQty <= 0) {
                $item->delete();
            } else {
                $item->update(['qty' => $newItemQty, 'subtotal' => $newItemQty * (float) $item->price]);
            }

            $purchase->decrement('total', $refund);
            $purchase->refresh();
            // Kelebihan bayar akibat retur dikembalikan ke kas
            $overpaid = (float) $purchase->paid - (float) $purchase->total;
            if ($overpaid > 0) {
                // KEPUTUSAN (Continuous 168): retur = pengembalian dana supplier
                // ke kas (arus masuk, bukan kas keluar). Risiko fraud tetap ada
                // (kas bertambah fiktif / stok keluar), jadi SAMAKAN gate:
                // pengembalian > Rp10.000.000 hanya owner/admin/superadmin +
                // wajib foto bukti. Tanpa migration (marker [BUKTI:path]).
                if ($overpaid > 10000000) {
                    $roleNow = $request->user()?->role ?? auth()->user()->role ?? '';
                    if (! in_array($roleNow, ['superadmin', 'owner', 'admin'], true)) {
                        abort(422, 'Pengembalian dana retur di atas Rp10.000.000 hanya boleh diproses owner/admin/superadmin. Minta owner/admin/superadmin yang login lalu ulangi retur.');
                    }
                    if (! $request->hasFile('bukti')) {
                        abort(422, 'Pengembalian dana retur di atas Rp10.000.000 wajib melampirkan foto bukti (nota retur/transfer supplier, maks 2 MB).');
                    }
                }
                $account = CashAccount::whereKey($data['cash_account_id'])->where('store_id', $storeId)->lockForUpdate()->firstOrFail();
                $account->increment('balance', $overpaid);
                $purchase->decrement('paid', $overpaid);
                $returDesc = 'Retur pembelian ' . $purchase->invoice_no;
                if ($request->hasFile('bukti')) {
                    $f = $request->file('bukti');
                    if ($f && $f->isValid()) {
                        $returDesc .= ' [BUKTI:' . $f->store('bukti-kas', 'public') . ']';
                    }
                }
                CashTransaction::create([
                    'store_id' => $storeId, 'cash_account_id' => $account->id,
                    'type' => 'masuk', 'category' => 'pembelian', 'amount' => $overpaid,
                    'ref_type' => 'retur_beli', 'ref_id' => $purchase->id,
                    'description' => $returDesc,
                    'user_id' => $request->user()?->id,
                ]);
            }
            $purchase->refresh();
            $paid = (float) $purchase->paid; $total = (float) $purchase->total;
            $purchase->update(['status' => $total <= 0 ? 'lunas' : ($paid <= 0 ? 'belum' : ($paid >= $total ? 'lunas' : 'sebagian'))]);

            // Sesuaikan hutang terkait pembelian ini
            $debt = Debt::where('ref_type', 'purchase')->where('ref_id', $purchase->id)->first();
            if ($debt) {
                $newPaid = min((float) $debt->paid, $total);
                $debt->update([
                    'total' => $total, 'paid' => $newPaid, 'remaining' => max(0, $total - $newPaid),
                    'status' => $total <= 0 || $newPaid >= $total ? 'lunas' : ($newPaid <= 0 ? 'belum' : 'sebagian'),
                ]);
            }
            if ($purchase->supplier_id) {
                $supplier = Supplier::whereKey($purchase->supplier_id)->first();
                if ($supplier) $supplier->update(['hutang' => max(0, (float) $supplier->hutang - $refund)]);
            }
        });

        return redirect()->route('purchases.show', $data['retur_purchase_id'])->with('success', 'Retur pembelian tercatat (stok & hutang/kas disesuaikan).');
    }
}
