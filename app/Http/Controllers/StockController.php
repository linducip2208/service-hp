<?php

namespace App\Http\Controllers;

use App\Models\CashAccount;
use App\Models\Product;
use App\Models\ProductItem;
use App\Models\ProductStock;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Models\Store;
use App\Support\LookupCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockController extends Controller
{
    protected function authorizeInventory(): void
    {
        $role = auth()->user()->role ?? '';
        abort_unless(in_array($role, ['superadmin', 'owner', 'admin', 'gudang']), 403, 'Hanya admin/gudang/owner yang boleh mengelola stok.');
    }

    public function opname(Request $request)
    {
        $this->authorizeInventory();
        $storeId = (int) session('active_store_id');

        // Template CSV opname: ?template=csv (tanpa route baru)
        if ($request->get('template') === 'csv') {
            $file = 'template_opname.csv';
            return response()->streamDownload(function () {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['sku', 'qty', 'location'], ';');
                fputcsv($out, ['SKU-001', '12', 'Etalase A'], ';');
                fputcsv($out, ['SKU-002', '0', ''], ';');
                fclose($out);
            }, $file, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $locations = ProductStock::where('store_id', $storeId)
            ->whereNotNull('location')->distinct()->orderBy('location')->pluck('location');
        $stocks = ProductStock::with('product')
            ->where('store_id', $storeId)
            ->when($request->filled('q'), fn($q) => $q->whereHas('product', fn($p) => $p->where('name', 'like', '%' . request('q') . '%')))
            ->when($request->filled('location'), function ($q) {
                return request('location') === '__empty'
                    ? $q->whereNull('location')
                    : $q->where('location', request('location'));
            })
            ->orderByDesc('qty')->paginate(50)->withQueryString();
        $movements = StockMovement::with('product')
            ->where('store_id', $storeId)->where('type', 'opname')
            ->orderByDesc('id')->limit(30)->get();
        return view('stocks.opname', compact('stocks', 'movements', 'locations'));
    }

    public function opnameStore(Request $request)
    {
        $this->authorizeInventory();
        $storeId = (int) session('active_store_id');

        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.qty' => 'required|integer|min:0',
            'items.*.location' => 'nullable|string|max:100',
            // Min. stok per toko (nullable = ikut global products.min_stock).
            'items.*.min_stock' => 'nullable|integer|min:0|max:1000000',
            'note' => 'nullable|string|max:500',
        ]);

        DB::transaction(function () use ($data, $storeId) {
            foreach ($data['items'] as $row) {
                $stock = ProductStock::firstOrCreate(
                    ['store_id' => $storeId, 'product_id' => $row['product_id']], ['qty' => 0]
                );
                $old = (int) $stock->qty;
                $new = (int) $row['qty'];
                $oldLoc = (string) ($stock->location ?? '');
                $newLoc = trim((string) ($row['location'] ?? ''));
                $newLoc = $newLoc === '' ? null : mb_substr($newLoc, 0, 100);
                // Min toko: input kosong = NULL (ikut global); angka = override per toko.
                $newMin = ($row['min_stock'] ?? null) === null || $row['min_stock'] === ''
                    ? null : (int) $row['min_stock'];
                $oldMin = $stock->min_stock !== null ? (int) $stock->min_stock : null;
                if ($oldMin !== $newMin) $stock->min_stock = $newMin;
                $minChanged = $oldMin !== $newMin;
                if ($old === $new && $oldLoc === (string) ($newLoc ?? '') && !$minChanged) continue;
                $stock->qty = $new;
                $stock->location = $newLoc;
                $stock->save();
                if ($old === $new && $oldLoc === (string) ($newLoc ?? '')) continue; // hanya min berubah: tanpa movement
                if ($old === $new) { // hanya pindah lokasi: jejak qty 0 agar tak merusak agregasi
                    StockMovement::create([
                        'store_id' => $storeId, 'product_id' => $row['product_id'],
                        'type' => 'opname', 'qty' => 0,
                        'ref_type' => 'opname', 'ref_id' => null,
                        'note' => ($data['note'] ?? 'Opname') . " — pindah lokasi '{$oldLoc}' → '" . ($newLoc ?? '') . "' (qty tetap {$new})",
                        'user_id' => auth()->id(),
                    ]);
                    continue;
                }
                StockMovement::create([
                    'store_id' => $storeId, 'product_id' => $row['product_id'],
                    'type' => 'opname', 'qty' => abs($new - $old),
                    'ref_type' => 'opname', 'ref_id' => null,
                    'note' => ($data['note'] ?? 'Opname') . " ({$old} → {$new})",
                    'user_id' => auth()->id(),
                ]);
            }
        });

        return back()->with('success', 'Stok opname berhasil disimpan.');
    }

    /** Import CSV opname: header sku,qty[,location], delimiter auto ;/,
     *  cocokkan SKU → terapkan langsung sbg opname (transaction +
     *  movement opname per baris berubah). Scope toko aktif. */
    public function opnameImport(Request $request)
    {
        $this->authorizeInventory();
        $storeId = (int) session('active_store_id');
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:2048',
            'note' => 'nullable|string|max:500',
        ]);
        $note = trim((string) $request->input('note', 'Opname via CSV'));
        $note = $note === '' ? 'Opname via CSV' : mb_substr($note, 0, 500);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        if (!$handle) {
            return back()->with('error', 'File CSV tidak bisa dibaca.');
        }
        // Deteksi delimiter ala ProductController@import: ';' (Excel Indonesia) vs ','.
        $firstLine = fgets($handle) ?: '';
        rewind($handle);
        $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';
        $readCsv = function () use ($handle, $delimiter) {
            $cols = fgetcsv($handle, 0, $delimiter);
            if (is_array($cols)) {
                $cols = array_map(fn($c) => is_string($c) ? ltrim($c, "\xEF\xBB\xBF") : $c, $cols);
            }
            return $cols;
        };
        $header = array_map(fn($h) => strtolower(trim((string) $h)), $readCsv() ?: []);
        if (!in_array('sku', $header, true) || !in_array('qty', $header, true)) {
            fclose($handle);
            return back()->with('error', 'Header CSV wajib memuat kolom: sku,qty[,location].');
        }

        // Kumpulkan per SKU (duplikat: baris terakhir menang), validasi ringan per baris.
        $wanted = []; // skuUpper => ['line'=>, 'qtyRaw'=>, 'location'=>]
        $fail = [];
        $line = 1;
        while (($cols = $readCsv()) !== false) {
            $line++;
            if (count($cols) === 1 && trim((string) $cols[0]) === '') continue;
            $row = array_combine(
                array_slice(array_merge($header, range(0, count($cols) - 1)), 0, count($cols)),
                $cols
            );
            $g = fn($k) => trim((string) ($row[$k] ?? ''));
            $sku = $g('sku');
            $qtyRaw = $g('qty');
            if ($sku === '') { $fail[] = "Baris $line: sku kosong"; continue; }
            if ($qtyRaw === '' || !ctype_digit($qtyRaw)) { $fail[] = "Baris $line ($sku): qty harus bilangan bulat >= 0"; continue; }
            if (mb_strlen($g('location')) > 100) { $fail[] = "Baris $line ($sku): location maks 100 karakter"; continue; }
            $wanted[mb_strtoupper($sku)] = ['line' => $line, 'sku' => $sku, 'qty' => (int) $qtyRaw, 'location' => $g('location')];
        }
        fclose($handle);

        if (empty($wanted)) {
            return back()->with('error', 'Tidak ada baris valid. ' . implode('; ', array_slice($fail, 0, 5)));
        }

        // Cocokkan SKU → product (case-insensitive).
        $skus = collect($wanted)->pluck('sku')->values();
        $products = Product::whereIn('sku', $skus->all())
            ->orWhereIn('sku', $skus->map(fn($s) => mb_strtoupper($s))->all())
            ->get()->keyBy(fn($p) => mb_strtoupper($p->sku));
        // Fallback satu-per-satu untuk SKU yang belum ketemu (beda case / whitespace).
        foreach ($wanted as $key => $w) {
            if (!isset($products[$key])) {
                $p = Product::where('sku', $w['sku'])->first()
                    ?? Product::whereRaw('UPPER(sku) = ?', [$key])->first();
                if ($p) $products[$key] = $p;
            }
        }

        $items = []; // siap terapkan
        foreach ($wanted as $key => $w) {
            $p = $products[$key] ?? null;
            if (!$p) { $fail[] = "Baris {$w['line']} ({$w['sku']}): SKU tidak ditemukan"; continue; }
            $items[] = [
                'line' => $w['line'],
                'product_id' => $p->id,
                'sku' => $p->sku,
                'qty' => $w['qty'],
                'location' => $w['location'],
            ];
        }

        $applied = 0; $skipped = 0;
        try {
            DB::transaction(function () use ($items, $storeId, $note, &$applied, &$skipped) {
                foreach ($items as $row) {
                    $stock = ProductStock::lockForUpdate()
                        ->where('store_id', $storeId)
                        ->where('product_id', $row['product_id'])
                        ->first();
                    if (!$stock) {
                        $stock = ProductStock::create([
                            'store_id' => $storeId, 'product_id' => $row['product_id'],
                            'qty' => 0, 'location' => null,
                        ]);
                    }
                    $old = (int) $stock->qty;
                    $new = (int) $row['qty'];
                    $oldLoc = (string) ($stock->location ?? '');
                    $newLoc = trim((string) ($row['location'] ?? ''));
                    // location kosong di CSV = jangan ubah lokasi (biar aman thd export parsial).
                    // Untuk mengosongkan lokasi, pakai form manual.
                    if ($newLoc === '' && ($stock->location ?? '') !== null) {
                        $newLoc = $oldLoc;
                    } else {
                        $newLoc = $newLoc === '' ? null : mb_substr($newLoc, 0, 100);
                    }
                    if ($old === $new && $oldLoc === (string) ($newLoc ?? '')) { $skipped++; continue; }
                    $stock->update(['qty' => $new, 'location' => $newLoc]);
                    $applied++;
                    if ($old === $new) { // hanya pindah lokasi: jejak qty 0 agar tak merusak agregasi
                        StockMovement::create([
                            'store_id' => $storeId, 'product_id' => $row['product_id'],
                            'type' => 'opname', 'qty' => 0,
                            'ref_type' => 'opname', 'ref_id' => null,
                            'note' => $note . " — pindah lokasi '{$oldLoc}' → '" . ($newLoc ?? '') . "' (qty tetap {$new})",
                            'user_id' => auth()->id(),
                        ]);
                        continue;
                    }
                    StockMovement::create([
                        'store_id' => $storeId, 'product_id' => $row['product_id'],
                        'type' => 'opname', 'qty' => abs($new - $old),
                        'ref_type' => 'opname', 'ref_id' => null,
                        'note' => $note . " ({$old} → {$new})",
                        'user_id' => auth()->id(),
                    ]);
                }
            });
        } catch (\Throwable $e) {
            return back()->with('error', 'Import opname gagal: ' . $e->getMessage());
        }

        $fail = array_slice($fail, 0, 100);
        return back()
            ->with('success', "Import opname selesai: $applied baris diterapkan, $skipped sama (dilewati), " . count($fail) . ' gagal.')
            ->with('import_fail', $fail);
    }

    public function mutations(Request $request)    {
        $this->authorizeInventory();
        $storeId = (int) session('active_store_id');
        $stores = LookupCache::activeStores()->where('id', '!=', $storeId)->values();
        // Filter riwayat: ?type=mutasi_masuk|mutasi_keluar, ?store_id=toko lawan,
        // ?from=YYYY-MM-DD, ?to=YYYY-MM-DD, ?q=nama/sku/batch.
        $query = StockMovement::with(['product', 'fromStore', 'toStore'])
            ->where(function ($w) use ($storeId) {
                $w->where('store_id', $storeId)->orWhere('from_store_id', $storeId)->orWhere('to_store_id', $storeId);
            })
            ->whereIn('type', ['mutasi_masuk', 'mutasi_keluar'])
            ->when(
                $request->filled('type') && in_array($request->type, ['mutasi_masuk', 'mutasi_keluar'], true),
                fn($q) => $q->where('type', $request->type)
            )
            ->when(
                $request->filled('store_id') && is_numeric($request->store_id),
                fn($q) => $q->where(function ($w) use ($request) {
                    $w->where('from_store_id', (int) $request->store_id)
                        ->orWhere('to_store_id', (int) $request->store_id);
                })
            )
            ->when($request->filled('from'), fn($q) => $q->whereDate('created_at', '>=', $request->from))
            ->when($request->filled('to'), fn($q) => $q->whereDate('created_at', '<=', $request->to))
            ->when($request->filled('q'), function ($q) use ($request) {
                $s = '%' . $request->q . '%';
                $q->where(function ($w) use ($s) {
                    $w->where('note', 'like', $s)
                        ->orWhereHas('product', fn($p) => $p->where('name', 'like', $s)->orWhere('sku', 'like', $s));
                });
            })
            ->orderByDesc('id');

        // Export CSV (?export=csv) — hormati filter toko/periode/tipe/q di atas,
        // pola valuation/deadstock (delimiter ; + BOM, streamDownload).
        if ($request->get('export') === 'csv') {
            $rows = (clone $query)->with(['product', 'productItem', 'fromStore', 'toStore'])->limit(5000)->get();
            $file = 'riwayat_mutasi_' . now()->format('Ymd_His') . '.csv';
            return response()->streamDownload(function () use ($rows) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['id', 'tanggal', 'produk', 'sku', 'imei', 'tipe', 'dari', 'ke', 'qty', 'batch', 'catatan'], ';');
                foreach ($rows as $m) {
                    $batch = self::extractBatchCode((string) ($m->note ?? '')) ?? '';
                    fputcsv($out, [
                        $m->id,
                        $m->created_at ? $m->created_at->format('Y-m-d H:i') : '',
                        $m->product->name ?? '',
                        $m->product->sku ?? '',
                        $m->productItem->imei ?? '',
                        $m->type ?? '',
                        $m->fromStore->name ?? '',
                        $m->toStore->name ?? '',
                        (int) $m->qty,
                        $batch,
                        (string) ($m->note ?? ''),
                    ], ';');
                }
                fclose($out);
            }, $file, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $movements = (clone $query)->limit(50)->get();
        // Kelompokkan riwayat per kode batch (die ekstrak dari note "[batch TRM-...]")
        // agar tiap batch punya satu tombol cetak surat jalan.
        $batches = [];
        foreach ($movements as $m) {
            $code = self::extractBatchCode((string) ($m->note ?? ''));
            if ($code === null) {
                continue;
            }
            if (!isset($batches[$code])) {
                $batches[$code] = [
                    'code' => $code,
                    'created_at' => $m->created_at,
                    'from' => $m->fromStore->name ?? '?',
                    'to' => $m->toStore->name ?? '?',
                    'count' => 0,
                ];
            }
            $batches[$code]['count']++;
            if ($m->created_at && $batches[$code]['created_at'] && $m->created_at->lt($batches[$code]['created_at'])) {
                $batches[$code]['created_at'] = $m->created_at;
            }
        }
        $batches = collect($batches)->sortByDesc('created_at')->values();
        $stocks = ProductStock::with('product')->where('store_id', $storeId)->where('qty', '>', 0)->orderBy('product_id')->get();
        $units = ProductItem::with('product')->where('store_id', $storeId)->where('status', 'tersedia')->orderByDesc('id')->limit(200)->get();
        $filterStores = LookupCache::activeStores()->values();
        return view('stocks.mutations', compact('stores', 'movements', 'batches', 'stocks', 'units', 'filterStores'));
    }

    /**
     * Surat jalan per batch mutasi (1 route GET dengan ?batch=TRM-...).
     * Tanpa migration: batch diekstrak dari note "[batch TRM-...]".
     * Hanya baris mutasi_keluar yang dihitung agar tidak ganda (keluar+masuk).
     */
    public function suratJalan(Request $request)
    {
        $this->authorizeInventory();
        $batch = trim((string) $request->query('batch', ''));
        abort_unless(
            preg_match('/^TRM-\d{14}-[A-Z0-9]{6}$/', $batch) === 1,
            422,
            'Kode batch tidak valid. Contoh: TRM-20240101120000-ABC123.'
        );

        $rows = StockMovement::with(['product', 'productItem.product', 'fromStore', 'toStore', 'user'])
            ->where('ref_type', 'mutasi')
            ->where('note', 'like', '%[batch ' . $batch . ']%')
            ->where('type', 'mutasi_keluar')
            ->orderBy('product_id')
            ->orderBy('product_item_id')
            ->get();
        abort_if($rows->isEmpty(), 404, 'Batch ' . $batch . ' tidak ditemukan.');

        $first = $rows->first();
        $items = [];
        $units = [];
        foreach ($rows as $r) {
            if ($r->product_item_id) {
                $units[] = [
                    'imei' => $r->productItem->imei ?? ('#' . $r->product_item_id),
                    'product' => $r->productItem->product->name ?? ($r->product->name ?? '-'),
                    'sku' => $r->productItem->product->sku ?? ($r->product->sku ?? '-'),
                ];
                continue;
            }
            $pid = (int) $r->product_id;
            if (!isset($items[$pid])) {
                $items[$pid] = [
                    'sku' => $r->product->sku ?? '-',
                    'name' => $r->product->name ?? ('#' . $pid),
                    'qty' => 0,
                ];
            }
            $items[$pid]['qty'] += (int) $r->qty;
        }
        $items = collect($items)->values();
        $units = collect($units)->sortBy('imei')->values();

        $note = trim((string) preg_replace(
            '/\s*\[batch\s+' . preg_quote($batch, '/') . '\]\s*/',
            '',
            (string) ($first->note ?? '')
        ));

        return view('stocks.surat_jalan', [
            'batch' => $batch,
            'tanggal' => $rows->min('created_at'),
            'fromStore' => $first->fromStore,
            'toStore' => $first->toStore,
            'items' => $items,
            'units' => $units,
            'totalQty' => $items->sum('qty'),
            'note' => $note,
            'dibuatOleh' => $first->user->name ?? '-',
        ]);
    }

    /**
     * Ekstrak kode batch dari note mutasi. Format ditulis mutate():
     * "... [batch TRM-YmdHis-XXXXXX]".
     */
    private static function extractBatchCode(string $note): ?string
    {
        if (preg_match('/\[batch\s+(TRM-\d{14}-[A-Z0-9]{6})\]/', $note, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    public function mutate(Request $request)
    {
        $this->authorizeInventory();
        $fromStore = (int) session('active_store_id');

        $data = $request->validate([
            'to_store_id' => 'required|exists:stores,id|different:from_store_id',
            'from_store_id' => 'nullable|integer',
            'items' => 'nullable|array|max:100',
            'items.*.product_id' => 'required_with:items|exists:products,id',
            'items.*.qty' => 'required_with:items|integer|min:1|max:100000',
            'unit_ids' => 'nullable|array|max:200',
            'unit_ids.*' => 'exists:product_items,id',
            'note' => 'nullable|string|max:500',
        ], [
            'items.max' => 'Maksimal 100 baris produk per transfer.',
            'items.*.qty.min' => 'Qty tiap baris minimal 1.',
            'items.*.qty.max' => 'Qty tiap baris maksimal 100.000.',
            'unit_ids.max' => 'Maksimal 200 unit per transfer.',
        ]);
        abort_if((int) $data['to_store_id'] === $fromStore, 422, 'Toko tujuan harus berbeda.');
        abort_if(!$fromStore, 422, 'Toko asal belum dipilih.');
        abort_unless(
            Store::whereKey((int) $data['to_store_id'])->where('is_active', true)->exists(),
            422,
            'Toko tujuan tidak aktif.'
        );
        // Normalisasi: buang baris kosong (tanpa product_id), gabung duplikat per produk.
        $rawItems = collect($data['items'] ?? [])
            ->filter(fn($r) => !empty($r['product_id']) && (int) ($r['qty'] ?? 0) > 0)
            ->values();
        abort_if($rawItems->isEmpty() && empty($data['unit_ids']), 422, 'Pilih minimal satu produk atau unit.');
        $merged = $rawItems->groupBy('product_id')->map(fn($g, $pid) => [
            'product_id' => (int) $pid,
            'qty' => (int) $g->sum('qty'),
        ])->values();
        abort_if($merged->count() > 100, 422, 'Maksimal 100 jenis produk per transfer.');
        // Pra-validasi stok cukup per baris (pesan menyebut nama produk + sisa).
        $stockMap = ProductStock::where('store_id', $fromStore)
            ->whereIn('product_id', $merged->pluck('product_id'))
            ->pluck('qty', 'product_id');
        $names = Product::whereIn('id', $merged->pluck('product_id'))->pluck('name', 'id');
        $kurang = [];
        foreach ($merged as $row) {
            $sisa = (int) ($stockMap[$row['product_id']] ?? 0);
            if ($sisa < $row['qty']) {
                $kurang[] = ($names[$row['product_id']] ?? ('#'.$row['product_id']))
                    . " (minta {$row['qty']}, sisa {$sisa})";
            }
        }
        abort_if(!empty($kurang), 422, 'Stok asal tidak mencukupi: ' . implode('; ', $kurang));
        // Unit: hilangkan duplikat, pra-cek milik toko aktif + tersedia.
        $unitIds = collect($data['unit_ids'] ?? [])->map(fn($v) => (int) $v)->unique()->values();
        if ($unitIds->isNotEmpty()) {
            $badUnits = ProductItem::whereIn('id', $unitIds)->get()
                ->filter(fn($u) => (int) $u->store_id !== $fromStore || $u->status !== 'tersedia')
                ->map(fn($u) => ($u->imei ?: '#'.$u->id))->values()->all();
            abort_if(!empty($badUnits), 422, 'Unit tidak bisa dipindah (bukan milik toko aktif / tidak tersedia): ' . implode(', ', $badUnits));
        }

        $batch = 'TRM-' . now()->format('YmdHis') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));
        $result = ['jenis' => 0, 'qty' => 0, 'units' => 0];

        DB::transaction(function () use ($merged, $unitIds, $data, $fromStore, $batch, &$result) {
            $to = (int) $data['to_store_id'];
            $noteRaw = trim((string) ($data['note'] ?? ''));
            $note = ($noteRaw === '' ? 'Mutasi antar toko' : mb_substr($noteRaw, 0, 400))
                . " [batch {$batch}]";

            // Kunci berurutan per product_id agar aman dari deadlock.
            foreach ($merged->sortBy('product_id')->values() as $row) {
                $from = ProductStock::where('store_id', $fromStore)->where('product_id', $row['product_id'])->lockForUpdate()->first();
                abort_if(!$from || $from->qty < (int) $row['qty'], 422, 'Stok asal tidak mencukupi untuk ' . ($row['product_id']) . '.');
                $from->decrement('qty', (int) $row['qty']);
                $dest = ProductStock::firstOrCreate(['store_id' => $to, 'product_id' => $row['product_id']], ['qty' => 0]);
                $dest->increment('qty', (int) $row['qty']);
                StockMovement::create([
                    'store_id' => $fromStore, 'product_id' => $row['product_id'], 'type' => 'mutasi_keluar',
                    'qty' => $row['qty'], 'from_store_id' => $fromStore, 'to_store_id' => $to,
                    'ref_type' => 'mutasi', 'note' => $note, 'user_id' => auth()->id(),
                ]);
                StockMovement::create([
                    'store_id' => $to, 'product_id' => $row['product_id'], 'type' => 'mutasi_masuk',
                    'qty' => $row['qty'], 'from_store_id' => $fromStore, 'to_store_id' => $to,
                    'ref_type' => 'mutasi', 'note' => $note, 'user_id' => auth()->id(),
                ]);
            }
            $result['jenis'] = $merged->count();
            $result['qty'] = (int) $merged->sum('qty');

            foreach ($unitIds as $unitId) {
                $unit = ProductItem::lockForUpdate()->findOrFail($unitId);
                abort_if((int) $unit->store_id !== $fromStore, 422, 'Unit ' . ($unit->imei ?: "#{$unit->id}") . ' bukan milik toko aktif.');
                abort_if($unit->status !== 'tersedia', 422, 'Unit ' . ($unit->imei ?: "#{$unit->id}") . ' tidak tersedia.');
                $unit->update(['store_id' => $to]);
                StockMovement::create([
                    'store_id' => $fromStore, 'product_id' => $unit->product_id, 'product_item_id' => $unit->id,
                    'type' => 'mutasi_keluar', 'qty' => 1, 'from_store_id' => $fromStore, 'to_store_id' => $to,
                    'ref_type' => 'mutasi', 'note' => $note, 'user_id' => auth()->id(),
                ]);
                StockMovement::create([
                    'store_id' => $to, 'product_id' => $unit->product_id, 'product_item_id' => $unit->id,
                    'type' => 'mutasi_masuk', 'qty' => 1, 'from_store_id' => $fromStore, 'to_store_id' => $to,
                    'ref_type' => 'mutasi', 'note' => $note, 'user_id' => auth()->id(),
                ]);
            }
            $result['units'] = $unitIds->count();
        });

        return back()->with('success', "Mutasi {$batch} berhasil: {$result['jenis']} jenis produk ({$result['qty']} pcs) + {$result['units']} unit IMEI.");
    }

    /** Nilai persediaan toko aktif: rekap per tipe + per kategori
     *  (qty + nilai modal + nilai jual + potensi profit) + total +
     *  perbandingan dengan kas. Mendukung ?q=&type=, ?export=csv. Tanpa migration. */
    public function valuation(Request $request)
    {
        $this->authorizeInventory();
        $storeId = (int) session('active_store_id');
        try {
            $stocks = ProductStock::with(['product.brand', 'product.category'])
                ->where('store_id', $storeId)
                ->where('qty', '>', 0)
                ->whereHas('product', function ($q) use ($request) {
                    if ($request->filled('q')) {
                        $qq = '%' . $request->q . '%';
                        $q->where(function ($w) use ($qq) {
                            $w->where('name', 'like', $qq)->orWhere('sku', 'like', $qq);
                        });
                    }
                    if ($request->filled('type')) {
                        $q->where('type', $request->type);
                    }
                })
                ->get();

            $rows = [];
            foreach ($stocks as $s) {
                $p = $s->product;
                if (!$p) continue;
                $qty = (int) $s->qty;
                $beli = (float) ($p->purchase_price ?? 0);
                $jual = (float) ($p->selling_price ?? 0);
                $rows[] = [
                    'product_id' => $p->id,
                    'sku' => $p->sku,
                    'name' => $p->name,
                    'brand' => $p->brand->name ?? '-',
                    'category' => $p->category->name ?? '-',
                    'type' => $p->type ?? '-',
                    'qty' => $qty,
                    'purchase_price' => $beli,
                    'selling_price' => $jual,
                    'nilai_modal' => $qty * $beli,
                    'nilai_jual' => $qty * $jual,
                    'profit' => $qty * ($jual - $beli),
                ];
            }
            usort($rows, fn($a, $b) => $b['nilai_modal'] <=> $a['nilai_modal']);

            $sum = fn($key) => collect($rows)->sum($key);
            $total = [
                'qty' => $sum('qty'),
                'modal' => $sum('nilai_modal'),
                'jual' => $sum('nilai_jual'),
                'profit' => $sum('nilai_jual') - $sum('nilai_modal'),
                'count' => count($rows),
            ];

            $group = function (string $key) use ($rows) {
                $g = [];
                foreach ($rows as $r) {
                    $k = $r[$key] ?: '-';
                    $g[$k] ??= ['label' => $k, 'qty' => 0, 'modal' => 0, 'jual' => 0, 'profit' => 0, 'count' => 0];
                    $g[$k]['qty'] += $r['qty'];
                    $g[$k]['modal'] += $r['nilai_modal'];
                    $g[$k]['jual'] += $r['nilai_jual'];
                    $g[$k]['profit'] += $r['profit'];
                    $g[$k]['count']++;
                }
                uasort($g, fn($a, $b) => $b['modal'] <=> $a['modal']);
                return array_values($g);
            };
            $byType = $group('type');
            $byCategory = $group('category');

            $kas = (float) CashAccount::where('store_id', $storeId)->sum('balance');
            $storeName = \App\Models\Store::whereKey($storeId)->value('name') ?? ('#' . $storeId);

            if ($request->get('export') === 'csv') {
                $file = 'valuasi_stok_' . now()->format('Ymd_His') . '.csv';
                return response()->streamDownload(function () use ($rows, $total) {
                    $out = fopen('php://output', 'w');
                    fwrite($out, "\xEF\xBB\xBF");
                    fputcsv($out, ['sku', 'nama', 'brand', 'kategori', 'tipe', 'qty', 'harga_beli', 'harga_jual', 'nilai_modal', 'nilai_jual', 'potensi_profit'], ';');
                    foreach ($rows as $r) {
                        fputcsv($out, [
                            $r['sku'], $r['name'], $r['brand'], $r['category'], $r['type'],
                            $r['qty'], $r['purchase_price'], $r['selling_price'],
                            $r['nilai_modal'], $r['nilai_jual'], $r['profit'],
                        ], ';');
                    }
                    fputcsv($out, ['TOTAL', $total['count'] . ' produk', '', '', '', $total['qty'], '', '', $total['modal'], $total['jual'], $total['profit']], ';');
                    fclose($out);
                }, $file, ['Content-Type' => 'text/csv; charset=UTF-8']);
            }

            return view('stocks.valuation', compact('rows', 'total', 'byType', 'byCategory', 'kas', 'storeName'));
        } catch (\Throwable $e) {
            return back()->with('error', 'Gagal menghitung valuasi persediaan: ' . $e->getMessage());
        }
    }

    /** Laporan stok mati: produk aktif toko dengan qty>0 yang tidak ada
     *  mutasi keluar/penjualan (keluar, mutasi_keluar, pakai_service) selama ?days= (default 90).
     *  Tanpa migration. Mendukung ?export=csv. */
    public function deadstock(Request $request)
    {
        $this->authorizeInventory();
        $storeId = (int) session('active_store_id');
        $days = (int) $request->get('days', 90);
        $days = max(1, min(3650, $days ?: 90));
        $cutoff = now()->subDays($days);

        $stocks = ProductStock::with(['product.brand'])
            ->where('store_id', $storeId)
            ->where('qty', '>', 0)
            ->whereHas('product', function ($q) use ($request) {
                $q->where('is_active', true);
                if ($request->filled('q')) {
                    $qq = '%' . $request->q . '%';
                    $q->where(function ($w) use ($qq) {
                        $w->where('name', 'like', $qq)->orWhere('sku', 'like', $qq);
                    });
                }
                if ($request->filled('type')) {
                    $q->where('type', $request->type);
                }
            })
            ->get();

        $pids = $stocks->map(fn($s) => $s->product_id)->unique()->values();
        $lastOut = collect();
        $soldPids = collect();
        if ($pids->isNotEmpty()) {
            $lastOut = StockMovement::query()
                ->where('store_id', $storeId)
                ->whereIn('product_id', $pids)
                ->whereIn('type', ['keluar', 'mutasi_keluar', 'pakai_service'])
                ->selectRaw('product_id, MAX(created_at) as last_at')
                ->groupBy('product_id')
                ->pluck('last_at', 'product_id');
            // Produk tak pernah laku = product_id tidak punya baris sale_items
            // pada penjualan (sales) toko aktif — tanpa batas hari.
            $soldPids = SaleItem::query()
                ->whereIn('product_id', $pids)
                ->whereHas('sale', fn($q) => $q->where('store_id', $storeId))
                ->distinct()
                ->pluck('product_id');
        }
        $soldSet = $soldPids->flip();
        $neverSoldOnly = $request->boolean('never_sold');

        $rows = [];
        foreach ($stocks as $s) {
            $p = $s->product;
            if (!$p) continue;
            $lastAt = $lastOut[$s->product_id] ?? null;
            $lastCarbon = $lastAt ? \Carbon\Carbon::parse($lastAt) : null;
            // Deadstock bila tidak pernah keluar ATAU gerakan keluar terakhir <= cutoff
            if ($lastCarbon && $lastCarbon->gt($cutoff)) continue;
            $stuckDays = $lastCarbon
                ? (int) $lastCarbon->diffInDays(now())
                : (int) $p->created_at->diffInDays(now());
            $price = (float) ($p->purchase_price ?? 0);
            $nilai = (int) $s->qty * $price;
            $rows[] = [
                'product_id' => $p->id,
                'sku' => $p->sku,
                'name' => $p->name,
                'brand' => $p->brand->name ?? '-',
                'type' => $p->type,
                'qty' => (int) $s->qty,
                'purchase_price' => $price,
                'selling_price' => (float) ($p->selling_price ?? 0),
                'nilai' => $nilai,
                'last_at' => $lastCarbon,
                'stuck_days' => $stuckDays,
                'never' => $lastCarbon === null,
                'never_sold' => !isset($soldSet[$s->product_id]),
                'saran' => $this->deadstockSaran($stuckDays, $p->type, $nilai),
            ];
        }

        // Toggle "tak pernah terjual sama sekali": saring ke produk yang
        // tidak punya baris sale_items di toko aktif (tanpa batas hari).
        if ($neverSoldOnly) {
            $rows = array_values(array_filter($rows, fn($r) => $r['never_sold']));
        }

        usort($rows, fn($a, $b) => $b['nilai'] <=> $a['nilai']);
        $totalNilai = collect($rows)->sum('nilai');
        $storeName = \App\Models\Store::whereKey($storeId)->value('name') ?? ('#' . $storeId);

        if ($request->get('export') === 'csv') {
            $file = 'stok_mati_' . $days . 'hari_' . now()->format('Ymd_His') . '.csv';
            return response()->streamDownload(function () use ($rows, $days) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['sku', 'nama', 'brand', 'tipe', 'stok', 'harga_beli', 'nilai_modal', 'gerak_keluar_terakhir', 'hari_diam_' . $days, 'saran'], ';');
                foreach ($rows as $r) {
                    fputcsv($out, [
                        $r['sku'], $r['name'], $r['brand'], $r['type'], $r['qty'],
                        $r['purchase_price'], $r['nilai'],
                        $r['last_at'] ? $r['last_at']->format('Y-m-d H:i') : 'tidak pernah',
                        $r['stuck_days'], $r['saran'],
                    ], ';');
                }
                fclose($out);
            }, $file, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return view('stocks.deadstock', compact('rows', 'days', 'totalNilai', 'storeName', 'neverSoldOnly'));
    }

    private function deadstockSaran(int $stuckDays, ?string $type, float $nilai): string
    {
        if ($stuckDays >= 180) {
            return 'Obral + bundel, retur supplier bila bisa';
        }
        if (in_array($type, ['aksesoris', 'sparepart'], true)) {
            return 'Obral / bundel dengan HP';
        }
        if ($nilai >= 2000000) {
            return 'Bundel + display depan, retur supplier bila bisa';
        }
        return 'Obral / bundel';
    }
}
