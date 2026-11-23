<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductItem;
use App\Models\ProductStock;
use App\Models\PurchaseItem;
use App\Models\SaleItem;
use App\Models\ServiceSparepart;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Supplier;
use App\Services\WaService;
use App\Support\LookupCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    protected function inventoryRoles(): array
    {
        return ['superadmin', 'owner', 'admin', 'gudang', 'kasir'];
    }

    protected function authorizeInventory(bool $write = false): void
    {
        $role = auth()->user()->role ?? '';
        if ($write) {
            abort_unless(in_array($role, ['superadmin', 'owner', 'admin', 'gudang']), 403, 'Hanya admin/gudang/owner yang boleh mengubah produk.');
        } else {
            abort_unless(in_array($role, $this->inventoryRoles()), 403, 'Tidak punya akses inventory.');
        }
    }

    public function index(Request $request)
    {
        $this->authorizeInventory();
        // Pola existing lintas-toko (?all=1): lihat UnitController/ConsignmentController
        // yang memakai $request->boolean('all') + cek role. Di sini dibuka untuk
        // superadmin & owner sesuai permintaan putaran 37.
        $role = auth()->user()->role ?? '';
        $showAll = $request->boolean('all') && in_array($role, ['superadmin', 'owner'], true);
        $base = Product::with(['brand', 'category'])
            ->withSum(['stocks as stock_total' => fn($q) => $q->when(!$showAll && session('active_store_id'), fn($qq) => $qq->where('store_id', session('active_store_id')))], 'qty')
            ->when($request->filled('type'), fn($q) => $q->where('type', $request->type))
            ->when($request->filled('brand_id'), fn($q) => $q->where('brand_id', $request->brand_id))
            ->when($request->filled('category_id'), fn($q) => $q->where('category_id', $request->category_id))
            ->when($request->filled('q'), fn($q) => $q->where(function ($w) {
                $w->where('name', 'like', '%' . request('q') . '%')
                  ->orWhere('sku', 'like', '%' . request('q') . '%')
                  ->orWhere('barcode', 'like', '%' . request('q') . '%');
            }))
            ->orderByDesc('id');
        // Filter jual rugi (?rugi=1): produk AKTIF yang harga jual < harga beli.
        // Master global — tidak di-scope per toko.
        $base->when($request->boolean('rugi'), function ($q) {
            $q->where('is_active', true)
                ->whereColumn('selling_price', '<', 'purchase_price');
        });
        // Filter tanpa foto (?no_photo=1): produk AKTIF yang kolom photo
        // kosong (NULL atau ''). Master global — tanpa scope toko,
        // tanpa migration/route baru.
        $base->when($request->boolean('no_photo'), function ($q) {
            $q->where('is_active', true)
                ->where(function ($w) {
                    $w->whereNull('photo')
                        ->orWhere('photo', '');
                });
        });
        // Export CSV (?export=csv) — hormati filter aktif + stok toko aktif
        if ($request->get('export') === 'csv') {
            $rows = (clone $base)->with(['brand', 'category'])->limit(5000)->get();
            $storeId = session('active_store_id');
            $filename = 'produk_toko_' . ($storeId ?? 'semua') . '_' . now()->format('Ymd_His') . '.csv';
            return $this->csvStream($filename, $rows);
        }
        $products = $base->paginate(20)->withQueryString();
        $brands = LookupCache::brands();
        $categories = LookupCache::categories();
        $storeId = session('active_store_id');
        // Peta stok & unit tersedia per toko untuk mode ?all=1 (1 query agregat
        // masing-masing, tanpa N+1 di blade). Tanpa migration/route baru.
        $stores = collect();
        $stockMap = [];
        $unitMap = [];
        if ($showAll) {
            $stores = Store::where('is_active', true)
                ->orderByDesc('is_center')
                ->orderBy('name')
                ->get(['id', 'name']);
            $pids = $products->getCollection()->pluck('id')->values();
            if ($pids->isNotEmpty() && $stores->isNotEmpty()) {
                $sids = $stores->pluck('id')->all();
                $stockRows = ProductStock::whereIn('product_id', $pids)
                    ->whereIn('store_id', $sids)
                    ->selectRaw('product_id, store_id, SUM(qty) AS q')
                    ->groupBy('product_id', 'store_id')
                    ->get();
                foreach ($stockRows as $r) {
                    $stockMap[(int) $r->product_id][(int) $r->store_id] = (int) $r->q;
                }
                // product_items tersedia per produk per toko (produk serialized/IMEI).
                $unitRows = ProductItem::whereIn('product_id', $pids)
                    ->whereIn('store_id', $sids)
                    ->where('status', 'tersedia')
                    ->selectRaw('product_id, store_id, COUNT(*) AS c')
                    ->groupBy('product_id', 'store_id')
                    ->get();
                foreach ($unitRows as $r) {
                    $unitMap[(int) $r->product_id][(int) $r->store_id] = (int) $r->c;
                }
            }
        }
        $lowSpareparts = Product::query()
            ->withSum(['stocks as stock_total' => fn($q) => $q->when($storeId, fn($qq) => $qq->where('store_id', $storeId))], 'qty')
            ->where('type', 'sparepart')->where('is_active', true)
            ->orderBy('min_stock')->limit(100)->get();
        // Bandingkan dengan min EFEKTIF toko aktif (override per toko bila ada).
        $storeMinMapIdx = $storeId
            ? ProductStock::where('store_id', $storeId)->whereNotNull('min_stock')->pluck('min_stock', 'product_id')
            : collect();
        $lowSpareparts = $lowSpareparts
            ->filter(fn($p) => (int) ($p->stock_total ?? 0) <= (int) ($storeMinMapIdx->get($p->id, $p->min_stock ?? 0)));
        // Section "Kemungkinan Duplikat" — hanya saat tidak sedang mencari (agar ringan)
        $duplicateGroups = $request->filled('q') ? [] : self::findDuplicateGroups();
        // ALERT JUAL RUGI (putaran 98): produk aktif yang harga jual < harga beli.
        // Master global — tanpa scope toko, tanpa migration/route baru.
        $lossCount = Product::query()
            ->where('is_active', true)
            ->whereColumn('selling_price', '<', 'purchase_price')
            ->count();
        $lossProducts = Product::query()
            ->with(['brand'])
            ->where('is_active', true)
            ->whereColumn('selling_price', '<', 'purchase_price')
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'sku', 'name', 'brand_id', 'type', 'purchase_price', 'selling_price', 'is_active']);
        // Badge hitung tanpa foto (putaran 159): produk AKTIF tanpa foto.
        $noPhotoCount = Product::query()
            ->where('is_active', true)
            ->where(function ($w) {
                $w->whereNull('photo')
                    ->orWhere('photo', '');
            })
            ->count();
        return view('products.index', compact('products', 'brands', 'categories', 'lowSpareparts', 'duplicateGroups', 'showAll', 'stores', 'stockMap', 'unitMap', 'lossCount', 'lossProducts', 'noPhotoCount'));
    }

    /** Kunci nama mirip: lowercase tanpa spasi & non-alfanumerik. */
    public static function nameKey(string $name): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(trim($name)));
    }

    /**
     * Kelompok kemungkinan duplikat (max 20 grup): by nama ternormalisasi
     * (lowercase alfanumerik), count > 1. Dihitung di PHP karena
     * normalisasi tidak tersimpan mentah di DB.
     */
    public static function findDuplicateGroups(int $maxGroups = 20, int $scanLimit = 2000): array
    {
        $rows = Product::query()
            ->with(['brand'])
            ->orderByDesc('id')->limit($scanLimit)->get(['id', 'sku', 'brand_id', 'name', 'type']);
        $byName = [];
        foreach ($rows as $p) {
            $nk = self::nameKey((string) $p->name);
            if ($nk !== '') $byName[$nk][] = $p;
        }
        $groups = [];
        $seen = [];
        foreach ($byName as $key => $list) {
            if (count($list) < 2 || count($groups) >= $maxGroups) continue;
            $ids = collect($list)->map(fn ($m) => $m->id)->sort()->values()->implode('-');
            if (isset($seen[$ids])) continue;
            $seen[$ids] = true;
            $groups[] = ['key' => (string) $key, 'members' => collect($list)->sortByDesc('id')->values()];
        }
        return array_slice($groups, 0, $maxGroups);
    }

    public function create()
    {
        $this->authorizeInventory(true);
        $brands = LookupCache::brands();
        $categories = LookupCache::categories();
        return view('products.create', compact('brands', 'categories'));
    }

    public function store(Request $request)
    {
        $this->authorizeInventory(true);
        $data = $request->validate([
            'brand_id' => 'nullable|exists:brands,id',
            'category_id' => 'nullable|exists:categories,id',
            'name' => 'required|string|max:200',
            'type' => 'required|in:hp_baru,hp_bekas,refurbished,aksesoris,sparepart,pulsa,paket_data,lain',
            'ram' => 'nullable|string|max:50',
            'storage' => 'nullable|string|max:50',
            'warna' => 'nullable|string|max:50',
            'purchase_price' => 'required|numeric|min:0',
            'selling_price' => 'required|numeric|min:0',
            'min_stock' => 'nullable|integer|min:0',
            'is_serialized' => 'nullable|boolean',
            'barcode' => 'nullable|string|max:100',
            'photo' => 'nullable|image|max:2048',
            'is_active' => 'nullable|boolean',
            'initial_stock' => 'nullable|integer|min:0',
        ]);

        do {
            $sku = 'SKU-' . now()->format('Ymd') . '-' . strtoupper(Str::random(4));
        } while (Product::where('sku', $sku)->exists());

        $photoPath = null;
        if ($request->hasFile('photo')) {
            $photoPath = $request->file('photo')->store('products', 'public');
        }

        $product = Product::create([
            'sku' => $sku,
            'brand_id' => $data['brand_id'] ?? null, 'category_id' => $data['category_id'] ?? null,
            'name' => $data['name'], 'type' => $data['type'],
            'ram' => $data['ram'] ?? null, 'storage' => $data['storage'] ?? null, 'warna' => $data['warna'] ?? null,
            'purchase_price' => $data['purchase_price'], 'selling_price' => $data['selling_price'],
            'min_stock' => $data['min_stock'] ?? 5,
            'is_serialized' => (bool) ($data['is_serialized'] ?? false),
            'barcode' => $data['barcode'] ?? $sku, 'photo' => $photoPath,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        if (!empty($data['initial_stock']) && !$product->is_serialized && session('active_store_id')) {
            ProductStock::create(['product_id' => $product->id, 'store_id' => session('active_store_id'), 'qty' => $data['initial_stock']]);
            \App\Models\StockMovement::create([
                'store_id' => session('active_store_id'), 'product_id' => $product->id,
                'type' => 'masuk', 'qty' => $data['initial_stock'],
                'ref_type' => 'product', 'ref_id' => $product->id,
                'note' => 'Stok awal ' . $sku, 'user_id' => auth()->id(),
            ]);
        }

        return redirect()->route('products.show', $product)->with('success', 'Produk ' . $sku . ' berhasil dibuat.');
    }

    public function show(Product $product)
    {
        $this->authorizeInventory();
        $product->load(['brand', 'category', 'stocks.store', 'items' => fn($q) => $q->orderByDesc('id')->limit(100)]);
        $storeId = session('active_store_id');
        $movements = StockMovement::with('user')
            ->where('product_id', $product->id)
            ->when($storeId, fn($q) => $q->where('store_id', $storeId))
            ->orderByDesc('id')->limit(20)->get();
        $current = (int) ProductStock::where('product_id', $product->id)
            ->when($storeId, fn($q) => $q->where('store_id', $storeId))->sum('qty');
        $signed = fn($m) => in_array($m->type, ['masuk', 'mutasi_masuk']) ? (int) $m->qty
            : (in_array($m->type, ['keluar', 'mutasi_keluar']) ? -(int) $m->qty : 0);
        $run = $current - $movements->sum($signed);
        $rows = $movements->sortBy('id')->map(function ($m) use (&$run, $signed) {
            $run += $signed($m);
            $m->saldo = $run;
            return $m;
        });
        return view('products.show', compact('product', 'rows', 'current'));
    }

    public function edit(Product $product)
    {
        $this->authorizeInventory(true);
        $brands = LookupCache::brands();
        $categories = LookupCache::categories();
        return view('products.edit', compact('product', 'brands', 'categories'));
    }

    public function update(Request $request, Product $product)
    {
        $this->authorizeInventory(true);
        $data = $request->validate([
            'brand_id' => 'nullable|exists:brands,id',
            'category_id' => 'nullable|exists:categories,id',
            'name' => 'required|string|max:200',
            'type' => 'required|in:hp_baru,hp_bekas,refurbished,aksesoris,sparepart,pulsa,paket_data,lain',
            'ram' => 'nullable|string|max:50',
            'storage' => 'nullable|string|max:50',
            'warna' => 'nullable|string|max:50',
            'purchase_price' => 'required|numeric|min:0',
            'selling_price' => 'required|numeric|min:0',
            'min_stock' => 'nullable|integer|min:0',
            'is_serialized' => 'nullable|boolean',
            'barcode' => 'nullable|string|max:100',
            'photo' => 'nullable|image|max:2048',
            'is_active' => 'nullable|boolean',
        ]);

        if ($request->hasFile('photo')) {
            $old = $product->photo;
            $data['photo'] = $request->file('photo')->store('products', 'public');
            // Hapus foto lama agar tidak jadi file yatim.
            if ($old && $old !== $data['photo']) {
                try { \Storage::disk('public')->delete($old); } catch (\Throwable $e) { report($e); }
            }
        } else {
            unset($data['photo']);
        }
        $data['is_serialized'] = (bool) ($data['is_serialized'] ?? false);
        $data['is_active'] = (bool) ($data['is_active'] ?? true);
        $product->update($data);

        return redirect()->route('products.show', $product)->with('success', 'Produk diperbarui.');
    }

    /** Saran belanja otomatis: produk aktif toko yang stok <= min_stock.
     *  Stok produk biasa = ProductStock toko aktif; produk serialized (IMEI)
     *  = jumlah unit 'tersedia' di toko aktif. Tanpa migration/tabel baru. */
    public function reorder()
    {
        $this->authorizeInventory();
        [$rows, $groups, $totalEstimate, $storeName] = $this->reorderData();
        $waGroups = $this->waGroups($groups, $storeName);

        return view('products.reorder', compact('rows', 'groups', 'totalEstimate', 'storeName', 'waGroups'));
    }

    /** Kirim daftar restock 1 grup supplier via WA (gateway, type 'purchase_order').
     *  1 route POST baru: POST /products/reorder/wa (tak bentrok resource —
     *  resource tak punya POST wildcard; didaftarkan sebelum resource). */
    public function reorderWa(Request $request)
    {
        $this->authorizeInventory();
        $data = $request->validate(['supplier_id' => 'required']);
        $sid = (string) $data['supplier_id'];
        if ($sid === 'none' || $sid === '') {
            return back()->with('error', 'Kelompok ini tanpa supplier — pilih supplier dulu di data supplier/produk.');
        }

        [$rows, $groups, $totalEstimate, $storeName] = $this->reorderData();
        $items = null;
        foreach ($groups as $key => $list) {
            if ((string) $key === $sid) { $items = $list; break; }
        }
        if (!$items || $items->isEmpty()) {
            return back()->with('error', 'Daftar restock supplier tidak ditemukan (mungkin stok sudah aman).');
        }

        $first = $items->first();
        $supplier = Supplier::whereKey($sid)->first();
        $rawPhone = (string) ($supplier?->phone ?? $first['supplier_phone'] ?? '');
        $phone = WaService::normalize($rawPhone);
        if (strlen($phone) < 9 || strlen($phone) > 15) {
            return back()->with('error', 'Nomor WA supplier ' . ($supplier?->name ?? $first['supplier']) . ' kosong/tidak valid — lengkapi nomor HP supplier dulu.');
        }

        $msg = $this->waMessage((string) ($supplier?->name ?? $first['supplier']), $items, $storeName);
        $ok = WaService::send($phone, $msg, 'purchase_order', session('active_store_id'));

        return $ok
            ? back()->with('success', 'Daftar restock (' . $items->count() . ' jenis) dikirim via WA ke ' . ($supplier?->name ?? $first['supplier']) . ' (' . $phone . ').')
            : back()->with('error', 'Gagal mengirim WA ke supplier (' . $phone . '). Cek koneksi gateway / menu Pengaturan WA.');
    }

    /** Bangun ulang data saran belanja (dipakai reorder & reorderWa agar konsisten). */
    private function reorderData(): array
    {
        $storeId = session('active_store_id');

        $products = Product::with('brand')
            ->where('is_active', true)
            ->withSum(['stocks as stock_total' => fn($q) => $q->when($storeId, fn($qq) => $qq->where('store_id', $storeId))], 'qty')
            ->orderBy('name')->get();

        $ids = $products->pluck('id')->values();
        $unitCounts = collect();
        $lastItems = collect();
        $lastUnits = collect();
        $sold30 = collect();
        // Min. stok EFEKTIF per toko: product_stocks.min_stock ?? products.min_stock.
        $storeMinMap = collect();
        if ($ids->isNotEmpty() && $storeId) {
            $storeMinMap = ProductStock::where('store_id', $storeId)
                ->whereIn('product_id', $ids)
                ->whereNotNull('min_stock')
                ->pluck('min_stock', 'product_id');
        }
        if ($ids->isNotEmpty()) {
            $unitCounts = ProductItem::query()
                ->whereIn('product_id', $ids)
                ->when($storeId, fn($q) => $q->where('store_id', $storeId))
                ->where('status', 'tersedia')
                ->selectRaw('product_id, COUNT(*) AS c')
                ->groupBy('product_id')->pluck('c', 'product_id');
            $latestIds = PurchaseItem::whereIn('product_id', $ids)
                ->selectRaw('MAX(id) AS id')->groupBy('product_id')->pluck('id');
            $lastItems = PurchaseItem::whereIn('id', $latestIds)
                ->with('purchase.supplier')->get()->keyBy('product_id');
            $latestUnitIds = ProductItem::whereIn('product_id', $ids)
                ->whereNotNull('supplier_id')
                ->selectRaw('MAX(id) AS id')->groupBy('product_id')->pluck('id');
            $lastUnits = ProductItem::whereIn('id', $latestUnitIds)
                ->with('supplier')->get()->keyBy('product_id');
            // Kecepatan jual 30 hari: SUM(sale_items.qty) join sales (toko aktif bila ada)
            $sold30 = SaleItem::query()
                ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                ->whereIn('sale_items.product_id', $ids)
                ->where('sales.created_at', '>=', now()->subDays(30))
                ->when($storeId, fn($q) => $q->where('sales.store_id', $storeId))
                ->selectRaw('sale_items.product_id, SUM(sale_items.qty) AS q')
                ->groupBy('sale_items.product_id')->pluck('q', 'product_id');
        }

        $rows = [];
        foreach ($products as $p) {
            $stock = $p->is_serialized
                ? (int) ($unitCounts[$p->id] ?? 0)
                : (int) ($p->stock_total ?? 0);
            // Min efektif: override toko bila ada, kalau NULL ikut global produk.
            $min = $storeMinMap->has($p->id) ? (int) $storeMinMap[$p->id] : (int) ($p->min_stock ?? 0);
            if ($stock > $min) continue;
            $need = max($min * 2 - $stock, 1);
            // Saran Min cerdas: ceil(qty30/30 × 7 × 1.2), min 1
            $qty30 = (float) ($sold30[$p->id] ?? 0);
            $suggestMin = max(1, (int) ceil($qty30 / 30 * 7 * 1.2));
            // Ketahanan stok (hari): stok kini / kecepatan harian (qty30/30); null = ∞ bila velocity 0
            $velocity = $qty30 / 30;
            $daysCover = $velocity > 0 ? $stock / $velocity : null;
            $li = $lastItems[$p->id] ?? null;
            $price = $li ? (float) $li->price : (float) $p->purchase_price;
            $supplier = ($li && $li->purchase && $li->purchase->supplier)
                ? $li->purchase->supplier
                : (($lastUnits[$p->id] ?? null)?->supplier ?? null);
            $rows[] = [
                'product_id' => $p->id,
                'name' => $p->name, 'sku' => $p->sku, 'type' => $p->type,
                'brand' => $p->brand->name ?? '-', 'is_serialized' => (bool) $p->is_serialized,
                'stock' => $stock, 'min' => $min, 'need' => $need,
                'store_min' => $storeMinMap->has($p->id) ? (int) $storeMinMap[$p->id] : null,
                'global_min' => (int) ($p->min_stock ?? 0),
                'sold30' => $qty30, 'suggest_min' => $suggestMin,
                'raise_min' => $suggestMin > $min,
                'velocity' => $velocity, 'days_cover' => $daysCover,
                'price' => $price, 'estimate' => $need * $price,
                'supplier_id' => $supplier?->id, 'supplier' => $supplier?->name ?? '-',
                'supplier_phone' => $supplier?->phone ?? '',
                'invoice' => $li?->purchase?->invoice_no,
            ];
        }

        // Supplier TERMURAH per produk: harga terbaru per supplier dalam 12 bulan
        // terakhir (purchase_items join purchases), lalu ambil min price.
        // Tanpa migration/tabel baru; tanggal = purchases.created_at.
        $needIds = collect($rows)->pluck('product_id')->values();
        $cheapestMap = [];
        if ($needIds->isNotEmpty()) {
            $hist = DB::table('purchase_items')
                ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
                ->leftJoin('suppliers', 'suppliers.id', '=', 'purchases.supplier_id')
                ->whereIn('purchase_items.product_id', $needIds)
                ->where('purchases.created_at', '>=', now()->subMonths(12))
                ->orderByDesc('purchase_items.id')
                ->limit(2000)
                ->get([
                    'purchase_items.product_id', 'purchase_items.price',
                    'purchases.supplier_id', 'suppliers.name as supplier_name',
                    'purchases.created_at as tanggal',
                ]);
            $latestPerSup = [];
            foreach ($hist as $h) {
                $pid = (int) $h->product_id;
                $sid = $h->supplier_id !== null ? (int) $h->supplier_id : 0;
                if (!isset($latestPerSup[$pid][$sid])) $latestPerSup[$pid][$sid] = $h;
            }
            foreach ($latestPerSup as $pid => $bySup) {
                $best = null;
                foreach ($bySup as $sid => $row) {
                    if ($sid === 0 || $row->supplier_name === null) continue;
                    if ($best === null || (float) $row->price < (float) $best->price) $best = $row;
                }
                if ($best) $cheapestMap[$pid] = $best;
            }
            foreach ($rows as &$r) {
                $c = $cheapestMap[$r['product_id']] ?? null;
                $r['cheapest_supplier_id'] = $c?->supplier_id;
                $r['cheapest_supplier'] = $c?->supplier_name;
                $r['cheapest_price'] = $c !== null ? (float) $c->price : null;
                $r['cheapest_date'] = $c?->tanggal;
            }
            unset($r);
        } else {
            foreach ($rows as &$r) {
                $r['cheapest_supplier_id'] = null;
                $r['cheapest_supplier'] = null;
                $r['cheapest_price'] = null;
                $r['cheapest_date'] = null;
            }
            unset($r);
        }

        $groups = collect($rows)->groupBy(fn($r) => $r['supplier_id'] ?? 'none');
        $totalEstimate = collect($rows)->sum('estimate');
        $storeName = $storeId ? (\App\Models\Store::whereKey($storeId)->value('name') ?? ('#' . $storeId)) : 'Semua toko';

        return [$rows, $groups, $totalEstimate, $storeName];
    }

    /** Format pesan restock 1 supplier (dipakai gateway + link wa.me agar konsisten). */
    private function waMessage(string $supplierName, $items, string $storeName): string
    {
        $lines = [];
        $lines[] = 'Halo *' . $supplierName . '*, kami dari *ServiceHP ' . $storeName . '* ingin memesan barang restock:';
        $lines[] = '';
        $totalQty = 0;
        $total = 0;
        $no = 0;
        foreach ($items as $it) {
            $no++;
            $qty = (int) ($it['need'] ?? 0);
            $price = (float) ($it['price'] ?? 0);
            $sub = $qty * $price;
            $totalQty += $qty;
            $total += $sub;
            $lines[] = $no . '. ' . ($it['name'] ?? '-') . ' (' . ($it['sku'] ?? '-') . ')'
                . ' x' . $qty
                . ' @ Rp ' . number_format($price, 0, ',', '.')
                . ' = Rp ' . number_format($sub, 0, ',', '.');
        }
        $lines[] = '';
        $lines[] = 'Total ' . $items->count() . ' jenis barang (' . $totalQty . ' pcs). Estimasi total: *Rp ' . number_format($total, 0, ',', '.') . '*';
        $lines[] = 'Mohon konfirmasi ketersediaan & harga. Terima kasih.';

        return implode("\n", $lines);
    }

    /** Paket siap-tampil per grup supplier: pesan, link wa.me, URL pembelian, subtotal. */
    private function waGroups($groups, string $storeName): array
    {
        $out = [];
        foreach ($groups as $sid => $items) {
            $items = $items->values();
            $first = $items->first();
            $name = (string) ($first['supplier'] ?? '-');
            $phone = WaService::normalize((string) ($first['supplier_phone'] ?? ''));
            $valid = $sid !== 'none' && strlen($phone) >= 9 && strlen($phone) <= 15;
            $msg = $sid === 'none' ? '' : $this->waMessage($name, $items, $storeName);
            $q = ['supplier_id' => $sid === 'none' ? null : $sid];
            $q['product_id'] = $items->pluck('product_id')->values()->all();
            $q['qty'] = $items->pluck('need')->values()->all();
            $q['price'] = $items->pluck('price')->values()->all();
            $out[] = [
                'sid' => $sid,
                'name' => $name,
                'phone' => $phone,
                'phone_valid' => $valid,
                'message' => $msg,
                'wa_link' => $valid ? 'https://wa.me/' . $phone . '?text=' . rawurlencode($msg) : null,
                'buy_url' => route('purchases.index', array_filter($q, fn($v) => !is_null($v))),
                'items' => $items,
                'count' => $items->count(),
                'total_qty' => $items->sum('need'),
                'subtotal' => $items->sum('estimate'),
            ];
        }

        return $out;
    }

    /** 1-klik update min_stock dari halaman reorder (admin/gudang/owner).
     *  Bila toko aktif dipilih: set override per toko
     *  (product_stocks.min_stock, NULL = ikut global) sehingga min efektif
     *  = nilai saran; tanpa toko aktif: update global products.min_stock. */
    public function updateMinStock(Request $request, Product $product)
    {
        $this->authorizeInventory(true);
        $data = $request->validate(['min_stock' => 'required|integer|min:0|max:1000000']);
        $storeId = session('active_store_id') ? (int) session('active_store_id') : null;
        if ($storeId) {
            $stock = ProductStock::firstOrCreate(
                ['store_id' => $storeId, 'product_id' => $product->id], ['qty' => 0]
            );
            $stock->update(['min_stock' => (int) $data['min_stock']]);
            return back()->with('success', 'Min. stok toko ' . $product->name . ' diubah ke ' . $data['min_stock'] . '. (global tetap ' . (int) ($product->min_stock ?? 0) . ')');
        }
        $product->update(['min_stock' => $data['min_stock']]);
        return back()->with('success', 'Min. stok ' . $product->name . ' diubah ke ' . $data['min_stock'] . '.');
    }

    /**
     * UNDUH TEMPLATE CSV produk: header-only + 1 baris contoh, delimiter ;, BOM.
     * Kolom cocok dengan @import (sku,nama,type,brand,category,harga_beli,harga_jual,min_stock).
     * GET /products/template — didaftarkan SEBELUM resource agar tak tertabrak {product}.
     */
    public function template()
    {
        $this->authorizeInventory();
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv(
                $out,
                ['sku', 'nama', 'type', 'brand', 'category', 'harga_beli', 'harga_jual', 'min_stock'],
                ';'
            );
            fputcsv(
                $out,
                ['ACC-001', 'Tempered Glass iPhone 13', 'aksesoris', 'Generic', 'aksesoris', '5000', '15000', '10'],
                ';'
            );
            fclose($out);
        }, 'template_produk.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function export(Request $request)
    {
        $this->authorizeInventory();
        $storeId = session('active_store_id');
        $products = Product::with(['brand', 'category'])
            ->withSum(['stocks as stock_total' => fn($q) => $q->when($storeId, fn($qq) => $qq->where('store_id', $storeId))], 'qty')
            ->when($request->filled('type'), fn($q) => $q->where('type', $request->type))
            ->when($request->filled('brand_id'), fn($q) => $q->where('brand_id', $request->brand_id))
            ->when($request->filled('q'), fn($q) => $q->where(function ($w) use ($request) {
                $w->where('name', 'like', '%' . $request->q . '%')
                  ->orWhere('sku', 'like', '%' . $request->q . '%')
                  ->orWhere('barcode', 'like', '%' . $request->q . '%');
            }))
            ->orderBy('id')->get();
        $file = 'produk_toko_' . ($storeId ?? 'semua') . '_' . now()->format('Ymd_His') . '.csv';
        return $this->csvStream($file, $products);
    }

    /** Stream CSV ala Excel Indonesia: header id + delimiter ; + BOM. */
    private function csvStream(string $file, $products)
    {
        return response()->streamDownload(function () use ($products) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['id', 'sku', 'nama', 'type', 'brand', 'category', 'harga_beli', 'harga_jual', 'min_stock', 'stok'], ';');
            foreach ($products as $p) {
                fputcsv($out, [
                    $p->id, $p->sku, $p->name, $p->type, $p->brand->name ?? '', $p->category->name ?? '',
                    (float) $p->purchase_price, (float) $p->selling_price,
                    (int) $p->min_stock, (int) ($p->stock_total ?? 0),
                ], ';');
            }
            fclose($out);
        }, $file, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function import(Request $request)
    {
        $this->authorizeInventory(true);
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:2048']);
        $types = ['hp_baru', 'hp_bekas', 'refurbished', 'aksesoris', 'sparepart', 'pulsa', 'paket_data', 'lain'];
        $catMap = ['hp_baru' => 'hp', 'hp_bekas' => 'hp', 'refurbished' => 'hp', 'aksesoris' => 'aksesoris', 'sparepart' => 'sparepart', 'pulsa' => 'pulsa', 'paket_data' => 'pulsa', 'lain' => 'lain'];

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        // Deteksi delimiter: file hasil Export memakai ';' (Excel Indonesia), file manual umumnya ','.
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
        // Batch: cache id brand/category per nama (dulu firstOrCreate = query per baris).
        $brandIds = [];
        $catIds = [];
        while (($cols = $readCsv()) !== false) {
            $line++;
            if (count($cols) === 1 && trim((string) $cols[0]) === '') continue;
            $row = array_combine(array_slice(array_merge($header, range(0, count($cols) - 1)), 0, count($cols)), $cols);
            $g = fn($k) => trim((string) ($row[$k] ?? ''));
            $err = null;
            $sku = $g('sku'); $nama = $g('nama'); $type = $g('type') ?: 'lain';
            if ($nama === '') $err = 'nama kosong';
            elseif (!in_array($type, $types, true)) $err = 'type tidak valid: ' . $type;
            elseif ($g('harga_beli') !== '' && !is_numeric($g('harga_beli'))) $err = 'harga_beli bukan angka';
            elseif ($g('harga_jual') !== '' && !is_numeric($g('harga_jual'))) $err = 'harga_jual bukan angka';
            elseif ($g('min_stock') !== '' && !ctype_digit($g('min_stock'))) $err = 'min_stock harus bilangan bulat >= 0';
            if ($err) { $fail[] = "Baris $line: $err"; continue; }

            try {
                $brandId = null; $catId = null;
                if ($g('brand') !== '') {
                    $bn = $g('brand');
                    if (! array_key_exists($bn, $brandIds)) {
                        $brandIds[$bn] = Brand::firstOrCreate(['name' => $bn])->id;
                    }
                    $brandId = $brandIds[$bn];
                }
                if ($g('category') !== '') {
                    $cn = $g('category');
                    if (! array_key_exists($cn, $catIds)) {
                        $catIds[$cn] = Category::firstOrCreate(['name' => $cn], ['type' => $catMap[$type] ?? 'lain'])->id;
                    }
                    $catId = $catIds[$cn];
                }
                $attrs = [
                    'name' => $nama, 'type' => $type,
                    'brand_id' => $brandId, 'category_id' => $catId,
                    'purchase_price' => $g('harga_beli') === '' ? 0 : (float) $g('harga_beli'),
                    'selling_price' => $g('harga_jual') === '' ? 0 : (float) $g('harga_jual'),
                    'min_stock' => $g('min_stock') === '' ? 5 : (int) $g('min_stock'),
                ];
                if ($sku !== '' && Product::where('sku', $sku)->exists()) {
                    Product::where('sku', $sku)->first()->update($attrs);
                } else {
                    do {
                        $newSku = $sku !== '' ? $sku : 'SKU-' . now()->format('Ymd') . '-' . strtoupper(Str::random(4));
                    } while (Product::where('sku', $newSku)->exists());
                    Product::create(array_merge($attrs, ['sku' => $newSku, 'barcode' => $newSku, 'is_active' => true]));
                }
                $ok++;
            } catch (\Throwable $e) {
                $fail[] = "Baris $line: " . $e->getMessage();
            }
        }
        fclose($handle);

        return redirect()->route('products.index')
            ->with('success', "Import selesai: $ok baris berhasil, " . count($fail) . ' gagal.')
            ->with('import_fail', $fail);
    }

    /** Ubah harga jual massal: filter → preview (JS) → apply.
     *  Harga bukan mutasi stok: hanya update selling_price, tanpa stock_movements. */
    public function bulkPrice(Request $request)
    {
        $this->authorizeInventory(true);
        $products = Product::with(['brand', 'category'])
            ->when($request->filled('type'), fn($q) => $q->where('type', $request->type))
            ->when($request->filled('brand_id'), fn($q) => $q->where('brand_id', $request->brand_id))
            ->when($request->filled('category_id'), fn($q) => $q->where('category_id', $request->category_id))
            ->when($request->filled('q'), fn($q) => $q->where(function ($w) use ($request) {
                $w->where('name', 'like', '%' . $request->q . '%')
                  ->orWhere('sku', 'like', '%' . $request->q . '%');
            }))
            ->orderBy('name')->limit(500)->get();
        $brands = LookupCache::brands();
        $categories = LookupCache::categories();
        return view('products.bulk', compact('products', 'brands', 'categories'));
    }

    public function bulkPriceUpdate(Request $request)
    {
        $this->authorizeInventory(true);
        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'exists:products,id',
            'mode' => 'required|in:percent_up,percent_down,nominal_up,nominal_down,margin,status',
            'value' => 'nullable|numeric|min:0',
            'status_value' => 'nullable|in:0,1',
            'allow_below' => 'nullable|boolean',
        ]);

        // Mode "status": aktif/nonaktif massal dalam satu transaction.
        if (($data['mode'] ?? '') === 'status') {
            if (!isset($data['status_value']) || $data['status_value'] === '') {
                return back()->withErrors(['status_value' => 'Pilih status tujuan (aktif / nonaktif).'])->withInput();
            }
            $active = (int) $data['status_value'] === 1;
            $count = 0;
            \DB::transaction(function () use ($data, $active, &$count) {
                $count = Product::whereIn('id', $data['ids'])->update(['is_active' => $active]);
            });
            $label = $active ? 'diaktifkan' : 'dinonaktifkan';
            return redirect()->route('products.bulk', $request->only(['type', 'brand_id', 'category_id', 'q']))
                ->with('success', "Status massal diterapkan: $count produk $label.");
        }

        if (!isset($data['value']) || $data['value'] === '' || !is_numeric($data['value'])) {
            return back()->withErrors(['value' => 'Nilai wajib diisi untuk mode harga.'])->withInput();
        }
        $allowBelow = (bool) ($data['allow_below'] ?? false);
        $products = Product::whereIn('id', $data['ids'])->get();
        $updated = 0; $skipped = 0;
        \DB::transaction(function () use ($products, $data, $allowBelow, &$updated, &$skipped) {
            foreach ($products as $p) {
                $buy = (float) $p->purchase_price;
                $sell = (float) $p->selling_price;
                $v = (float) $data['value'];
                $new = match ($data['mode']) {
                    'percent_up' => $sell * (1 + $v / 100),
                    'percent_down' => $sell * (1 - $v / 100),
                    'nominal_up' => $sell + $v,
                    'nominal_down' => $sell - $v,
                    'margin' => $buy * (1 + $v / 100),
                };
                $new = max(0, round($new));
                if ($new < $buy && !$allowBelow) { $skipped++; continue; }
                $p->update(['selling_price' => $new]);
                $updated++;
            }
        });
        return redirect()->route('products.bulk', $request->only(['type', 'brand_id', 'category_id', 'q']))
            ->with('success', "Harga massal diterapkan: $updated produk diperbarui." . ($skipped ? " $skipped ditolak (di bawah modal)." : ''));
    }

    /** Set merek &/ kategori &/ min. stok global massal: produk tercentang
     *  (maks 100) → update brand_id / category_id / min_stock sekaligus
     *  dalam satu transaction. Tanpa migration; hanya menyentuh kolom
     *  yang diisi. Min. global = products.min_stock; min. per-toko yang
     *  sudah diset tetap menang (efektif = COALESCE(per-toko, global)). */
    public function bulkAssign(Request $request)
    {
        $this->authorizeInventory(true);
        $data = $request->validate([
            'ids' => 'required|array|min:1|max:100',
            'ids.*' => 'exists:products,id',
            'assign_brand_id' => 'nullable|exists:brands,id|required_without_all:assign_category_id,assign_min_stock',
            'assign_category_id' => 'nullable|exists:categories,id|required_without_all:assign_brand_id,assign_min_stock',
            'assign_min_stock' => 'nullable|integer|min:0|max:1000000|required_without_all:assign_brand_id,assign_category_id',
        ], [
            'ids.max' => 'Maksimal 100 produk sekaligus — perkecil pilihan / filter dulu.',
            'assign_brand_id.required_without_all' => 'Pilih merek tujuan, kategori tujuan, dan/atau min. global.',
            'assign_category_id.required_without_all' => 'Pilih merek tujuan, kategori tujuan, dan/atau min. global.',
            'assign_min_stock.required_without_all' => 'Pilih merek tujuan, kategori tujuan, dan/atau min. global.',
        ]);

        $attrs = [];
        if (!empty($data['assign_brand_id'])) {
            $attrs['brand_id'] = (int) $data['assign_brand_id'];
        }
        if (!empty($data['assign_category_id'])) {
            $attrs['category_id'] = (int) $data['assign_category_id'];
        }
        if (isset($data['assign_min_stock']) && $data['assign_min_stock'] !== '' && $data['assign_min_stock'] !== null) {
            $attrs['min_stock'] = (int) $data['assign_min_stock'];
        }
        if ($attrs === []) {
            return back()->withErrors([
                'assign_brand_id' => 'Pilih merek tujuan, kategori tujuan, dan/atau min. global.',
            ])->withInput();
        }

        $count = 0;
        \DB::transaction(function () use ($data, $attrs, &$count) {
            $count = Product::whereIn('id', $data['ids'])->update($attrs);
        });

        $parts = [];
        if (isset($attrs['brand_id'])) {
            $parts[] = 'merek → ' . (Brand::whereKey($attrs['brand_id'])->value('name') ?? ('#' . $attrs['brand_id']));
        }
        if (isset($attrs['category_id'])) {
            $parts[] = 'kategori → ' . (Category::whereKey($attrs['category_id'])->value('name') ?? ('#' . $attrs['category_id']));
        }
        if (isset($attrs['min_stock'])) {
            $parts[] = 'min. global → ' . $attrs['min_stock'];
        }

        return redirect()->route('products.bulk', $request->only(['type', 'brand_id', 'category_id', 'q']))
            ->with('success', 'Massal diterapkan: ' . $count . ' produk (' . implode('; ', $parts) . ').');
    }

    /**
     * Gabung produk duplikat ke satu primary.
     * Pindahkan sale_items, product_stocks (gabung qty bila primary sudah
     * punya stok di toko sama), product_items, purchase_items,
     * service_spareparts, stock_movements ke primary; lengkapi field kosong
     * primary; hapus duplikat. Tanpa migration.
     */
    public function merge(Request $request)
    {
        $this->authorizeInventory(true);
        $data = $request->validate([
            'primary_id' => 'required|exists:products,id',
            'ids' => 'required|array|min:2',
            'ids.*' => 'exists:products,id',
        ]);
        $ids = array_values(array_unique(array_map('intval', $data['ids'])));
        if (!in_array((int) $data['primary_id'], $ids, true)) {
            return back()->withErrors(['primary_id' => 'Primary harus salah satu dari grup duplikat.']);
        }
        self::mergeIdsIntoPrimary((int) $data['primary_id'], $ids);

        return redirect()->route('products.show', (int) $data['primary_id'])->with('success', 'Produk berhasil digabung.');
    }

    /**
     * Inti penggabungan 1 grup ke primary (dipakai merge manual & mergeExact).
     * Melempar InvalidArgumentException bila validasi grup gagal
     * (primary di luar grup / anggota < 2). Jalan dalam 1 DB transaction.
     */
    protected static function mergeIdsIntoPrimary(int $primaryId, array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!in_array($primaryId, $ids, true)) {
            throw new \InvalidArgumentException('Primary harus salah satu dari grup duplikat.');
        }
        $members = Product::whereIn('id', $ids)->get();
        if ($members->count() < 2) {
            throw new \InvalidArgumentException('Pilih minimal 2 produk untuk digabung.');
        }
        $primary = $members->firstWhere('id', $primaryId);
        $dupes = $members->where('id', '!=', $primary->id);
        $dupeIds = $dupes->pluck('id')->all();

        DB::transaction(function () use ($primary, $dupes, $dupeIds) {
            // Lengkapi field kosong primary dari duplikat (tanpa menimpa yang sudah ada)
            foreach ($dupes as $d) {
                if (!$primary->brand_id && $d->brand_id) $primary->brand_id = $d->brand_id;
                if (!$primary->category_id && $d->category_id) $primary->category_id = $d->category_id;
                foreach (['ram', 'storage', 'warna', 'barcode', 'photo'] as $f) {
                    if (!$primary->$f && $d->$f) $primary->$f = $d->$f;
                }
                if ((float) $primary->purchase_price <= 0 && (float) $d->purchase_price > 0) $primary->purchase_price = $d->purchase_price;
                if ((float) $primary->selling_price <= 0 && (float) $d->selling_price > 0) $primary->selling_price = $d->selling_price;
            }
            $primary->save();

            // product_stocks: gabung qty bila primary sudah punya stok di toko sama
            $primaryStocks = ProductStock::where('product_id', $primary->id)->get()->keyBy('store_id');
            $dupeStocks = ProductStock::whereIn('product_id', $dupeIds)->get();
            foreach ($dupeStocks as $s) {
                if (isset($primaryStocks[$s->store_id])) {
                    $primaryStocks[$s->store_id]->increment('qty', (int) $s->qty);
                    $s->delete();
                } else {
                    $s->update(['product_id' => $primary->id]);
                    $primaryStocks[$s->store_id] = $s;
                }
            }

            // Pindahkan relasi ber-FK product_id lainnya
            SaleItem::whereIn('product_id', $dupeIds)->update(['product_id' => $primary->id]);
            ProductItem::whereIn('product_id', $dupeIds)->update(['product_id' => $primary->id]);
            PurchaseItem::whereIn('product_id', $dupeIds)->update(['product_id' => $primary->id]);
            ServiceSparepart::whereIn('product_id', $dupeIds)->update(['product_id' => $primary->id]);
            StockMovement::whereIn('product_id', $dupeIds)->update(['product_id' => $primary->id]);

            foreach ($dupes as $d) {
                $d->delete();
            }
        });
    }

    /** Normalisasi string pembanding: trim + lowercase ('' bila null). */
    protected static function normStr(?string $v): string
    {
        return strtolower(trim((string) ($v ?? '')));
    }

    /**
     * Sidik-jari identitas produk untuk gabung-otomatis.
     * ATURAN KETAT (by design): dua produk dianggap kembar identik HANYA bila
     * SEMUA komponen sama persis:
     *  (1) nama ternormalisasi (nameKey: lowercase alfanumerik),
     *  (2) brand_id (null dianggap nilainya sendiri — null ≠ 5),
     *  (3) type, (4) ram, (5) storage, (6) warna (ketiganya trim+lowercase),
     *  (7) purchase_price + (8) selling_price (dibandingkan sebagai float).
     * SENGAJA DIABAIKAN (tidak ikut sidik-jari): sku, barcode, category_id,
     * photo, min_stock, is_active, is_serialized — karena merge hanya
     * melengkapi field kosong primary tanpa menimpa, stok digabung per toko,
     * dan riwayat dipindah; perbedaan di field-field ini tidak merusak data.
     * Konsekuensi: grup yang beda harga/beda warna/beda RAM TIDAK akan pernah
     * tersentuh tombol otomatis — gabungkan manual per grup bila memang yakin.
     */
    protected static function exactFingerprint(Product $p): string
    {
        return implode('|', [
            self::nameKey((string) $p->name),
            $p->brand_id === null ? 'brand:null' : ('brand:' . (int) $p->brand_id),
            (string) ($p->type ?? ''),
            'ram:' . self::normStr($p->ram),
            'storage:' . self::normStr($p->storage),
            'warna:' . self::normStr($p->warna),
            'beli:' . ((float) ($p->purchase_price ?? 0)),
            'jual:' . ((float) ($p->selling_price ?? 0)),
        ]);
    }

    /**
     * Gabung OTOMATIS produk kembar identik penuh (cermin customers.merge-exact).
     * HANYA grup dari findDuplicateGroups() yang SEMUA anggotanya punya
     * exactFingerprint() sama yang diproses; grup dengan ≥1 anggota beda
     * (harga/warna/RAM/tipe/brand/nama) dilewati + dilaporkan, TIDAK disentuh.
     * Primary per grup = ID terkecil (data tertua). Tiap grup jalan dalam
     * transaction sendiri (via mergeIdsIntoPrimary): 1 grup gagal tidak
     * menggagalkan grup lain; hasil per grup dilaporkan via flash session.
     * POST /products/merge-exact — tanpa migration, tanpa tabel baru.
     */
    public function mergeExact(Request $request)
    {
        $this->authorizeInventory(true);
        $groups = self::findDuplicateGroups();
        if (empty($groups)) {
            return back()->with('error', 'Tidak ada grup kemungkinan duplikat yang perlu digabung.');
        }
        $ok = [];
        $skipped = [];
        $failed = [];
        foreach ($groups as $g) {
            $ids = $g['members']->map(fn ($m) => (int) $m->id)->unique()->values()->all();
            sort($ids);
            $primaryId = $ids[0]; // ID terkecil = master tertua
            $label = 'Nama "' . $g['key'] . '" (ID ' . implode(',', $ids) . ' → primary #' . $primaryId . ')';
            // Muat ulang field pembanding (findDuplicateGroups hanya select
            // id/sku/brand_id/name/type) lalu cek identik-penuh.
            $full = Product::whereIn('id', $ids)->get([
                'id', 'name', 'brand_id', 'type', 'ram', 'storage', 'warna',
                'purchase_price', 'selling_price',
            ]);
            if ($full->count() !== count($ids)) {
                $skipped[] = $label . ' — dilewati: sebagian produk sudah tidak ada (mungkin baru digabung)';
                continue;
            }
            $prints = $full->map(fn ($p) => self::exactFingerprint($p))->unique()->values();
            if ($prints->count() > 1) {
                $skipped[] = $label . ' — dilewati: tidak identik penuh (beda brand/tipe/RAM/storage/warna/harga) — gabungkan manual bila yakin';
                continue;
            }
            try {
                self::mergeIdsIntoPrimary($primaryId, $ids);
                $ok[] = $label . ' — berhasil';
            } catch (\InvalidArgumentException $e) {
                $skipped[] = $label . ' — dilewati: ' . $e->getMessage();
            } catch (\Throwable $e) {
                report($e);
                $failed[] = $label . ' — gagal: ' . $e->getMessage();
            }
        }
        $summary = [
            'berhasil' => count($ok),
            'dilewati' => count($skipped),
            'gagal' => count($failed),
            'details_ok' => $ok,
            'details_skipped' => $skipped,
            'details_failed' => $failed,
        ];
        $msg = 'Gabung otomatis selesai: '
            . count($ok) . ' berhasil, '
            . count($skipped) . ' dilewati, '
            . count($failed) . ' gagal '
            . '(hanya grup identik penuh: nama + brand + tipe + RAM + storage + warna + harga beli + harga jual sama persis).';
        if (count($failed) > 0) {
            return back()->with('merge_exact_summary', $summary)->with('error', $msg);
        }
        return back()->with('merge_exact_summary', $summary)->with('success', $msg);
    }

    /**
     * Duplikat produk (cermin promos.duplicate): copy aman —
     * SKU unik (+ -COPY), barcode & photo ikut, stok TIDAK ikut
     * (tanpa ProductStock/ProductItem baru = mulai 0), is_active=false
     * → lanjut ke edit. Tanpa migration.
     */
    public function duplicate(Product $product)
    {
        $this->authorizeInventory(true);
        $base = substr($product->sku . '-COPY', 0, 250);
        $sku = $base;
        $i = 2;
        while (Product::where('sku', $sku)->exists()) {
            $suffix = '-' . $i;
            $sku = substr($base, 0, 250 - strlen($suffix)) . $suffix;
            $i++;
            if ($i > 9999) {
                break;
            }
        }
        $copy = $product->replicate();
        $copy->sku = $sku;
        $copy->barcode = $product->barcode;
        $copy->photo = $product->photo;
        $copy->is_active = false;
        $copy->save();

        return redirect()
            ->route('products.edit', $copy)
            ->with('success', 'Produk diduplikat sebagai ' . $sku . ' (stok 0, nonaktif). Sesuaikan data lalu aktifkan.');
    }

    public function destroy(Product $product)
    {
        $this->authorizeInventory(true);
        abort_if($product->items()->whereNotIn('status', ['terjual', 'retur'])->exists(), 422, 'Produk masih punya unit aktif, tidak bisa dihapus.');
        // FK sale_items/purchase_items/service_spareparts/stock_movements → product_id
        // = cascadeOnDelete: hapus produk akan melenyapkan baris RIWAYAT. Blokir bila
        // sudah pernah dipakai transaksi — nonaktifkan (is_active=false) sebagai gantinya.
        abort_if(SaleItem::where('product_id', $product->id)->exists(), 422, 'Produk sudah dipakai di riwayat penjualan, tidak bisa dihapus. Nonaktifkan saja.');
        abort_if(PurchaseItem::where('product_id', $product->id)->exists(), 422, 'Produk sudah dipakai di riwayat pembelian, tidak bisa dihapus. Nonaktifkan saja.');
        abort_if(ServiceSparepart::where('product_id', $product->id)->exists(), 422, 'Produk sudah dipakai sebagai sparepart service, tidak bisa dihapus. Nonaktifkan saja.');
        abort_if(StockMovement::where('product_id', $product->id)->exists(), 422, 'Produk punya riwayat mutasi stok, tidak bisa dihapus. Nonaktifkan saja.');
        abort_if(ProductStock::where('product_id', $product->id)->where('qty', '>', 0)->exists(), 422, 'Produk masih punya sisa stok, habiskan/pindahkan dulu atau nonaktifkan saja.');
        $photo = $product->photo;
        $product->delete();
        if ($photo) {
            try { \Storage::disk('public')->delete($photo); } catch (\Throwable $e) { report($e); }
        }
        return redirect()->route('products.index')->with('success', 'Produk dihapus.');
    }
}
