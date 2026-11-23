<?php

namespace App\Http\Controllers;

use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductItem;
use App\Models\SecondhandPurchase;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SecondhandController extends Controller
{
    public function index(Request $request)
    {
        $storeId = (int) session('active_store_id');
        $showAll = $request->boolean('all') && (auth()->user()->role ?? '') === 'superadmin';
        $base = SecondhandPurchase::with(['customer', 'product', 'store'])
            ->when(!$showAll && $storeId, fn($q) => $q->where('store_id', $storeId))
            ->orderByDesc('id');
        // Export CSV (?export=csv) — hormati scope toko, delimiter ; + BOM.
        if ($request->get('export') === 'csv') {
            $rows = (clone $base)->limit(5000)->get();
            $filename = 'hp-bekas-' . now()->format('Ymd-His') . '.csv';
            return response()->streamDownload(function () use ($rows) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['id', 'tanggal', 'toko', 'pelanggan', 'produk', 'imei', 'grade', 'harga_beli', 'rencana_jual', 'status'], ';');
                foreach ($rows as $p) {
                    fputcsv($out, [$p->id, $p->created_at?->format('Y-m-d H:i') ?? '', $p->store->name ?? '', $p->customer->name ?? '', $p->product->name ?? '', $p->imei ?? '', $p->condition_grade, (float) $p->buy_price, (float) $p->sell_price_plan, $p->status], ';');
                }
                fclose($out);
            }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }
        $purchases = (clone $base)->paginate(20)->withQueryString();
        $customers = Customer::when(!$showAll && $storeId, fn($q) => $q->where('store_id', $storeId))->orderBy('name')->get();
        $products = Product::whereIn('type', ['hp_bekas', 'refurbished'])->where('is_active', true)->orderBy('name')->limit(300)->get();
        // Margin aktual HP bekas: petakan tiap pembelian → unit (product_items via IMEI) → sale_items via product_item_id.
        $collection = $purchases->getCollection();
        $imeis = $collection->map(fn($p) => $p->imei)->filter()->values()->all();
        $unitsByImei = $imeis
            ? \App\Models\ProductItem::whereIn('imei', $imeis)
                ->when(!$showAll && $storeId, fn($q) => $q->where('store_id', $storeId))
                ->get()->keyBy('imei')
            : collect();
        $unitIds = $unitsByImei->pluck('id')->all();
        $salesByUnit = $unitIds
            ? \App\Models\SaleItem::whereIn('product_item_id', $unitIds)->orderBy('id')->get()->keyBy('product_item_id')
            : collect();
        // Fallback unit untuk pembelian tanpa IMEI: unit terbaru produk+toko yang sama.
        $missing = $collection->filter(fn($p) => !$p->imei || !isset($unitsByImei[$p->imei]));
        $fallbackByKey = [];
        if ($missing->isNotEmpty()) {
            $pids = $missing->map(fn($p) => $p->product_id)->filter()->values()->all();
            if ($pids) {
                $candidates = \App\Models\ProductItem::whereIn('product_id', $pids)
                    ->when(!$showAll && $storeId, fn($q) => $q->where('store_id', $storeId))
                    ->orderByDesc('id')->get();
                foreach ($candidates as $u) {
                    $k = $u->store_id . '-' . $u->product_id;
                    if (!isset($fallbackByKey[$k])) $fallbackByKey[$k] = $u;
                }
                $fbIds = collect($fallbackByKey)->pluck('id')->all();
                if ($fbIds) {
                    $fbSales = \App\Models\SaleItem::whereIn('product_item_id', $fbIds)->orderBy('id')->get()->keyBy('product_item_id');
                    foreach ($fbSales as $uid => $s) {
                        if (!isset($salesByUnit[$uid])) $salesByUnit[$uid] = $s;
                    }
                }
            }
        }
        foreach ($collection as $p) {
            $unit = ($p->imei && isset($unitsByImei[$p->imei])) ? $unitsByImei[$p->imei] : ($fallbackByKey[$p->store_id . '-' . $p->product_id] ?? null);
            $sale = ($unit && isset($salesByUnit[$unit->id])) ? $salesByUnit[$unit->id] : null;
            $actual = $sale ? (float) ($sale->subtotal ?: $sale->price) : null;
            $p->unit_status = $unit?->status;
            $p->actual_sell = $actual;
            $p->actual_margin = $actual !== null ? $actual - (float) $p->buy_price : null;
        }
        // Petakan foto barang dari marker [FOTO:path] di note mutasi stok
        // (ref_type=secondhand, ref_id=purchase id) — tanpa migration/route baru.
        $photosByPurchase = [];
        $purchaseIds = $collection->map(fn($p) => $p->id)->all();
        if ($purchaseIds) {
            $photoMovements = StockMovement::where('ref_type', 'secondhand')
                ->whereIn('ref_id', $purchaseIds)
                ->get(['ref_id', 'note']);
            foreach ($photoMovements as $m) {
                if (preg_match_all('/\[FOTO:([^\]]+)\]/', (string) $m->note, $mm)) {
                    foreach ($mm[1] as $pp) {
                        $photosByPurchase[(int) $m->ref_id][] = trim($pp);
                    }
                }
            }
        }
        return view('secondhand.index', compact('purchases', 'customers', 'products', 'showAll', 'photosByPurchase'));
    }

    public function store(Request $request)
    {
        $storeId = (int) session('active_store_id');
        abort_if(!$storeId, 422, 'Toko aktif belum dipilih.');

        $data = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'product_id' => 'nullable|exists:products,id',
            'product_name' => 'required_without:product_id|nullable|string|max:200',
            'imei' => 'nullable|string|max:50|unique:product_items,imei',
            'condition_grade' => 'required|in:A,B,C,D',
            'buy_price' => 'required|numeric|min:0',
            'sell_price_plan' => 'required|numeric|min:0',
            'cash_account_id' => 'nullable|exists:cash_accounts,id',
            'photos' => 'required|array|min:1|max:2',
            'photos.*' => 'required|image|max:2048',
            'market_price' => 'nullable|numeric|min:0',
            'ck_layar' => 'nullable|in:baik,kurang,rusak',
            'ck_baterai' => 'nullable|in:baik,kurang,rusak',
            'ck_tombol' => 'nullable|in:baik,kurang,rusak',
            'ck_sinyal' => 'nullable|in:baik,kurang,rusak',
            'ck_kelengkapan' => 'nullable|in:baik,kurang,rusak',
        ], [
            'photos.required' => 'Foto bukti pembelian HP bekas wajib diisi (minimal 1 foto).',
            'photos.min' => 'Foto bukti pembelian HP bekas wajib diisi (minimal 1 foto).',
            'photos.*.required' => 'Foto bukti pembelian HP bekas wajib diisi (minimal 1 foto).',
            'photos.*.image' => 'Foto bukti harus berupa gambar.',
            'photos.*.max' => 'Ukuran tiap foto maksimal 2 MB.',
        ]);

        // GATE KAS KELUAR (Continuous 169): samakan CashController@store —
        // beli HP bekas = kas keluar buy_price. Nominal > Rp10.000.000 hanya
        // owner/admin/superadmin + bukti foto WAJIB. Tanpa migration & tanpa
        // field bukti terpisah: foto kondisi (photos, min 1, sudah required di
        // validasi) dipakai sebagai bukti — path foto pertama dicatat
        // [BUKTI:path] di description kas keluar (pola CashController@store).
        $buyPreview = (float) ($data['buy_price'] ?? 0);
        if ($buyPreview > 10000000) {
            $roleNow = $request->user()?->role ?? auth()->user()->role ?? '';
            if (! in_array($roleNow, ['superadmin', 'owner', 'admin'], true)) {
                abort(422, 'Beli HP bekas di atas Rp10.000.000 hanya boleh diproses owner/admin/superadmin. Minta owner/admin/superadmin yang login lalu ulangi pencatatan.');
            }
            if (! $request->hasFile('photos')) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'photos' => 'Beli HP bekas di atas Rp10.000.000 wajib melampirkan foto bukti (foto kondisi unit, minimal 1 foto).',
                ]);
            }
        }

        $guide = $this->appraisalGuide($data);
        $checkSummary = '';
        if ($guide) {
            $checkSummary = ' [Skor ' . $guide['score'] . '/100 • Pasar Rp ' . number_format($guide['market'], 0, ',', '.')
                . ' → saran Rp ' . number_format($guide['suggestion'], 0, ',', '.') . '] '
                . 'Layar:' . $guide['labels']['ck_layar'] . '; Baterai:' . $guide['labels']['ck_baterai']
                . '; Tombol:' . $guide['labels']['ck_tombol'] . '; Sinyal:' . $guide['labels']['ck_sinyal']
                . '; Kelengkapan:' . $guide['labels']['ck_kelengkapan'];
        }

        DB::transaction(function () use ($data, $storeId, $checkSummary, $request) {
            // Pola foto = ServiceController@store: file ke disk public (maks 2 @2MB),
            // path dilacak via marker [FOTO:path] di note mutasi stok
            // (secondhand_purchases tanpa migration tak punya kolom note/foto).
            $photoPaths = [];
            if ($request->hasFile('photos')) {
                foreach ((array) $request->file('photos') as $f) {
                    if (!$f || !$f->isValid()) {
                        continue;
                    }
                    if (count($photoPaths) >= 2) {
                        break;
                    }
                    $photoPaths[] = $f->store('secondhand', 'public');
                }
            }
            $productId = $data['product_id'] ?? null;
            if (!$productId) {
                $product = Product::create([
                    'sku' => 'BK-' . strtoupper(Str::random(6)),
                    'name' => $data['product_name'], 'type' => 'hp_bekas',
                    'purchase_price' => $data['buy_price'], 'selling_price' => $data['sell_price_plan'],
                    'is_serialized' => true, 'is_active' => true,
                ]);
                $productId = $product->id;
            }

            $purchase = SecondhandPurchase::create([
                'store_id' => $storeId, 'customer_id' => $data['customer_id'] ?? null,
                'product_id' => $productId, 'imei' => $data['imei'] ?? null,
                'condition_grade' => $data['condition_grade'],
                'buy_price' => $data['buy_price'], 'sell_price_plan' => $data['sell_price_plan'],
                'status' => 'dibeli', 'user_id' => auth()->id(),
            ]);

            $unit = ProductItem::create([
                'product_id' => $productId, 'store_id' => $storeId,
                'imei' => $data['imei'] ?? null, 'condition' => 'bekas',
                'purchase_price' => $data['buy_price'], 'selling_price' => $data['sell_price_plan'],
                'status' => 'tersedia',
            ]);

            $movementNote = mb_substr('Beli HP bekas grade ' . $data['condition_grade'] . $checkSummary, 0, 400);
            if ($photoPaths) {
                $movementNote .= ' ' . implode(' ', array_map(fn($p) => '[FOTO:' . $p . ']', $photoPaths));
            }
            StockMovement::create([
                'store_id' => $storeId, 'product_id' => $productId, 'product_item_id' => $unit->id,
                'type' => 'masuk', 'qty' => 1, 'ref_type' => 'secondhand', 'ref_id' => $purchase->id,
                'note' => mb_substr($movementNote, 0, 1000), 'user_id' => auth()->id(),
            ]);

            $buyPrice = (float) $data['buy_price'];
            if ($buyPrice > 0) {
                $accountId = $data['cash_account_id'] ?? null;
                $account = $accountId
                    ? CashAccount::whereKey($accountId)->where('store_id', $storeId)->lockForUpdate()->first()
                    : CashAccount::where('store_id', $storeId)->orderByDesc('is_default')->lockForUpdate()->first();
                abort_if(!$account, 422, 'Akun kas toko aktif belum ada, buat dulu di menu Kas.');
                abort_if((float) $account->balance < $buyPrice, 422, 'Saldo akun kas tidak mencukupi untuk pembelian HP bekas Rp ' . number_format($buyPrice, 0, ',', '.') . '.');
                $account->decrement('balance', $buyPrice);
                // Foto kondisi = bukti kas (tanpa migration): path foto pertama
                // dicatat [BUKTI:path] di description kas keluar.
                $cashDesc = 'Beli HP bekas ' . ($data['imei'] ?? '');
                if (!empty($photoPaths)) {
                    $cashDesc .= ' [BUKTI:' . $photoPaths[0] . ']';
                }
                CashTransaction::create([
                    'store_id' => $storeId, 'cash_account_id' => $account->id, 'type' => 'keluar',
                    'category' => 'pembelian', 'amount' => $buyPrice,
                    'ref_type' => 'secondhand', 'ref_id' => $purchase->id,
                    'description' => $cashDesc, 'user_id' => auth()->id(),
                ]);
            }
        });

        return back()->with('success', 'Pembelian HP bekas berhasil dicatat.');
    }

    /** Penilaian terpandu: skor 0-100 → grade → saran harga. Null bila form panduan tak dipakai. */
    protected function appraisalGuide(array $data): ?array
    {
        $keys = ['ck_layar', 'ck_baterai', 'ck_tombol', 'ck_sinyal', 'ck_kelengkapan'];
        $used = !empty($data['market_price']) || array_filter(array_intersect_key($data, array_flip($keys)));
        if (!$used) return null;
        $w = ['baik' => 20, 'kurang' => 10, 'rusak' => 0];
        $labels = [];
        $score = 0;
        $labelMap = ['baik' => 'Baik', 'kurang' => 'Kurang', 'rusak' => 'Rusak'];
        foreach ($keys as $k) {
            $v = $data[$k] ?? 'kurang';
            $score += $w[$v] ?? 10;
            $labels[$k] = $labelMap[$v] ?? $v;
        }
        $grade = $score >= 90 ? 'A' : ($score >= 75 ? 'B' : ($score >= 55 ? 'C' : 'D'));
        $pct = ['A' => 0.80, 'B' => 0.65, 'C' => 0.50, 'D' => 0.35][$grade];
        $market = (float) ($data['market_price'] ?? 0);
        return ['score' => $score, 'grade' => $grade, 'pct' => $pct, 'market' => $market,
            'suggestion' => round($market * $pct), 'labels' => $labels];
    }

    public function update(Request $request, SecondhandPurchase $secondhand)
    {
        $storeId = (int) session('active_store_id');
        if ($storeId) abort_if($secondhand->store_id !== $storeId, 403, 'Bukan data toko aktif.');

        $data = $request->validate([
            'status' => 'required|in:dibeli,dijual,retur',
            'sell_price_plan' => 'nullable|numeric|min:0',
        ]);

        DB::transaction(function () use ($data, $secondhand) {
            $secondhand->update([
                'status' => $data['status'],
                'sell_price_plan' => $data['sell_price_plan'] ?? $secondhand->sell_price_plan,
            ]);
            if ($secondhand->imei) {
                $unit = ProductItem::where('store_id', $secondhand->store_id)->where('imei', $secondhand->imei)->first();
                if ($unit) {
                    if ($data['status'] === 'dijual') {
                        abort_if($unit->status === 'terjual', 422, 'Unit IMEI ' . $secondhand->imei . ' sudah terjual.');
                        $unit->update(['status' => 'terjual']);
                        StockMovement::create([
                            'store_id' => $unit->store_id, 'product_id' => $unit->product_id,
                            'product_item_id' => $unit->id, 'type' => 'keluar', 'qty' => 1,
                            'ref_type' => 'secondhand', 'ref_id' => $secondhand->id,
                            'note' => 'HP bekas terjual', 'user_id' => auth()->id(),
                        ]);
                    } elseif ($data['status'] === 'dibeli') {
                        $unit->update(['status' => 'tersedia']);
                    }
                }
            }
        });

        return back()->with('success', 'Status pembelian bekas diperbarui.');
    }
}
