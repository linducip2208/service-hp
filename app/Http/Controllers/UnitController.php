<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductItem;
use App\Models\ProductStock;
use App\Models\SaleItem;
use App\Models\SecondhandPurchase;
use App\Models\ServiceTicket;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Support\LookupCache;
use App\Models\TradeIn;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UnitController extends Controller
{
    public function index(Request $request)
    {
        $storeId = (int) session('active_store_id');
        $showAll = $request->boolean('all') && (auth()->user()->role ?? '') === 'superadmin';
        $base = ProductItem::with(['product.brand', 'store', 'supplier'])
            ->when(!$showAll && $storeId, fn($q) => $q->where('product_items.store_id', $storeId))
            ->when($request->filled('status'), fn($q) => $q->where('product_items.status', $request->status))
            ->when($request->filled('price_min'), fn($q) => $q->where('product_items.selling_price', '>=', (float) $request->price_min))
            ->when($request->filled('price_max'), fn($q) => $q->where('product_items.selling_price', '<=', (float) $request->price_max))
            ->when($request->filled('q'), fn($q) => $q->where(function ($w) {
                $w->where('imei', 'like', '%' . request('q') . '%')
                  ->orWhere('serial', 'like', '%' . request('q') . '%')
                  ->orWhereHas('product', fn($p) => $p->where('name', 'like', '%' . request('q') . '%'));
            }))
            ->when($request->query('sort') === 'age',
                fn($q) => $q->orderBy('product_items.created_at', 'asc')->orderBy('product_items.id', 'asc'),
                fn($q) => $q->orderByDesc('product_items.id'));
        // Export CSV (?export=csv) — hormati filter q/status/sort + scope toko, pola ProductController@csvStream
        if ($request->get('export') === 'csv') {
            $rows = (clone $base)->limit(5000)->get();
            $now = now();
            $filename = 'units_toko_' . ($showAll ? 'semua' : ($storeId ?: 'semua')) . '_' . now()->format('Ymd_His') . '.csv';
            return response()->streamDownload(function () use ($rows, $now) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['imei', 'serial', 'produk', 'brand', 'kondisi', 'harga_beli', 'harga_jual', 'status', 'toko', 'umur_hari'], ';');
                foreach ($rows as $u) {
                    fputcsv($out, [
                        $u->imei ?? '', $u->serial ?? '', $u->product->name ?? '',
                        $u->product->brand->name ?? '', $u->condition ?? '',
                        (float) $u->purchase_price, (float) $u->selling_price,
                        $u->status ?? '', $u->store->name ?? '',
                        $u->created_at ? (int) $now->diffInDays($u->created_at) : 0,
                    ], ';');
                }
                fclose($out);
            }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }
        $units = $base->paginate(25)->withQueryString();
        $products = Product::where('is_serialized', true)->where('is_active', true)->orderBy('name')->limit(300)->get();
        $suppliers = Supplier::when(!$showAll && $storeId, fn($q) => $q->where('store_id', $storeId))->orderBy('name')->get();
        $lowSpareparts = Product::query()
            ->withSum(['stocks as stock_total' => fn($q) => $q->when($storeId, fn($qq) => $qq->where('store_id', $storeId))], 'qty')
            ->where('type', 'sparepart')->where('is_active', true)
            ->orderBy('min_stock')->limit(100)->get();
        // Min EFEKTIF toko aktif (override per toko bila ada, kalau NULL ikut global).
        $unitMinMap = $storeId
            ? ProductStock::where('store_id', $storeId)->whereNotNull('min_stock')->pluck('min_stock', 'product_id')
            : collect();
        $lowSpareparts = $lowSpareparts
            ->filter(fn($p) => (int) ($p->stock_total ?? 0) <= (int) ($unitMinMap->get($p->id, $p->min_stock ?? 0)));
        $moveStores = LookupCache::activeStores()->where('id', '!=', $storeId)->values();
        return view('units.index', compact('units', 'products', 'suppliers', 'showAll', 'lowSpareparts', 'moveStores'));
    }

    public function store(Request $request)
    {
        $storeId = (int) session('active_store_id');
        abort_if(!$storeId, 422, 'Toko aktif belum dipilih.');

        $data = $request->validate([
            'product_id' => 'required|exists:products,id',
            'imei' => 'nullable|string|max:50|unique:product_items,imei',
            'serial' => 'nullable|string|max:100',
            'condition' => 'required|in:baru,bekas,refurbished,konsinyasi',
            'purchase_price' => 'required|numeric|min:0',
            'selling_price' => 'required|numeric|min:0',
            'status' => 'nullable|in:tersedia,terjual,service,mutasi,retur,konsinyasi',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'warranty_months' => 'nullable|integer|min:0|max:60',
        ]);

        DB::transaction(function () use ($data, $storeId) {
            $unit = ProductItem::create([
                'product_id' => $data['product_id'], 'store_id' => $storeId,
                'imei' => $data['imei'] ?? null, 'serial' => $data['serial'] ?? null,
                'condition' => $data['condition'],
                'purchase_price' => $data['purchase_price'], 'selling_price' => $data['selling_price'],
                'status' => $data['status'] ?? 'tersedia',
                'supplier_id' => $data['supplier_id'] ?? null,
                'warranty_months' => $data['warranty_months'] ?? 0,
            ]);
            StockMovement::create([
                'store_id' => $storeId, 'product_id' => $data['product_id'], 'product_item_id' => $unit->id,
                'type' => 'masuk', 'qty' => 1, 'ref_type' => 'unit', 'ref_id' => $unit->id,
                'note' => 'Unit baru ' . ($data['imei'] ?? "#{$unit->id}"), 'user_id' => auth()->id(),
            ]);
        });

        return back()->with('success', 'Unit IMEI berhasil ditambahkan.');
    }

    /**
     * UNDUH TEMPLATE CSV unit/IMEI: header + 1 baris contoh, delimiter ;, BOM.
     * Kolom cocok dengan @import
     * (sku,nama_produk,imei,serial,kondisi,harga_beli,harga_jual,supplier).
     * GET /units/template — didaftarkan SEBELUM /units/{unit}/timeline.
     */
    public function template()
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv(
                $out,
                ['sku', 'nama_produk', 'imei', 'serial', 'kondisi', 'harga_beli', 'harga_jual', 'supplier'],
                ';'
            );
            fputcsv(
                $out,
                ['IP13-128-BLK', 'iPhone 13 128GB Black', '356938035643809', 'SN123456', 'baru', '8500000', '9500000', 'Supplier Utama'],
                ';'
            );
            fclose($out);
        }, 'template_unit_imei.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * IMPORT CSV UNIT/IMEI MASSAL (cermin ProductController@import).
     * Kolom: sku, nama_produk, imei, serial, kondisi, harga_beli,
     * harga_jual, supplier. Delimiter otomatis `;`/`,`, BOM diabaikan,
     * validasi per baris, laporan baris gagal/dilewati via
     * session `import_fail`. Tiap unit sukses = 1 ProductItem (status
     * `tersedia`) + 1 StockMovement `masuk` (cermin store()).
     *
     * KEPUTUSAN: produk tak dikenal DITOLAK (tidak auto-create) — lebih
     * aman: mencegah typo nama/SKU melahirkan produk sampah yang
     * mencemari katalog + laporan stok. Buat/aktifkan dulu produk
     * serialized-nya di menu Produk, lalu import ulang barisnya.
     * Alasan yang sama: supplier tak dikenal juga DITOLAK (bukan
     * dibuat otomatis); kosongkan kolom supplier bila tidak ada.
     *
     * - imei WAJIB & UNIK: kosong → gagal; duplikat (sudah ada di DB
     *   maupun dobel dalam file yang sama) = LEWATI + lapor, unit
     *   existing TIDAK diubah (tanpa update harga/kondisi/supplier).
     * - Resolusi produk: SKU dulu (eksak), lalu nama_produk (eksak);
     *   keduanya kosong / tidak cocok → gagal. Produk non-serialized
     *   → gagal (unit IMEI hanya untuk produk serialized).
     * - kondisi: baru|bekas|refurbished|konsinyasi (kosong = baru).
     * - harga_beli/harga_jual: angka >= 0; kosong = ikut harga produk.
     * - Tanpa migration. POST /units/import (toko = session aktif).
     */
    public function import(Request $request)
    {
        $storeId = (int) session('active_store_id');
        abort_if(!$storeId, 422, 'Toko aktif belum dipilih.');
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:2048']);
        $conditions = ['baru', 'bekas', 'refurbished', 'konsinyasi'];

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        // Deteksi delimiter: file hasil Excel Indonesia memakai ';', file manual umumnya ','.
        $firstLine = fgets($handle) ?: '';
        rewind($handle);
        $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';
        $readCsv = function () use ($handle, $delimiter) {
            $cols = fgetcsv($handle, 0, $delimiter);
            if (is_array($cols)) $cols = array_map(fn($c) => is_string($c) ? ltrim($c, "\xEF\xBB\xBF") : $c, $cols);
            return $cols;
        };
        $header = array_map(fn($h) => strtolower(trim((string) $h)), $readCsv() ?: []);
        $ok = 0; $skipped = 0; $fail = []; $line = 1; $seen = [];
        while (($cols = $readCsv()) !== false) {
            $line++;
            if (count($cols) === 1 && trim((string) $cols[0]) === '') continue;
            $row = array_combine(array_slice(array_merge($header, range(0, count($cols) - 1)), 0, count($cols)), $cols);
            $g = fn($k) => trim((string) ($row[$k] ?? ''));
            $err = null;

            $sku = $g('sku');
            $namaProduk = $g('nama_produk') !== '' ? $g('nama_produk') : $g('nama');
            $imei = $g('imei');
            if ($imei === '') $err = 'imei kosong (wajib diisi)';
            elseif (mb_strlen($imei) > 50) $err = 'imei max 50 karakter';
            elseif (isset($seen[mb_strtolower($imei, 'UTF-8')])) {
                $skipped++;
                $fail[] = "Baris $line: dilewati — duplikat IMEI $imei dalam file (unit existing tidak diubah)";
                continue;
            } elseif (ProductItem::where('imei', $imei)->exists()) {
                $skipped++;
                $seen[mb_strtolower($imei, 'UTF-8')] = $line;
                $fail[] = "Baris $line: dilewati — duplikat IMEI $imei (unit existing tidak diubah)";
                continue;
            }
            if ($err) { $fail[] = "Baris $line: $err"; continue; }
            $seen[mb_strtolower($imei, 'UTF-8')] = $line;

            $kondisi = mb_strtolower($g('kondisi') !== '' ? $g('kondisi') : 'baru', 'UTF-8');
            if (!in_array($kondisi, $conditions, true)) $err = 'kondisi tidak valid: ' . $g('kondisi') . ' (baru|bekas|refurbished|konsinyasi)';
            elseif ($g('harga_beli') !== '' && !is_numeric($g('harga_beli'))) $err = 'harga_beli bukan angka';
            elseif ($g('harga_jual') !== '' && !is_numeric($g('harga_jual'))) $err = 'harga_jual bukan angka';
            elseif ($g('serial') !== '' && mb_strlen($g('serial')) > 100) $err = 'serial max 100 karakter';
            if ($err) { $fail[] = "Baris $line: $err"; continue; }

            $product = null;
            if ($sku !== '') $product = Product::where('sku', $sku)->first();
            if (!$product && $namaProduk !== '') $product = Product::where('name', $namaProduk)->first();
            if ($sku === '' && $namaProduk === '') { $fail[] = "Baris $line: produk kosong (isi sku atau nama_produk)"; continue; }
            if (!$product) { $fail[] = "Baris $line: produk tak dikenal (sku/nama_produk tidak cocok) — buat dulu di menu Produk"; continue; }
            if (!$product->is_serialized) { $fail[] = "Baris $line: produk '{$product->name}' bukan serialized — unit IMEI hanya untuk produk serialized"; continue; }

            $supplierId = null;
            if ($g('supplier') !== '') {
                $sup = Supplier::where('store_id', $storeId)->where('name', $g('supplier'))->first()
                    ?? Supplier::where('name', $g('supplier'))->first();
                if (!$sup) { $fail[] = "Baris $line: supplier tak dikenal: " . $g('supplier'); continue; }
                $supplierId = $sup->id;
            }

            try {
                DB::transaction(function () use ($storeId, $product, $imei, $kondisi, $g, $supplierId) {
                    $unit = ProductItem::create([
                        'product_id' => $product->id, 'store_id' => $storeId,
                        'imei' => $imei, 'serial' => $g('serial') !== '' ? $g('serial') : null,
                        'condition' => $kondisi,
                        'purchase_price' => $g('harga_beli') === '' ? (float) $product->purchase_price : (float) $g('harga_beli'),
                        'selling_price' => $g('harga_jual') === '' ? (float) $product->selling_price : (float) $g('harga_jual'),
                        'status' => 'tersedia', 'supplier_id' => $supplierId,
                        'warranty_months' => 0,
                    ]);
                    StockMovement::create([
                        'store_id' => $storeId, 'product_id' => $product->id, 'product_item_id' => $unit->id,
                        'type' => 'masuk', 'qty' => 1, 'ref_type' => 'unit', 'ref_id' => $unit->id,
                        'note' => 'Import CSV ' . $imei, 'user_id' => auth()->id(),
                    ]);
                });
                $ok++;
            } catch (\Throwable $e) {
                report($e);
                $fail[] = "Baris $line: " . $e->getMessage();
            }
        }
        fclose($handle);

        $errCount = count($fail) - $skipped;
        return redirect()->route('units.index')
            ->with('success', "Import selesai: $ok berhasil, $skipped dilewati (duplikat IMEI), $errCount gagal.")
            ->with('import_fail', $fail);
    }

    public function timeline(ProductItem $unit)
    {
        $storeId = (int) session('active_store_id');
        if ($storeId && (auth()->user()->role ?? '') !== 'superadmin') {
            abort_if((int) $unit->store_id !== $storeId, 403, 'Bukan unit toko aktif.');
        }
        $unit->load(['product', 'store', 'supplier']);
        $movements = StockMovement::with(['store', 'fromStore', 'toStore', 'user'])
            ->where('product_item_id', $unit->id)->orderBy('id')->get()
            ->map(fn($m) => ['waktu' => $m->created_at?->format('d/m/Y H:i'), 'tipe' => $m->type,
                'toko' => $m->store->name ?? '', 'dari' => $m->fromStore->name ?? '',
                'ke' => $m->toStore->name ?? '', 'catatan' => $m->note, 'oleh' => $m->user->name ?? '']);
        $sales = SaleItem::with('sale.customer')->where('product_item_id', $unit->id)->get()
            ->map(fn($si) => ['waktu' => $si->created_at?->format('d/m/Y H:i'),
                'invoice' => $si->sale->invoice_no ?? ('#' . $si->sale_id),
                'pelanggan' => $si->sale->customer->name ?? 'Umum',
                'harga' => (float) $si->price, 'qty' => (int) $si->qty]);
        $services = $unit->imei
            ? ServiceTicket::with('customer')->where('imei', $unit->imei)->orderByDesc('id')->limit(20)->get()
                ->map(fn($s) => ['waktu' => $s->created_at?->format('d/m/Y H:i'), 'tiket' => $s->ticket_no,
                    'keluhan' => $s->complaint, 'status' => $s->status,
                    'pelanggan' => $s->customer->name ?? '-', 'biaya' => (float) $s->total_cost]) : [];
        $origins = [];
        if ($unit->imei) {
            foreach (TradeIn::where('imei', $unit->imei)->get() as $t) {
                $origins[] = ['waktu' => $t->created_at?->format('d/m/Y H:i'), 'sumber' => 'Tukar tambah',
                    'detail' => trim($t->old_brand . ' ' . $t->old_type) . ' • taksiran Rp ' . number_format($t->appraisal_price, 0, ',', '.')];
            }
            foreach (SecondhandPurchase::where('imei', $unit->imei)->get() as $s) {
                $origins[] = ['waktu' => $s->created_at?->format('d/m/Y H:i'), 'sumber' => 'Beli bekas',
                    'detail' => 'Grade ' . $s->condition_grade . ' • beli Rp ' . number_format($s->buy_price, 0, ',', '.')];
            }
        }
        return response()->json([
            'unit' => ['imei' => $unit->imei, 'serial' => $unit->serial,
                'produk' => $unit->product->name ?? '', 'toko' => $unit->store->name ?? '',
                'kondisi' => $unit->condition, 'status' => $unit->status,
                'beli' => (float) $unit->purchase_price, 'jual' => (float) $unit->selling_price,
                'supplier' => $unit->supplier->name ?? '-', 'masuk' => $unit->created_at?->format('d/m/Y H:i')],
            'origins' => $origins, 'movements' => $movements, 'sales' => $sales, 'services' => $services,
        ]);
    }
}
