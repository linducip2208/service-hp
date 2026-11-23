<?php

namespace App\Http\Controllers;

use App\Models\CashTransaction;
use App\Models\CashAccount;
use App\Models\Customer;
use App\Models\Debt;
use App\Models\Product;
use App\Models\ProductItem;
use App\Models\ProductStock;
use App\Models\Promo;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\TradeIn;
use App\Models\Warranty;
use App\Services\WaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PosController extends Controller
{
    protected function posRoles(): array
    {
        return ['superadmin', 'owner', 'admin', 'kasir'];
    }

    protected function authorizePos(): void
    {
        $role = auth()->user()->role ?? '';
        abort_unless(in_array($role, $this->posRoles()), 403, 'Hanya kasir/admin/owner/superadmin yang boleh mengakses POS.');
    }

    protected function activeStoreId(): int
    {
        return (int) session('active_store_id');
    }

    public function index(Request $request)
    {
        $this->authorizePos();
        $storeId = $this->activeStoreId();
        abort_if(!$storeId, 422, 'Toko aktif belum dipilih.');

        $q = trim((string) $request->get('q', ''));

        // Scan kartu member (tanpa route baru): ?scan_member=KODE → resolve ke customer
        // (member_code exact case-insensitive, fallback phone exact/normalisasi)
        // → reload POS dengan ?customer_id= existing + flash bila tidak ketemu.
        if ($request->filled('scan_member')) {
            $code = trim((string) $request->get('scan_member'));
            $found = Customer::where('store_id', $storeId)
                ->whereRaw('UPPER(member_code) = ?', [mb_strtoupper($code)])->first();
            if (!$found) {
                $found = Customer::where('store_id', $storeId)->where('phone', $code)->first();
            }
            if (!$found) {
                try {
                    $norm = \App\Http\Controllers\CustomerController::normalizePhone($code);
                } catch (\Throwable $e) {
                    $norm = null;
                }
                if ($norm) {
                    $found = Customer::where('store_id', $storeId)->where('phone', $norm)->first();
                }
            }
            if ($found) {
                return redirect()->route('pos.index', array_filter(['q' => $q, 'customer_id' => $found->id]))
                    ->with('success', 'Member ' . $found->name . ' (' . ($found->member_code ?? '-') . ') terpilih.');
            }
            return redirect()->route('pos.index', array_filter(['q' => $q] + ($request->filled('customer_id') ? ['customer_id' => $request->get('customer_id')] : [])))
                ->with('error', 'Kartu member "' . $code . '" tidak ditemukan (cek member_code / no. HP).');
        }

        // Cek promo via query string (tanpa route baru): ?check_promo=KODE&subtotal=N[&customer_id=ID]
        // Dipakai tombol "Cek Promo" di POS. JSON bila diminta via Accept/AJAX, selain itu redirect dengan flash.
        if ($request->filled('check_promo')) {
            $code = trim((string) $request->get('check_promo'));
            $subtotal = (float) $request->get('subtotal', 0);
            $distinct = max(1, (int) $request->get('distinct', 1));
            $customer = $request->filled('customer_id')
                ? Customer::where('store_id', $storeId)->find($request->get('customer_id')) : null;
            [$promo, $discount, $error] = $this->checkPromo($code, $subtotal, $storeId, $customer, $distinct);
            $payload = $promo
                ? ['valid' => true, 'code' => $promo->code, 'name' => $promo->name, 'type' => $promo->type,
                    'jenis' => self::promoTypeLabel($promo->type), 'syarat' => self::promoSyarat($promo),
                    'discount' => (float) $discount,
                    'message' => 'Promo ' . $promo->code . ' (' . self::promoTypeLabel($promo->type) . ') valid — hemat Rp ' . number_format($discount, 0, ',', '.')]
                : ['valid' => false, 'discount' => 0, 'message' => $error];
            if ($request->expectsJson() || $request->wantsJson() || $request->ajax()) {
                return response()->json($payload, $payload['valid'] ? 200 : 422);
            }
            return redirect()->route('pos.index', array_filter(['q' => $q]))
                ->with($payload['valid'] ? 'success' : 'error', $payload['message']);
        }

        // Hapus slot struk ditahan (via query string, tanpa route tambahan)
        if ($request->filled('drop_held')) {
            $held = session('pos_held', []);
            unset($held[$request->get('drop_held')]);
            session(['pos_held' => $held]);
            return redirect()->route('pos.index')->with('success', 'Struk ditahan dihapus.');
        }

        // Lanjutkan slot struk ditahan: muat ke keranjang & keluarkan dari slot
        $resumeCart = null;
        $resumeName = '';
        $resumeNote = '';
        if ($request->filled('resume')) {
            $held = session('pos_held', []);
            $key = (string) $request->get('resume');
            if (isset($held[$key])) {
                $resumeCart = array_values($held[$key]['items'] ?? []);
                $resumeName = $held[$key]['customer_name'] ?? '';
                $resumeNote = $held[$key]['note'] ?? '';
                unset($held[$key]);
                session(['pos_held' => $held]);
            }
        }

        // Tampilkan semua stok termasuk yang habis (qty 0) agar badge peringatan bisa tampil;
        // urut menipis dulu supaya kasir langsung waspada.
        $stocks = ProductStock::with(['product.brand', 'product.category'])
            ->where('store_id', $storeId)
            ->whereHas('product', fn($p) => $p->where('is_active', true))
            ->when($q, fn($qq) => $qq->whereHas('product', fn($p) => $p
                ->where('name', 'like', "%{$q}%")->orWhere('sku', 'like', "%{$q}%")->orWhere('barcode', 'like', "%{$q}%")))
            ->orderBy('qty')->limit(300)->get();

        $lowCount = $stocks->filter(fn($s) => (int) $s->qty <= (int) ($s->min_stock ?? $s->product->min_stock ?? 5))->count();

        $units = ProductItem::with('product')
            ->where('store_id', $storeId)->where('status', 'tersedia')
            ->whereHas('product', fn($p) => $p->where('is_active', true))
            ->when($q, fn($qq) => $qq->where(function ($w) use ($q) {
                $w->where('imei', 'like', "%{$q}%")->orWhere('serial', 'like', "%{$q}%")
                  ->orWhereHas('product', fn($p) => $p->where('name', 'like', "%{$q}%"));
            }))
            ->orderByDesc('id')->limit(200)->get();

        $customers = Customer::where('store_id', $storeId)->orderBy('name')->limit(500)->get();
        // Preselect pelanggan baru dari modal "Pelanggan baru" (?customer_id=ID via customers.store redirect=pos).
        $selectedCustomerId = $request->filled('customer_id') ? (int) $request->get('customer_id') : null;
        if ($selectedCustomerId && !$customers->contains('id', $selectedCustomerId)) {
            $extra = Customer::where('store_id', $storeId)->find($selectedCustomerId);
            if ($extra) $customers->push($extra);
            else $selectedCustomerId = null;
        }        $promos = Promo::where('is_active', true)
            ->where(function ($w) use ($storeId) { $w->whereNull('store_id')->orWhere('store_id', $storeId); })
            ->orderBy('code')->get();
        $tradeIns = TradeIn::where('store_id', $storeId)->whereNull('sale_id')->orderByDesc('id')->limit(50)->get();
        $accounts = CashAccount::where('store_id', $storeId)->orderByDesc('is_default')->get();
        $store = Store::find($storeId);

        $heldSlots = collect(session('pos_held', []))->where('store_id', $storeId)->sortByDesc('created_at');

        // Favorit: 6 produk terlaris toko ini 30 hari terakhir (1 klik tambah ke keranjang).
        // Query aman try/catch, tanpa tabel baru.
        $favorits = collect();
        try {
            $top = DB::table('sale_items')
                ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                ->where('sales.store_id', $storeId)
                ->where('sales.created_at', '>=', now()->subDays(30))
                ->groupBy('sale_items.product_id')
                ->selectRaw('sale_items.product_id, SUM(sale_items.qty) as terjual')
                ->orderByDesc('terjual')->limit(6)->get();
            if ($top->isNotEmpty()) {
                $pmap = Product::whereIn('id', $top->pluck('product_id'))->get()->keyBy('id');
                $smap = ProductStock::where('store_id', $storeId)
                    ->whereIn('product_id', $top->pluck('product_id'))->pluck('qty', 'product_id');
                $favorits = $top->map(fn($r) => [
                    'product_id' => (int) $r->product_id,
                    'name' => $pmap[$r->product_id]->name ?? ('#' . $r->product_id),
                    'price' => (float) ($pmap[$r->product_id]->selling_price ?? 0),
                    'stock' => (int) ($smap[$r->product_id] ?? 0),
                    'serialized' => (bool) ($pmap[$r->product_id]->is_serialized ?? false),
                    'terjual' => (int) $r->terjual,
                ])->filter(fn($f) => isset($pmap[$f['product_id']]) && (bool) ($pmap[$f['product_id']]->is_active ?? false))->values();
            }
        } catch (\Throwable $e) {
            $favorits = collect();
        }

        // Data promo untuk auto-suggest JS (tanpa request tambahan):
        // status tanggal, sisa quota, dan flag khusus-member. Tier pelanggan
        // memakai kaidah existing CustomerController::tierOf (tanpa tabel baru).
        $today = now()->toDateString();
        $nowDay = now()->startOfDay();
        $promoSuggest = $promos->map(function ($p) use ($today, $nowDay) {
            // Hitung mundur server-side (tanpa query tambahan): "X hari lagi" / "berakhir dalam X hari".
            $countdown = null;
            try {
                if ($p->start_date && $p->start_date->toDateString() > $today) {
                    $days = $nowDay->copy()->diffInDays($p->start_date->copy()->startOfDay());
                    $countdown = $days <= 1 ? '1 hari lagi' : $days . ' hari lagi';
                } elseif ($p->end_date && $p->end_date->toDateString() >= $today) {
                    $days = $nowDay->copy()->diffInDays($p->end_date->copy()->startOfDay());
                    $countdown = $days === 0 ? 'berakhir hari ini' : 'berakhir dalam ' . $days . ' hari';
                }
            } catch (\Throwable $e) {
                $countdown = null;
            }
            return [
            'code' => $p->code,
            'name' => $p->name,
            'type' => $p->type,
            'jenis' => self::promoTypeLabel($p->type),
            'syarat' => self::promoSyarat($p),
            'value' => (float) $p->value,
            'min_belanja' => (float) ($p->min_belanja ?? 0),
            'sisa' => $p->quota > 0 ? max(0, (int) $p->quota - (int) $p->used) : null, // null = tanpa batas
            'dateOk' => (!$p->start_date || $p->start_date->toDateString() <= $today)
                && (!$p->end_date || $p->end_date->toDateString() >= $today),
            'memberOnly' => $p->type === 'member',
            'bundling' => $p->type === 'bundling',
            'countdown' => $countdown,
            ];
        })->values();
        $customerTiers = $customers->mapWithKeys(fn($c) => [
            $c->id => \App\Http\Controllers\CustomerController::tierOf((float) $c->total_belanja),
        ])->all();

        // WARNING PIUTANG DI POS (tanpa migration/route baru):
        // debts tak punya customer_id — cocokkan via party_name = nama customer (exact, scope toko).
        // Outstanding = type piutang + remaining > 0 + status belum/sebagian.
        // Hasil: $customerDebts[id] = ['n' => jml nota, 'total' => sisa Rp].
        $customerDebts = [];
        try {
            $outstanding = Debt::where('store_id', $storeId)
                ->where('type', 'piutang')
                ->where('remaining', '>', 0)
                ->whereIn('status', ['belum', 'sebagian'])
                ->selectRaw('party_name, COUNT(*) as n, SUM(remaining) as total')
                ->groupBy('party_name')
                ->get();
            $byName = $outstanding->keyBy('party_name');
            foreach ($customers as $c) {
                if (isset($byName[$c->name])) {
                    $customerDebts[$c->id] = [
                        'n' => (int) $byName[$c->name]->n,
                        'total' => (float) $byName[$c->name]->total,
                    ];
                }
            }
        } catch (\Throwable $e) {
            $customerDebts = [];
        }

        // ULANGI TRANSAKSI (cermin repeat purchase, tanpa route/migration baru):
        // ?repeat_sale=ID → muat item sale lama ke keranjang sebagai transaksi BARU
        // (tanpa pembayaran lama). Unit IMEI spesifik TIDAK dipakai ulang karena
        // statusnya sudah 'terjual' — diganti baris produk saja + kasir pilih unit
        // pengganti di tabel "Unit IMEI tersedia". $repeatNotices menjelaskan
        // penyesuaian ke kasir.
        $repeatNotices = [];
        $repeatFrom = null;
        if ($request->filled('repeat_sale')) {
            $repeatFrom = Sale::with(['items.product', 'customer'])
                ->whereKey((int) $request->get('repeat_sale'))->first();
            if (!$repeatFrom) {
                return redirect()->route('pos.index')->with('error', 'Transaksi asal tidak ditemukan.');
            }
            abort_if((int) $repeatFrom->store_id !== $storeId, 403, 'Bukan transaksi toko aktif.');
            if (!$request->filled('resume')) {
                $stockMap = ProductStock::where('store_id', $storeId)
                    ->whereIn('product_id', $repeatFrom->items->pluck('product_id')->filter()->unique()->values())
                    ->pluck('qty', 'product_id');
                $cart = [];
                foreach ($repeatFrom->items as $it) {
                    $p = $it->product;
                    if (!$p || !$p->is_active) {
                        $repeatNotices[] = ' "' . ($it->description ?? ('#' . $it->product_id)) . '" dilewati: produk nonaktif.';
                        continue;
                    }
                    $max = (int) ($stockMap[$p->id] ?? 0);
                    if ($it->product_item_id) {
                        $repeatNotices[] = ' "' . $p->name . '" (IMEI lama terjual) → dimasukkan sebagai produk saja; pilih unit IMEI pengganti di tabel Unit IMEI tersedia.';
                    } elseif ($max < (int) $it->qty) {
                        $repeatNotices[] = ' "' . $p->name . '" stok tersisa ' . $max . ' (permintaan ' . (int) $it->qty . ') — qty tetap dimasukkan, sesuaikan di keranjang.';
                    }
                    $cart[] = [
                        'product_id' => (int) $p->id,
                        'product_item_id' => null,
                        'name' => $p->name . ($it->product_item_id ? ' (pilih unit baru)' : ''),
                        'qty' => (int) $it->qty,
                        'price' => (float) $it->price,
                        'max' => $max,
                    ];
                }
                $resumeCart = $cart;
                $resumeName = $repeatFrom->customer->name ?? '';
                $resumeNote = '';
                if ($repeatFrom->customer_id) {
                    $selectedCustomerId = (int) $repeatFrom->customer_id;
                }
                if (empty($resumeCart)) {
                    return redirect()->route('pos.index')->with('error', 'Tidak ada item yang bisa diulangi (semua produk nonaktif).');
                }
                if ($selectedCustomerId && !$customers->contains('id', $selectedCustomerId)) {
                    $extra = Customer::where('store_id', $storeId)->find($selectedCustomerId);
                    if ($extra) $customers->push($extra);
                    else $selectedCustomerId = null;
                }
            }
        }

        // CETAK ULANG TERAKHIR (tanpa migration/route baru):
        // Prioritas: session pos_last_sale_id (diset di store() setelah transaksi sukses).
        // Fallback: sale terakhir kasir ini (user_id) di toko aktif hari ini.
        // $lastPrintSale null bila keduanya tidak ada → tombol disembunyikan di blade.
        $lastPrintSale = null;
        try {
            $sessId = (int) session('pos_last_sale_id', 0);
            if ($sessId > 0) {
                $lastPrintSale = Sale::whereKey($sessId)
                    ->where('store_id', $storeId)
                    ->first(['id', 'invoice_no', 'store_id']);
            }
            if (!$lastPrintSale) {
                $lastPrintSale = Sale::where('store_id', $storeId)
                    ->where('user_id', auth()->id())
                    ->whereDate('created_at', now()->toDateString())
                    ->orderByDesc('id')
                    ->first(['id', 'invoice_no', 'store_id']);
            }
        } catch (\Throwable $e) {
            $lastPrintSale = null;
        }

        return view('pos.index', compact('stocks', 'units', 'customers', 'promos', 'tradeIns', 'accounts', 'store', 'q', 'heldSlots', 'resumeCart', 'resumeName', 'resumeNote', 'lowCount', 'favorits', 'promoSuggest', 'customerTiers', 'selectedCustomerId', 'customerDebts', 'repeatNotices', 'repeatFrom', 'lastPrintSale'));
    }

    /** Label Indonesia tiap tipe promo (tanpa tabel baru). */
    protected static function promoTypeLabel(string $type): string
    {
        return match ($type) {
            'diskon_persen' => 'Diskon persen',
            'diskon_rupiah' => 'Diskon rupiah',
            'voucher' => 'Voucher',
            'member' => 'Member Silver/Gold',
            'bundling' => 'Bundling (≥2 produk)',
            default => $type,
        };
    }

    /** Syarat ringkas satu promo untuk box saran POS & tombol Cek. */
    protected static function promoSyarat(Promo $promo): string
    {
        $bits = [];
        $bits[] = match ($promo->type) {
            'diskon_persen' => 'potongan ' . rtrim(rtrim(number_format((float) $promo->value, 2, ',', '.'), '0'), ',') . '% dari subtotal',
            'bundling' => 'diskon ' . rtrim(rtrim(number_format((float) $promo->value, 2, ',', '.'), '0'), ',') . '% khusus keranjang ≥2 produk berbeda',
            'voucher' => 'nominal tetap Rp ' . number_format((float) $promo->value, 0, ',', '.') . ' sekali pakai per transaksi',
            'member' => ((float) $promo->value <= 100
                ? 'khusus Silver/Gold: potongan ' . rtrim(rtrim(number_format((float) $promo->value, 2, ',', '.'), '0'), ',') . '%'
                : 'khusus Silver/Gold: potongan Rp ' . number_format((float) $promo->value, 0, ',', '.')),
            default => 'potongan Rp ' . number_format((float) $promo->value, 0, ',', '.'),
        };
        if ((float) ($promo->min_belanja ?? 0) > 0) {
            $bits[] = 'min. belanja Rp ' . number_format((float) $promo->min_belanja, 0, ',', '.');
        }
        if ($promo->quota > 0) {
            $bits[] = 'sisa ' . max(0, (int) $promo->quota - (int) $promo->used) . '/' . (int) $promo->quota;
        }
        if ($promo->start_date || $promo->end_date) {
            $bits[] = 'periode ' . ($promo->start_date ? $promo->start_date->format('d/m/Y') : '…')
                . ' s/d ' . ($promo->end_date ? $promo->end_date->format('d/m/Y') : '…');
        }
        return implode(' • ', $bits);
    }

    /** Sisa quota promo; null = tanpa batas (quota 0). */
    protected static function promoSisa(Promo $promo): ?int
    {
        return $promo->quota > 0 ? max(0, (int) $promo->quota - (int) $promo->used) : null;
    }

    /**
     * Estimasi hemat promo untuk suatu subtotal (dicap agar tak melebihi subtotal).
     * Semantik per tipe (tanpa kolom baru):
     * - diskon_persen/bundling: value = persen (0–100). Bundling NOL bila <2 produk berbeda.
     * - diskon_rupiah/voucher: value = rupiah, sekali pakai per transaksi.
     * - member: value ≤100 dibaca persen, >100 dibaca rupiah.
     */
    protected static function promoDiscount(Promo $promo, float $subtotal, int $distinct = 1): float
    {
        if (in_array($promo->type, ['diskon_persen', 'bundling'], true)) {
            if ($promo->type === 'bundling' && $distinct < 2) return 0;
            $pct = min(100, max(0, (float) $promo->value));
            return min($subtotal, $subtotal * $pct / 100);
        }
        if ($promo->type === 'member') {
            $v = max(0, (float) $promo->value);
            if ($v <= 100) return min($subtotal, $subtotal * $v / 100);
            return min($subtotal, $v);
        }
        return min($subtotal, max(0, (float) $promo->value));
    }

    /**
     * Validasi satu kode promo untuk penjualan.
     * Semantik per tipe (tanpa migration/tabel baru):
     * - diskon_persen/diskon_rupiah: min_belanja + quota + tanggal (aturan kini).
     * - voucher: nominal rupiah tetap sekali pakai per transaksi + min_belanja + quota + tanggal.
     * - member: hanya Silver/Gold (tierOf existing) + value ≤100 = persen, >100 = rupiah + min_belanja + quota + tanggal.
     * - bundling: value = persen (0–100), HANYA bila keranjang ≥2 produk berbeda
     *   (kolom existing tak menyimpan pasangan A/B, jadi syarat pasangan spesifik belum didukung).
     * @return array{0:Promo|null, 1:float, 2:string|null} [promo, diskon, pesanErrorIndonesia]
     */
    protected function checkPromo(string $code, float $subtotal, int $storeId, ?Customer $customer, int $distinct = 1): array
    {
        $code = trim($code);
        if ($code === '') return [null, 0, 'Kode promo belum diisi.'];
        $promo = Promo::whereRaw('UPPER(code) = ?', [mb_strtoupper($code)])->first();
        if (!$promo) return [null, 0, 'Kode promo "' . $code . '" tidak ditemukan.'];
        if (!$promo->is_active) return [null, 0, 'Promo ' . $promo->code . ' sedang nonaktif.'];
        if ($promo->store_id && (int) $promo->store_id !== $storeId) {
            return [null, 0, 'Promo ' . $promo->code . ' hanya berlaku di toko lain.'];
        }
        $today = now()->toDateString();
        if ($promo->start_date && $promo->start_date->toDateString() > $today) {
            return [null, 0, 'Promo ' . $promo->code . ' belum berlaku (mulai ' . $promo->start_date->format('d/m/Y') . ').'];
        }
        if ($promo->end_date && $promo->end_date->toDateString() < $today) {
            return [null, 0, 'Promo ' . $promo->code . ' sudah kedaluwarsa (berakhir ' . $promo->end_date->format('d/m/Y') . ').'];
        }
        $sisa = self::promoSisa($promo);
        if ($sisa !== null && $sisa <= 0) {
            return [null, 0, 'Kuota promo ' . $promo->code . ' habis (terpakai ' . (int) $promo->used . '/' . (int) $promo->quota . ').'];
        }
        if ($promo->type === 'member') {
            $tier = $customer ? \App\Http\Controllers\CustomerController::tierOf((float) $customer->total_belanja) : null;
            if (!$customer || !in_array($tier, ['Silver', 'Gold'], true)) {
                return [null, 0, 'Promo ' . $promo->code . ' khusus member Silver/Gold. Pilih pelanggan member terlebih dahulu.'];
            }
        }
        if (in_array($promo->type, ['diskon_persen', 'bundling'], true) && (float) $promo->value > 100) {
            return [null, 0, 'Nilai promo ' . $promo->code . ' tidak wajar (>100%). Perbaiki data promo.'];
        }
        if ($promo->type === 'bundling' && $distinct < 2) {
            return [null, 0, 'Promo bundling ' . $promo->code . ' syaratnya keranjang berisi minimal 2 produk berbeda (saat ini ' . $distinct . '). Tambah 1 produk lain dahulu.'];
        }
        if ($subtotal < (float) ($promo->min_belanja ?? 0)) {
            return [null, 0, 'Belanja Rp ' . number_format($subtotal, 0, ',', '.')
                . ' belum mencapai minimum promo ' . $promo->code . ' (Rp ' . number_format((float) $promo->min_belanja, 0, ',', '.') . ').'];
        }
        return [$promo, self::promoDiscount($promo, $subtotal, $distinct), null];
    }

    public function store(Request $request)
    {
        $this->authorizePos();
        $storeId = $this->activeStoreId();
        abort_if(!$storeId, 422, 'Toko aktif belum dipilih.');
        // Kunci Periode: penjualan baru bertanggal hari ini — tolak bila hari ini < batas.
        \App\Support\PeriodLock::check($storeId, now()->toDateString(), 'Tanggal penjualan');

        // Dukung items[] maupun items_json (dari JS kasir)
        $items = $request->input('items');
        if ((!$items || !is_array($items)) && $request->filled('items_json')) {
            $items = json_decode((string) $request->input('items_json'), true);
        }
        $request->merge(['items' => $items]);

        // TAHAN STRUK: simpan keranjang ke session multi-slot, tanpa membuat transaksi.
        // Dipicu tombol "Tahan (F9)" yang mengirim hold_cart=1 + hold_name.
        if ($request->filled('hold_cart')) {
            $request->validate([
                'items' => 'required|array|min:1',
                'items.*.product_id' => 'required|exists:products,id',
                'items.*.product_item_id' => 'nullable|exists:product_items,id',
                'items.*.qty' => 'required|integer|min:1|max:1000',
                'items.*.price' => 'required|numeric|min:0',
                'hold_name' => 'nullable|string|max:100',
                'note' => 'nullable|string|max:1000',
            ]);
            $held = session('pos_held', []);
            $key = 'H' . now()->format('His') . random_int(10, 99);
            $held[$key] = [
                'key' => $key,
                'store_id' => $storeId,
                'customer_name' => trim((string) $request->input('hold_name', '')) ?: 'Umum',
                'items' => array_values($items),
                'note' => trim((string) $request->input('note', '')),
                'created_at' => now()->toDateTimeString(),
            ];
            session(['pos_held' => $held]);
            return redirect()->route('pos.index')->with('success', 'Struk ditahan (' . $held[$key]['customer_name'] . '). Lanjutkan dari panel "Struk Ditahan".');
        }

        // Split payment: gabungan tunai+transfer/QRIS dalam 1 transaksi (dari payments_json JS)
        $legs = $request->input('payments');
        if ((!$legs || !is_array($legs)) && $request->filled('payments_json')) {
            $legs = json_decode((string) $request->input('payments_json'), true);
        }
        $legs = is_array($legs)
            ? array_values(array_filter($legs, fn($l) => is_array($l) && (float) ($l['amount'] ?? 0) > 0))
            : [];
        foreach ($legs as $leg) {
            if (!in_array($leg['method'] ?? '', ['tunai', 'transfer', 'qris'])) {
                return back()->withInput()->with('error', 'Metode split payment hanya boleh tunai / transfer / qris.');
            }
        }

        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.product_item_id' => 'nullable|exists:product_items,id',
            'items.*.qty' => 'required|integer|min:1|max:1000',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
            'customer_id' => 'nullable|exists:customers,id',
            'payment_method' => 'required|in:tunai,transfer,qris,cicilan,dp',
            'paid_amount' => 'nullable|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'tax' => 'nullable|numeric|min:0',
            'promo_code' => 'nullable|string|max:50',
            'trade_in_id' => 'nullable|exists:trade_ins,id',
            'trade_in_value' => 'nullable|numeric|min:0',
            'installment_tenor' => 'nullable|integer|min:0|max:60',
            'cash_account_id' => 'nullable|exists:cash_accounts,id',
            'note' => 'nullable|string|max:1000',
            'signature' => 'nullable|string|max:2000000',
            'send_wa' => 'nullable|boolean',
            'payments_json' => 'nullable|string',
            'hold_cart' => 'nullable',
            'hold_name' => 'nullable|string|max:100',
        ], [
            'items.required' => 'Keranjang masih kosong, tambahkan minimal 1 barang.',
            'items.min' => 'Keranjang masih kosong, tambahkan minimal 1 barang.',
            'items.*.product_id.required' => 'Ada baris tanpa produk yang valid.',
            'items.*.product_id.exists' => 'Produk pada keranjang tidak ditemukan.',
            'items.*.qty.required' => 'Qty wajib diisi.',
            'items.*.qty.min' => 'Qty minimal 1.',
            'items.*.qty.max' => 'Qty maksimal 1000 per baris.',
            'items.*.price.required' => 'Harga wajib diisi.',
            'items.*.price.numeric' => 'Harga harus berupa angka.',
            'payment_method.required' => 'Metode pembayaran wajib dipilih.',
            'payment_method.in' => 'Metode pembayaran tidak valid.',
            'customer_id.exists' => 'Pelanggan tidak ditemukan.',
            'cash_account_id.exists' => 'Akun kas tidak ditemukan.',
            'promo_code.max' => 'Kode promo terlalu panjang.',
        ]);

        $paid = (float) ($data['paid_amount'] ?? 0);
        // Catatan kado/ucapan: tandai [UCAPAN] agar terpisah dari marker sistem [VOID ...]
        // (ReturnController append "[VOID ...]" via str_contains — marker ini tidak mengganggu cek tersebut).
        $gNote = trim((string) ($data['note'] ?? ''));
        if ($gNote !== '' && !str_contains($gNote, '[UCAPAN]')) {
            $gNote = '[UCAPAN] ' . $gNote;
        }
        $manualDiscount = (float) ($data['discount'] ?? 0);
        $tax = (float) ($data['tax'] ?? 0);
        $tradeInValue = (float) ($data['trade_in_value'] ?? 0);
        $tradeIn = null;
        if (!empty($data['trade_in_id'])) {
            $tradeIn = TradeIn::where('store_id', $storeId)->find($data['trade_in_id']);
            if ($tradeIn && $tradeInValue <= 0) $tradeInValue = (float) $tradeIn->appraisal_price;
        }

        // Promo / voucher — validasi kuat di server (tanggal, quota, min belanja, khusus-member).
        $promo = null;
        $promoDiscount = 0;
        $subtotalGuess = collect($data['items'])->sum(fn($i) => (int) $i['qty'] * (float) $i['price'] - (float) ($i['discount'] ?? 0));
        $saleCustomer = !empty($data['customer_id']) ? Customer::find($data['customer_id']) : null;
        if ($saleCustomer && (int) $saleCustomer->store_id !== $storeId) {
            return back()->withInput()->with('error', 'Pelanggan terpilih bukan dari toko aktif.');
        }
        if (!empty($data['promo_code'])) {
            $distinct = collect($data['items'])->pluck('product_id')->unique()->count();
            [$promo, $promoDiscount, $promoError] = $this->checkPromo(
                (string) $data['promo_code'], (float) $subtotalGuess, $storeId, $saleCustomer, max(1, $distinct)
            );
            if (!$promo) return back()->withInput()->with('error', $promoError);
        }

        $subtotal = $subtotalGuess;

        // KEPUTUSAN BISNIS (Continuous 123): diskon MANUAL per transaksi maks 30%
        // dari subtotal (sebelum promo) untuk kasir/teknisi. Owner/admin/
        // superadmin/gudang bebas. Cap HANYA untuk $manualDiscount (field
        // `discount`), BUKAN untuk $promoDiscount / diskon per-baris item.
        // Rumus: manualMaks = 0.30 × subtotal. Tolak 422 bila manual > manualMaks.
        // Tanpa migration.
        $roleNow = auth()->user()->role ?? '';
        $bebasCapManual = in_array($roleNow, ['superadmin', 'owner', 'admin', 'gudang'], true);
        if (!$bebasCapManual && $manualDiscount > 0) {
            $manualMaks = 0.30 * max(0, $subtotal);
            if ($manualDiscount - $manualMaks > 0.0001) {
                abort(422, 'Diskon manual melebihi batas 30% dari subtotal (sebelum promo) — '
                    . 'subtotal Rp ' . number_format($subtotal, 0, ',', '.')
                    . ', maks manual Rp ' . number_format($manualMaks, 0, ',', '.')
                    . ', diajukan Rp ' . number_format($manualDiscount, 0, ',', '.') . '. '
                    . 'Kurangi diskon manual atau minta owner/admin/superadmin/gudang yang input.');
            }
        }

        // KEPUTUSAN BISNIS (Continuous 121): jual di bawah modal hanya boleh
        // owner/admin/superadmin/gudang. Kasir/teknisi (dan role lain di luar
        // daftar) ditolak 422 per item. Modal = purchase_price unit IMEI bila
        // > 0, fallback ke purchase_price produk (sama dgn kalkulasi profit di
        // dalam transaksi). Tanpa migration. Komisi tidak disentuh.
        $bolehBawahModal = in_array($roleNow, ['superadmin', 'owner', 'admin', 'gudang'], true);
        if (!$bolehBawahModal) {
            $diBawahModal = [];
            foreach ($data['items'] as $idx => $row) {
                $p = Product::find($row['product_id'] ?? null);
                if (!$p) continue;
                $u = !empty($row['product_item_id'])
                    ? ProductItem::where('store_id', $storeId)->find($row['product_item_id'])
                    : null;
                $modalCek = (float) ((($u?->purchase_price ?? 0) > 0) ? $u->purchase_price : $p->purchase_price);
                $hargaCek = (float) ($row['price'] ?? 0);
                if ($modalCek > 0 && $hargaCek < $modalCek) {
                    $diBawahModal[] = 'Baris #' . ($idx + 1) . ' ' . $p->name
                        . ($u?->imei ? ' (IMEI ' . $u->imei . ')' : '')
                        . ': harga Rp ' . number_format($hargaCek, 0, ',', '.')
                        . ' di bawah modal Rp ' . number_format($modalCek, 0, ',', '.') . '.';
                }
            }
            if ($diBawahModal) {
                abort(422, 'Harga di bawah modal — role kasir/teknisi tidak boleh menjual rugi. '
                    . implode(' ', $diBawahModal)
                    . ' Minta owner/admin/superadmin/gudang yang input (login dengan akun tersebut lalu ulangi transaksi).');
            }
        }

        $total = max(0, $subtotal - $manualDiscount - $promoDiscount - $tradeInValue) + $tax;

        // Split payment: jumlah seluruh leg WAJIB pas dengan total bayar.
        $isSplit = count($legs) > 0;
        if ($isSplit) {
            $splitSum = (float) collect($legs)->sum(fn($l) => (float) $l['amount']);
            if (abs($splitSum - $total) > 1) {
                return back()->withInput()->with('error', 'Split payment harus pas: total split Rp ' . number_format($splitSum, 0, ',', '.') . ' ≠ total bayar Rp ' . number_format($total, 0, ',', '.'));
            }
            $paid = $splitSum;
            // Kolom payment_method tetap dalam enum lama → isi metode dominan (nominal terbesar).
            // Rincian tiap metode tersimpan di sale_payments + cash_transactions.
            usort($legs, fn($a, $b) => (float) $b['amount'] <=> (float) $a['amount']);
            $data['payment_method'] = $legs[0]['method'];
        }

        $paymentStatus = 'lunas';
        if ($paid < $total) {
            $paymentStatus = $data['payment_method'] === 'cicilan' ? 'cicilan' : ($paid > 0 ? 'dp' : 'belum');
        }
        $remaining = max(0, $total - $paid);

        // TTD PERSETUJUAN CICILAN/DP (cermin signature serah terima ServiceController@updateStatus):
        // payment_method cicilan/dp WAJIB menyertakan dataURL PNG canvas. Split payment
        // hanya mengenal tunai/transfer/qris sehingga tak pernah memicu kewajiban ini.
        // Tanpa migration: file disimpan di disk public + path di marker [SIG:...] kolom note.
        $needSig = in_array($data['payment_method'] ?? '', ['cicilan', 'dp'], true);
        $sigBin = null;
        if ($needSig) {
            $sig = trim((string) ($data['signature'] ?? ''));
            if ($sig === '' || !str_starts_with($sig, 'data:image/png;base64,')) {
                return back()->withInput()->with('error', 'Cicilan/DP wajib disertai tanda tangan persetujuan pelanggan (klik "Ambil Tanda Tangan").');
            }
            $b64 = substr($sig, strlen('data:image/png;base64,'));
            $sigBin = base64_decode($b64, true);
            if ($sigBin === false || strlen($sigBin) < 100 || strlen($sigBin) > 2097152 || substr($sigBin, 0, 8) !== "\x89PNG\r\n\x1a\n") {
                return back()->withInput()->with('error', 'Tanda tangan tidak valid (PNG dataURL rusak). Ulangi tanda tangan.');
            }
        }

        $sale = DB::transaction(function () use ($data, $gNote, $storeId, $subtotal, $manualDiscount, $promoDiscount, $tax, $total, $paid, $remaining, $paymentStatus, $tradeInValue, $promo, $tradeIn, $request, $legs, $isSplit, $needSig, $sigBin) {
            // Invoice unik: {KODETOKO-}INV-YYYYMMDD-XXXX (prefix kode toko di
            // depan bila stores.code terisi, fallback format lama bila kosong).
            $prefix = \App\Support\DocNumber::prefixForStore($storeId) . 'INV-' . now()->format('Ymd') . '-';
            do {
                $invoice = $prefix . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            } while (Sale::where('invoice_no', $invoice)->exists());

            $sale = Sale::create([
                'invoice_no' => $invoice,
                'store_id' => $storeId,
                'user_id' => auth()->id(),
                'customer_id' => $data['customer_id'] ?? null,
                'subtotal' => $subtotal,
                'discount' => $manualDiscount + $promoDiscount,
                'tax' => $tax,
                'total' => $total,
                'payment_method' => $data['payment_method'],
                'payment_status' => $paymentStatus,
                'paid_amount' => $paid,
                'remaining' => $remaining,
                'installment_tenor' => (int) ($data['installment_tenor'] ?? 0),
                'trade_in_value' => $tradeInValue,
                'promo_id' => $promo?->id,
                'note' => $gNote !== '' ? $gNote : null,
            ]);

            // Simpan file TTD persetujuan cicilan/DP (di dalam transaksi agar gagal total
            // bila disk error — cermin ServiceController@updateStatus) + catat path di
            // marker [SIG:...] kolom note (TEXT, tanpa migration).
            if ($needSig && $sigBin) {
                $safe = preg_replace('/[^A-Za-z0-9-_]/', '_', (string) $sale->invoice_no);
                if ($safe === '' || $safe === null) $safe = 'sale-' . $sale->id;
                $sigPath = 'signatures/sale-' . $safe . '.png';
                try {
                    Storage::disk('public')->put($sigPath, $sigBin);
                } catch (\Throwable $e) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'signature' => 'Gagal menyimpan tanda tangan: ' . $e->getMessage(),
                    ]);
                }
                $sale->note = trim(trim((string) ($sale->note ?? '')) . ' [SIG:' . $sigPath . ']');
                $sale->save();
            }

            $soldUnits = [];
            foreach ($data['items'] as $row) {
                $product = Product::findOrFail($row['product_id']);
                abort_if(!$product->is_active, 422, 'Produk ' . $product->name . ' sedang nonaktif.');
                $qty = (int) $row['qty'];
                $price = (float) $row['price'];
                $itemDisc = (float) ($row['discount'] ?? 0);
                $lineSubtotal = max(0, $qty * $price - $itemDisc);

                $unitId = $row['product_item_id'] ?? null;
                $unit = null;
                if ($unitId) {
                    $unit = ProductItem::where('store_id', $storeId)->lockForUpdate()->find($unitId);
                    abort_if(!$unit, 422, 'Unit IMEI tidak ditemukan di toko aktif.');
                    abort_if($unit->status !== 'tersedia', 422, 'Unit ' . ($unit->imei ?: "#{$unit->id}") . ' tidak tersedia.');
                    if ($unit->product_id != $product->id) {
                        abort(422, 'Unit tidak cocok dengan produk.');
                    }
                    $unit->update(['status' => 'terjual']);
                    // Stok agregat ikut berkurang 1
                    $stock = ProductStock::where('store_id', $storeId)->where('product_id', $product->id)->lockForUpdate()->first();
                    if ($stock) $stock->decrement('qty', 1);
                    StockMovement::create([
                        'store_id' => $storeId, 'product_id' => $product->id, 'product_item_id' => $unit->id,
                        'type' => 'keluar', 'qty' => 1, 'ref_type' => 'sale', 'ref_id' => $sale->id,
                        'note' => 'Penjualan ' . $sale->invoice_no, 'user_id' => auth()->id(),
                    ]);
                    $soldUnits[] = $unit;
                } else {
                    if ($product->is_serialized) {
                        abort(422, 'Produk ' . $product->name . ' wajib pilih unit IMEI.');
                    }
                    $stock = ProductStock::where('store_id', $storeId)->where('product_id', $product->id)->lockForUpdate()->first();
                    abort_if(!$stock || $stock->qty < $qty, 422, 'Stok ' . $product->name . ' tidak mencukupi (sisa ' . ($stock->qty ?? 0) . ').');
                    $stock->decrement('qty', $qty);
                    StockMovement::create([
                        'store_id' => $storeId, 'product_id' => $product->id, 'product_item_id' => null,
                        'type' => 'keluar', 'qty' => $qty, 'ref_type' => 'sale', 'ref_id' => $sale->id,
                        'note' => 'Penjualan ' . $sale->invoice_no, 'user_id' => auth()->id(),
                    ]);
                }

                // Modal = harga beli unit IMEI bila tersedia & > 0, fallback ke harga beli produk.
                // Profit = (price - modal) * qty - diskon item. Total/harga tidak diubah.
                $qtyCharged = $unit ? 1 : $qty;
                $modal = (float) ($unit?->purchase_price ?? 0) > 0
                    ? (float) $unit->purchase_price
                    : (float) $product->purchase_price;
                $profit = ($price - $modal) * $qtyCharged - $itemDisc;

                SaleItem::create([
                    'sale_id' => $sale->id, 'product_id' => $product->id, 'product_item_id' => $unit?->id,
                    'description' => $product->name, 'qty' => $unit ? 1 : $qty,
                    'price' => $price, 'discount' => $itemDisc, 'subtotal' => $lineSubtotal, 'profit' => $profit,
                ]);
            }

            // Satu baris sale_payments + cash_transactions per metode (mendukung split payment).
            $payRows = $isSplit
                ? $legs
                : ($paid > 0 ? [['method' => $data['payment_method'], 'amount' => $paid]] : []);
            if (count($payRows)) {
                $accountId = $data['cash_account_id'] ?? CashAccount::where('store_id', $storeId)->orderByDesc('is_default')->value('id');
                foreach ($payRows as $i => $pr) {
                    SalePayment::create([
                        'sale_id' => $sale->id, 'amount' => (float) $pr['amount'],
                        'method' => $pr['method'],
                        'note' => ($isSplit ? 'Split ' . ($i + 1) . '/' . count($payRows) . ' ' : 'Pembayaran awal ') . $sale->invoice_no,
                        'user_id' => auth()->id(),
                    ]);
                    CashTransaction::create([
                        'store_id' => $storeId, 'cash_account_id' => $accountId, 'type' => 'masuk',
                        'category' => 'penjualan', 'amount' => (float) $pr['amount'],
                        'ref_type' => 'sale', 'ref_id' => $sale->id,
                        'description' => 'Penjualan ' . $sale->invoice_no . ($isSplit ? ' (' . $pr['method'] . ')' : ''),
                        'user_id' => auth()->id(),
                    ]);
                }
            }

            if ($remaining > 0) {
                $customerName = $sale->customer->name ?? 'Umum';
                Debt::create([
                    'store_id' => $storeId, 'type' => 'piutang', 'party_name' => $customerName,
                    'ref_type' => 'sale', 'ref_id' => $sale->id,
                    'total' => $total, 'paid' => $paid, 'remaining' => $remaining,
                    'due_date' => now()->addDays(30)->toDateString(),
                    'status' => $paid > 0 ? 'sebagian' : 'belum',
                ]);
            }

            // Kunci baris promo agar quota tidak jebol saat 2 kasir memakai bersamaan;
            // cek ulang karena quota bisa habis di antara validasi awal & commit.
            if ($promo) {
                $fresh = Promo::whereKey($promo->id)->lockForUpdate()->first();
                abort_if(!$fresh || !$fresh->is_active, 422, 'Promo ' . $promo->code . ' sudah tidak aktif.');
                abort_if($fresh->quota > 0 && $fresh->used >= $fresh->quota,
                    422, 'Kuota promo ' . $promo->code . ' baru saja habis dipakai transaksi lain.');
                $fresh->increment('used');
            }

            if ($tradeIn) {
                $tradeIn->update(['sale_id' => $sale->id]);
            }

            if ($sale->customer_id) {
                $sale->customer->increment('total_belanja', $total);
            }

            // GARANSI TOKO OTOMATIS: tiap unit IMEI yang terjual dapat 1 garansi toko aktif.
            // Pola disamakan dengan garansi service otomatis (ServiceController::updateStatus):
            // start = hari ini, status = aktif, idempoten (cek dulu sebelum create).
            // Durasi: unit punya kolom existing warranty_months (bulan) — bila > 0 dipakai
            // (end = start + N bulan); bila 0 / tak diisi, default 30 hari. Ditulis eksplisit
            // di claim_note ("otomatis ... 30 hari") agar kasir tahu ini fallback, bukan data unit.
            // Link: ref_type = 'sale', ref_id = id sale, customer_id + product_item_id terisi,
            // type = 'toko'. Item non-IMEI tidak dapat garansi (tak ada unit yang bisa diklaim).
            foreach ($soldUnits as $soldUnit) {
                $already = Warranty::where('product_item_id', $soldUnit->id)
                    ->where('status', 'aktif')->exists();
                if ($already) continue;
                $months = (int) ($soldUnit->warranty_months ?? 0);
                $start = now()->toDateString();
                $end = $months > 0
                    ? now()->addMonthsNoOverflow($months)->toDateString()
                    : now()->addDays(30)->toDateString();
                $durLabel = $months > 0 ? $months . ' bulan (dari data unit)' : '30 hari (default toko)';
                Warranty::create([
                    'ref_type' => 'sale',
                    'ref_id' => $sale->id,
                    'customer_id' => $sale->customer_id,
                    'product_item_id' => $soldUnit->id,
                    'start_date' => $start,
                    'end_date' => $end,
                    'type' => 'toko',
                    'claim_note' => 'Garansi toko otomatis penjualan ' . $sale->invoice_no
                        . ' (' . $durLabel . ', IMEI ' . ($soldUnit->imei ?: '#' . $soldUnit->id) . ')',
                    'status' => 'aktif',
                ]);
            }

            return $sale;
        });

        // KIRIM STRUK WA OTOMATIS (opt-in via checkbox; default MATI):
        // pesan SAMA dengan tombol manual di halaman cetak
        // (PrinterController::saleWaMessage) + pencatatan wa_logs via
        // WaService::send. Guard: tanpa HP valid = lewati; tanpa gateway =
        // WaService mencatat 'antri'. Dibungkus try/catch agar tak pernah
        // menggagalkan transaksi (sale sudah commit). Tanpa migration.
        $flash = 'Transaksi ' . $sale->invoice_no . ' berhasil disimpan.';
        if ($request->boolean('send_wa')) {
            try {
                $sale->load(['items', 'customer', 'store', 'user']);
                $rawPhone = (string) ($sale->customer->phone ?? '');
                $phone = $rawPhone !== '' ? WaService::normalize($rawPhone) : '';
                if (strlen($phone) < 9 || strlen($phone) > 15) {
                    $flash .= ' Struk WA dilewati (pelanggan tanpa no. HP valid).';
                } else {
                    $msg = PrinterController::saleWaMessage($sale);
                    $cfgFile = storage_path('app/wa.json');
                    $cfg = is_file($cfgFile) ? json_decode((string) file_get_contents($cfgFile), true) : [];
                    $hasGateway = trim((string) (($cfg['WA_GATEWAY_URL'] ?? '') ?: env('WA_GATEWAY_URL', ''))) !== '';
                    $ok = WaService::send($phone, $msg, 'struk_sale', $storeId);
                    if ($ok) {
                        $flash .= $hasGateway
                            ? ' Struk WA terkirim ke ' . $phone . '.'
                            : ' Struk WA diantre (gateway WA belum dikonfigurasi, tercatat di wa_logs).';
                    } else {
                        $flash .= ' Gagal kirim struk WA ke ' . $phone . ' (cek pengaturan gateway WA).';
                    }
                }
            } catch (\Throwable $e) {
                $flash .= ' Struk WA otomatis gagal (' . substr($e->getMessage(), 0, 120) . ').';
            }
        }

        // Ingat transaksi terakhir untuk tombol "Cetak Ulang Terakhir" di POS
        // (dibaca di index(): session dulu, fallback sale terakhir kasir hari ini).
        session(['pos_last_sale_id' => $sale->id]);

        return redirect()->route('pos.print', $sale)->with('success', $flash);
    }

    public function print(Sale $sale)
    {
        $this->authorizePos();
        $storeId = $this->activeStoreId();
        $role = auth()->user()->role ?? '';
        if ($role !== 'superadmin' || !request()->boolean('all')) {
            abort_if($sale->store_id !== $storeId, 403, 'Bukan transaksi toko aktif.');
        }
        $sale->load(['items.product', 'items.productItem', 'payments', 'customer', 'store', 'promo', 'user']);
        $lanPrinters = \App\Services\EscPosService::all();
        $branding = \App\Services\EscPosService::branding($sale->store_id);
        $brandingLogo = \App\Services\EscPosService::logoUrl($sale->store_id);
        // Mode struk hadiah (?gift=1): sembunyikan harga, tampilkan ucapan besar.
        // Samakan dgn parser di pos.print (format asli: "[UCAPAN] teks",
        // BUKAN colon-form). $__noteBersih wajib bebas marker sistem.
        $isGift = request()->boolean('gift');
        $__rawNote = (string) ($sale->note ?? '');
        $__ucapan = '';
        if (preg_match('/\[UCAPAN\]\s*(.*?)(?=\s*\[[A-Z\]]|\s*$)/s', $__rawNote . ' ', $um)) {
            $__ucapan = trim($um[1]);
        }
        if ($__ucapan === '') {
            $__ucapan = trim((string) preg_replace('/\s*\[[^\]]+\]/', '', $__rawNote));
        }
        $__noteBersih = trim((string) preg_replace('/\s*\[(?:VOID|UCAPAN|SIG)[^\]]*\]/', '', $__rawNote));
        return view('pos.print', compact('sale', 'lanPrinters', 'branding', 'brandingLogo', 'isGift', '__ucapan', '__noteBersih'));
    }
}
