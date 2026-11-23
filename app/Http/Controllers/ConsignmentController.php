<?php

namespace App\Http\Controllers;

use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\Consignment;
use App\Models\Product;
use App\Models\ProductItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConsignmentController extends Controller
{
    public function index(Request $request)
    {
        $storeId = (int) session('active_store_id');
        $showAll = $request->boolean('all') && (auth()->user()->role ?? '') === 'superadmin';
        $base = Consignment::with(['product', 'productItem', 'supplier', 'store'])
            ->when(!$showAll && $storeId, fn($q) => $q->where('store_id', $storeId))
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->status))
            ->orderByDesc('id');
        // Export CSV (?export=csv) — hormati filter status + scope toko, delimiter ; + BOM.
        if ($request->get('export') === 'csv') {
            $rows = (clone $base)->limit(5000)->get();
            $filename = 'konsinyasi-' . now()->format('Ymd-His') . '.csv';
            return response()->streamDownload(function () use ($rows) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['id', 'kode', 'tanggal', 'toko', 'produk', 'imei', 'penitip', 'titip', 'jual', 'komisi', 'status'], ';');
                foreach ($rows as $c) {
                    fputcsv($out, [$c->id, $c->code, $c->created_at?->format('Y-m-d H:i') ?? '', $c->store->name ?? '', $c->product->name ?? '', $c->productItem->imei ?? '', $c->supplier->name ?? $c->customer_name ?? '', (float) $c->titip_price, (float) $c->jual_price, (float) $c->commission, $c->status], ';');
                }
                fclose($out);
            }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }
        $consignments = (clone $base)->paginate(20)->withQueryString();
        $products = Product::where('is_active', true)->orderBy('name')->limit(300)->get();
        $suppliers = Supplier::when(!$showAll && $storeId, fn($q) => $q->where('store_id', $storeId))->orderBy('name')->get();
        $from = $request->input('from', now()->startOfMonth()->format('Y-m-d'));
        $to = $request->input('to', now()->format('Y-m-d'));
        $sold = Consignment::query()
            ->when(!$showAll && $storeId, fn($q) => $q->where('store_id', $storeId))
            ->where('status', 'terjual')
            ->whereDate('updated_at', '>=', $from)->whereDate('updated_at', '<=', $to)->get();
        $margin = ['count' => $sold->count(), 'jual' => (float) $sold->sum('jual_price'),
            'titip' => (float) $sold->sum('titip_price'), 'komisi' => (float) $sold->sum('commission')];
        $margin['toko'] = $margin['jual'] - $margin['titip'] - $margin['komisi'];
        $cashAccounts = CashAccount::when($storeId, fn($q) => $q->where('store_id', $storeId))->orderByDesc('is_default')->orderBy('name')->get();
        $settledIds = CashTransaction::where('ref_type', 'consignment_settle')
            ->whereIn('ref_id', $consignments->pluck('id'))->pluck('ref_id')->all();
        // Bukti settle opsional: marker [BUKTI:path] di description kas keluar
        // (ref_type=consignment_settle) — tanpa migration/route baru.
        $settleBuktiByConsignment = [];
        $settleTx = CashTransaction::where('ref_type', 'consignment_settle')
            ->whereIn('ref_id', $consignments->pluck('id'))
            ->get(['ref_id', 'description']);
        foreach ($settleTx as $stx) {
            if (preg_match('/\[BUKTI:([^\]]+)\]/', (string) $stx->description, $mb)) {
                $settleBuktiByConsignment[(int) $stx->ref_id] = trim($mb[1]);
            }
        }
        // Petakan foto titipan dari marker [FOTO:path] di note mutasi stok
        // (ref_type=consignment) — tanpa migration/route baru.
        $photosByConsignment = [];
        $consignmentIds = $consignments->pluck('id')->all();
        if ($consignmentIds) {
            $photoMovements = StockMovement::where('ref_type', 'consignment')
                ->whereIn('ref_id', $consignmentIds)
                ->get(['ref_id', 'note']);
            foreach ($photoMovements as $m) {
                if (preg_match_all('/\[FOTO:([^\]]+)\]/', (string) $m->note, $mm)) {
                    foreach ($mm[1] as $pp) {
                        $photosByConsignment[(int) $m->ref_id][] = trim($pp);
                    }
                }
            }
        }
        return view('consignments.index', compact('consignments', 'products', 'suppliers', 'showAll', 'margin', 'from', 'to', 'cashAccounts', 'settledIds', 'photosByConsignment', 'settleBuktiByConsignment'));
    }

    public function store(Request $request)
    {
        $storeId = (int) session('active_store_id');
        abort_if(!$storeId, 422, 'Toko aktif belum dipilih.');

        $data = $request->validate([
            'supplier_id' => 'nullable|exists:suppliers,id',
            'customer_name' => 'nullable|string|max:200',
            'product_id' => 'required|exists:products,id',
            'imei' => 'nullable|string|max:50|unique:product_items,imei',
            'titip_price' => 'required|numeric|min:0',
            'jual_price' => 'required|numeric|min:0',
            'commission' => 'nullable|numeric|min:0',
            'photos' => 'required|array|min:1|max:2',
            'photos.*' => 'required|image|max:2048',
        ], [
            'product_id.required' => 'Pilih produk dulu.',
            'product_id.exists' => 'Produk tidak ditemukan.',
            'imei.unique' => 'IMEI ini sudah terdaftar di stok.',
            'titip_price.required' => 'Harga titip wajib diisi.',
            'jual_price.required' => 'Harga jual wajib diisi.',
            'photos.required' => 'Foto bukti konsinyasi wajib diisi (minimal 1 foto).',
            'photos.min' => 'Foto bukti konsinyasi wajib diisi (minimal 1 foto).',
            'photos.*.required' => 'Foto bukti konsinyasi wajib diisi (minimal 1 foto).',
            'photos.*.image' => 'Foto bukti harus berupa gambar.',
            'photos.*.max' => 'Ukuran tiap foto maksimal 2 MB.',
        ]);

        DB::transaction(function () use ($data, $storeId, $request) {
            // Pola foto = ServiceController@store: file ke disk public (maks 2 @2MB),
            // path dilacak via marker [FOTO:path] di note mutasi stok
            // (consignments tanpa migration tak punya kolom note/foto).
            $photoPaths = [];
            if ($request->hasFile('photos')) {
                foreach ((array) $request->file('photos') as $f) {
                    if (!$f || !$f->isValid()) {
                        continue;
                    }
                    if (count($photoPaths) >= 2) {
                        break;
                    }
                    $photoPaths[] = $f->store('consignment', 'public');
                }
            }
            // Kode unik: {KODETOKO-}KONS-YYYYMMDD-XXXX (prefix kode toko di
            // depan bila stores.code terisi, fallback format lama bila kosong).
            $prefix = \App\Support\DocNumber::prefixForStore($storeId) . 'KONS-' . now()->format('Ymd') . '-';
            do {
                $code = $prefix . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            } while (Consignment::where('code', $code)->exists());

            $unit = ProductItem::create([
                'product_id' => $data['product_id'], 'store_id' => $storeId,
                'imei' => $data['imei'] ?? null, 'condition' => 'konsinyasi',
                'purchase_price' => $data['titip_price'], 'selling_price' => $data['jual_price'],
                'status' => 'konsinyasi', 'supplier_id' => $data['supplier_id'] ?? null,
            ]);

            $consignment = Consignment::create([
                'code' => $code, 'store_id' => $storeId,
                'supplier_id' => $data['supplier_id'] ?? null,
                'customer_name' => $data['customer_name'] ?? null,
                'product_id' => $data['product_id'], 'product_item_id' => $unit->id,
                'titip_price' => $data['titip_price'], 'jual_price' => $data['jual_price'],
                'commission' => $data['commission'] ?? 0, 'status' => 'aktif',
            ]);

            $unit->update(['consignment_id' => $consignment->id]);

            $movementNote = 'Titipan ' . $code;
            if ($photoPaths) {
                $movementNote .= ' ' . implode(' ', array_map(fn($p) => '[FOTO:' . $p . ']', $photoPaths));
            }
            StockMovement::create([
                'store_id' => $storeId, 'product_id' => $data['product_id'], 'product_item_id' => $unit->id,
                'type' => 'masuk', 'qty' => 1, 'ref_type' => 'consignment', 'ref_id' => $consignment->id,
                'note' => mb_substr($movementNote, 0, 1000), 'user_id' => auth()->id(),
            ]);
        });

        return back()->with('success', 'Titipan konsinyasi berhasil dicatat.');
    }

    public function update(Request $request, Consignment $consignment)
    {
        $storeId = (int) session('active_store_id');
        if ($storeId) abort_if($consignment->store_id !== $storeId, 403, 'Bukan data toko aktif.');

        $data = $request->validate([
            'status' => 'required|in:aktif,terjual,kembali',
        ]);

        DB::transaction(function () use ($data, $consignment) {
            $consignment->update(['status' => $data['status']]);
            $unit = $consignment->productItem;
            if ($unit) {
                if ($data['status'] === 'terjual') {
                    $unit->update(['status' => 'terjual']);
                    StockMovement::create([
                        'store_id' => $unit->store_id, 'product_id' => $unit->product_id,
                        'product_item_id' => $unit->id, 'type' => 'keluar', 'qty' => 1,
                        'ref_type' => 'consignment', 'ref_id' => $consignment->id,
                        'note' => 'Konsinyasi terjual ' . $consignment->code, 'user_id' => auth()->id(),
                    ]);
                } elseif ($data['status'] === 'kembali') {
                    $unit->update(['status' => 'retur']);
                    StockMovement::create([
                        'store_id' => $unit->store_id, 'product_id' => $unit->product_id,
                        'product_item_id' => $unit->id, 'type' => 'keluar', 'qty' => 1,
                        'ref_type' => 'consignment', 'ref_id' => $consignment->id,
                        'note' => 'Konsinyasi dikembalikan ' . $consignment->code, 'user_id' => auth()->id(),
                    ]);
                } elseif ($data['status'] === 'aktif') {
                    $unit->update(['status' => 'konsinyasi']);
                }
            }
        });

        return back()->with('success', 'Status konsinyasi diperbarui.');
    }

    public function settle(Request $request, Consignment $consignment)
    {
        $storeId = (int) session('active_store_id');
        if ($storeId) abort_if($consignment->store_id !== $storeId, 403, 'Bukan data toko aktif.');
        abort_if($consignment->status === 'kembali', 422, 'Konsinyasi sudah dikembalikan.');
        abort_if(CashTransaction::where('ref_type', 'consignment_settle')->where('ref_id', $consignment->id)->exists(), 422, 'Konsinyasi ini sudah di-settle.');

        $data = $request->validate([
            'cash_account_id' => 'required|exists:cash_accounts,id',
            'jual_price' => 'nullable|numeric|min:0',
            'commission' => 'nullable|numeric|min:0',
            'bukti' => 'nullable|image|max:2048',
        ]);

        // GATE KAS KELUAR (Continuous 168): samakan CashController@store —
        // settle = kas keluar (titip + komisi). Nominal > Rp10.000.000 hanya
        // owner/admin/superadmin + wajib foto bukti. Tanpa migration.
        $jualPreview = (float) ($data['jual_price'] ?? $consignment->jual_price);
        $marginPreview = $jualPreview - (float) $consignment->titip_price;
        $commissionPreview = array_key_exists('commission', $data) && $data['commission'] !== null
            ? (float) $data['commission']
            : ($marginPreview > 0 ? round($marginPreview * 0.10, 2) : 0);
        $payPreview = (float) $consignment->titip_price + $commissionPreview;
        if ($payPreview > 10000000) {
            $roleNow = $request->user()?->role ?? auth()->user()->role ?? '';
            if (! in_array($roleNow, ['superadmin', 'owner', 'admin'], true)) {
                abort(422, 'Settle di atas Rp10.000.000 hanya boleh diproses owner/admin/superadmin. Minta owner/admin/superadmin yang login lalu ulangi settle.');
            }
            if (! $request->hasFile('bukti')) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'bukti' => 'Settle di atas Rp10.000.000 wajib melampirkan foto bukti (kwitansi/transfer ke penitip, maks 2 MB).',
                ]);
            }
        }

        DB::transaction(function () use ($data, $consignment, $request) {
            $consignment->load('productItem');
            $jual = (float) ($data['jual_price'] ?? $consignment->jual_price);
            $margin = $jual - (float) $consignment->titip_price;
            $commission = array_key_exists('commission', $data) && $data['commission'] !== null
                ? (float) $data['commission']
                : ($margin > 0 ? round($margin * 0.10, 2) : 0);
            $consignment->update(['status' => 'terjual', 'jual_price' => $jual, 'commission' => $commission]);

            if ($consignment->productItem && $consignment->productItem->status !== 'terjual') {
                $consignment->productItem->update(['status' => 'terjual']);
                StockMovement::create([
                    'store_id' => $consignment->store_id, 'product_id' => $consignment->product_id,
                    'product_item_id' => $consignment->product_item_id, 'type' => 'keluar', 'qty' => 1,
                    'ref_type' => 'consignment', 'ref_id' => $consignment->id,
                    'note' => 'Konsinyasi terjual (settle) ' . $consignment->code, 'user_id' => auth()->id(),
                ]);
            }

            $account = CashAccount::whereKey($data['cash_account_id'])
                ->where('store_id', $consignment->store_id)->lockForUpdate()->firstOrFail();
            $pay = (float) $consignment->titip_price + $commission;
            abort_if((float) $account->balance < $pay, 422, 'Saldo akun kas tidak mencukupi untuk settle Rp ' . number_format($pay, 0, ',', '.') . '.');
            $account->decrement('balance', $pay);
            // Bukti settle opsional: file ke disk public (maks 2 MB),
            // path dilacak via marker [BUKTI:path] di description kas keluar.
            $settleDesc = 'Settle ' . $consignment->code . ' ke penitip (titip Rp ' . number_format($consignment->titip_price, 0, ',', '.') . ' + komisi Rp ' . number_format($commission, 0, ',', '.') . ')';
            if ($request->hasFile('bukti')) {
                $f = $request->file('bukti');
                if ($f && $f->isValid()) {
                    $buktiPath = $f->store('bukti-kas', 'public');
                    $settleDesc .= ' [BUKTI:' . $buktiPath . ']';
                }
            }
            CashTransaction::create([
                'store_id' => $consignment->store_id, 'cash_account_id' => $account->id, 'type' => 'keluar',
                'category' => 'operasional', 'amount' => $pay,
                'ref_type' => 'consignment_settle', 'ref_id' => $consignment->id,
                'description' => $settleDesc,
                'user_id' => auth()->id(),
            ]);
        });

        return back()->with('success', 'Settle ' . $consignment->code . ' berhasil: kas keluar ke penitip tercatat.');
    }
}
