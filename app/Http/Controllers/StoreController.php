<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class StoreController extends Controller
{
    /** Kelola toko (CRUD + assign user): hanya superadmin/owner. */
    private function authorizeManager(): void
    {
        abort_unless(in_array(auth()->user()->role ?? '', ['superadmin', 'owner']), 403, 'Hanya owner/superadmin.');
    }

    /** Monitoring Pusat multi-toko (khusus superadmin/owner). */
    public function monitor()
    {
        abort_unless(in_array(auth()->user()->role ?? '', ['superadmin', 'owner']), 403, 'Hanya owner/superadmin.');

        $stores = Store::orderBy('is_center', 'desc')->orderBy('name')->get();
        $today = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();
        $cutoff30 = now()->subDays(29)->startOfDay();

        $rows = [];
        $omzet30 = [];
        try {
            if (\Schema::hasTable('sales')) {
                $omzet30 = \DB::table('sales')
                    ->where('created_at', '>=', $cutoff30)
                    ->groupBy('store_id')
                    ->selectRaw('store_id, SUM(total) as t')
                    ->pluck('t', 'store_id');
            }
        } catch (\Throwable $e) { $omzet30 = []; }

        foreach ($stores as $s) {
            $r = [
                'store' => $s,
                'omzet_hari' => 0, 'omzet_bulan' => 0, 'trx_hari' => 0,
                'service_aktif' => 0, 'service_terlambat' => 0,
                'stok_menipis' => 0, 'kas_total' => 0,
                'hutang' => 0, 'piutang' => 0, 'booking_menunggu' => 0,
                'omzet_30' => (float) ($omzet30[$s->id] ?? 0),
            ];
            try {
                if (\Schema::hasTable('sales')) {
                    $r['omzet_hari'] = (float) \DB::table('sales')->where('store_id', $s->id)->whereDate('created_at', $today)->sum('total');
                }
            } catch (\Throwable $e) {}
            try {
                if (\Schema::hasTable('sales')) {
                    $r['omzet_bulan'] = (float) \DB::table('sales')->where('store_id', $s->id)->where('created_at', '>=', $monthStart)->sum('total');
                }
            } catch (\Throwable $e) {}
            try {
                if (\Schema::hasTable('sales')) {
                    $r['trx_hari'] = (int) \DB::table('sales')->where('store_id', $s->id)->whereDate('created_at', $today)->count();
                }
            } catch (\Throwable $e) {}
            try {
                if (\Schema::hasTable('service_tickets')) {
                    $r['service_aktif'] = (int) \DB::table('service_tickets')->where('store_id', $s->id)->whereNotIn('status', ['diserahkan', 'batal'])->count();
                }
            } catch (\Throwable $e) {}
            try {
                if (\Schema::hasTable('service_tickets')) {
                    $r['service_terlambat'] = (int) \DB::table('service_tickets')->where('store_id', $s->id)
                        ->whereNotIn('status', ['diserahkan', 'batal'])
                        ->where('created_at', '<=', now()->subDays(3))->count();
                }
            } catch (\Throwable $e) {}
            try {
                if (\Schema::hasTable('product_stocks') && \Schema::hasTable('products')) {
                    // Min EFEKTIF per toko: COALESCE(product_stocks.min_stock, products.min_stock).
                    $r['stok_menipis'] = (int) \DB::table('product_stocks')
                        ->join('products', 'products.id', '=', 'product_stocks.product_id')
                        ->where('product_stocks.store_id', $s->id)
                        ->whereRaw('product_stocks.qty <= COALESCE(product_stocks.min_stock, products.min_stock)')->count();
                }
            } catch (\Throwable $e) {}
            try {
                if (\Schema::hasTable('cash_accounts')) {
                    $r['kas_total'] = (float) \DB::table('cash_accounts')->where('store_id', $s->id)->sum('balance');
                }
            } catch (\Throwable $e) {}
            try {
                if (\Schema::hasTable('debts')) {
                    $r['hutang'] = (float) \DB::table('debts')->where('store_id', $s->id)->where('type', 'hutang')->where('status', '!=', 'lunas')->sum('remaining');
                    $r['piutang'] = (float) \DB::table('debts')->where('store_id', $s->id)->where('type', 'piutang')->where('status', '!=', 'lunas')->sum('remaining');
                }
            } catch (\Throwable $e) {}
            try {
                if (\Schema::hasTable('bookings')) {
                    $r['booking_menunggu'] = (int) \DB::table('bookings')->where('store_id', $s->id)->where('status', 'menunggu')->count();
                }
            } catch (\Throwable $e) {}
            $rows[] = $r;
        }

        $maxOmzet = 0;
        foreach ($rows as $r) { $maxOmzet = max($maxOmzet, (float) $r['omzet_30']); }

        // Target omzet bulanan (storage/app/targets.json, key "{store_id}:{YYYY-MM}").
        $monthKey = now()->format('Y-m');
        $targets = self::readTargets();
        foreach ($rows as &$r) {
            $t = (float) ($targets[$r['store']->id . ':' . $monthKey] ?? 0);
            $r['target'] = $t;
            $r['target_pct'] = $t > 0 ? min(100, round($r['omzet_bulan'] / $t * 100, 1)) : 0;
        }
        unset($r);

        // Grafik garis Target vs Realisasi Jan-Des tahun berjalan (per toko + total).
        // Sumber target: storage/app/targets.json key "{store_id}:{YYYY-MM}".
        // Sumber realisasi: tabel sales SUM(total) per bulan (whereYear/whereMonth agar lintas DB).
        $yearNow = now()->format('Y');
        $chartBulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
        $targetTotalTahun = array_fill(0, 12, 0.0);
        $realTotalTahun = array_fill(0, 12, 0.0);
        $targetPerTokoChart = [];
        $realPerTokoChart = [];
        foreach ($stores as $s) {
            $targetPerTokoChart[$s->id] = [
                'name' => $s->name,
                'data' => array_fill(0, 12, 0.0),
            ];
            $realPerTokoChart[$s->id] = [
                'name' => $s->name,
                'data' => array_fill(0, 12, 0.0),
            ];
        }
        for ($m = 1; $m <= 12; $m++) {
            $keyLoop = $yearNow . '-' . sprintf('%02d', $m);
            foreach ($stores as $s) {
                $t = (float) ($targets[$s->id . ':' . $keyLoop] ?? 0);
                $targetPerTokoChart[$s->id]['data'][$m - 1] = $t;
                $targetTotalTahun[$m - 1] += $t;
            }
            try {
                if (\Schema::hasTable('sales')) {
                    $sums = \DB::table('sales')
                        ->whereYear('created_at', $yearNow)
                        ->whereMonth('created_at', $m)
                        ->groupBy('store_id')
                        ->selectRaw('store_id, SUM(total) as t')
                        ->pluck('t', 'store_id');
                    foreach ($stores as $s) {
                        $v = (float) ($sums[$s->id] ?? 0);
                        $realPerTokoChart[$s->id]['data'][$m - 1] = $v;
                        $realTotalTahun[$m - 1] += $v;
                    }
                }
            } catch (\Throwable $e) {}
        }

        return view('stores.monitor', compact(
            'rows',
            'maxOmzet',
            'monthKey',
            'yearNow',
            'chartBulan',
            'targetTotalTahun',
            'realTotalTahun',
            'targetPerTokoChart',
            'realPerTokoChart'
        ));
    }

    /** Simpan target omzet bulanan toko berjalan (owner/superadmin). Tanpa tabel baru. */
    public function setTarget(Request $request, Store $store)
    {
        abort_unless(in_array(auth()->user()->role ?? '', ['superadmin', 'owner']), 403, 'Hanya owner/superadmin.');
        $data = $request->validate(['target' => 'required|numeric|min:0|max:999999999999']);
        $targets = self::readTargets();
        $targets[$store->id . ':' . now()->format('Y-m')] = (float) $data['target'];
        self::writeTargets($targets);
        return back()->with('success', 'Target ' . $store->name . ' bulan ini disimpan: Rp ' . number_format((float) $data['target'], 0, ',', '.'));
    }

    /** Salin semua target "{store}:{YYYY-MM}" → "{store}:{YYYY+1-MM}" (lewati yang sudah ada). */
    public function copyTargetsNextYear()
    {
        abort_unless(in_array(auth()->user()->role ?? '', ['superadmin', 'owner']), 403, 'Hanya owner/superadmin.');
        $targets = self::readTargets();
        $snapshot = $targets;
        $copied = 0;
        $skipped = 0;
        foreach ($snapshot as $key => $val) {
            if (!preg_match('/^(\d+):(\d{4})-(\d{2})$/', (string) $key, $m)) continue;
            $mm = (int) $m[3];
            if ($mm < 1 || $mm > 12) continue;
            $dest = $m[1] . ':' . (((int) $m[2]) + 1) . '-' . $m[3];
            if (array_key_exists($dest, $targets)) {
                $skipped++;
                continue;
            }
            $targets[$dest] = $val;
            $copied++;
        }
        self::writeTargets($targets);
        return back()->with('success', 'Salin target ke tahun depan: ' . $copied . ' disalin, ' . $skipped . ' dilewati (sudah ada).');
    }

    /** @return array<string,float> */
    public static function readTargets(): array
    {
        try {
            if (!Storage::disk('local')->exists('targets.json')) return [];
            $raw = Storage::disk('local')->get('targets.json');
            $arr = json_decode($raw, true);
            return is_array($arr) ? $arr : [];
        } catch (\Throwable $e) { return []; }
    }

    public static function writeTargets(array $targets): void
    {
        Storage::disk('local')->put('targets.json', json_encode($targets, JSON_PRETTY_PRINT));
    }

    public function index(Request $request)
    {
        $this->authorizeManager();
        // Export CSV (?export=csv) — pola CustomerController@index (delimiter ; + BOM, streamDownload).
        if ($request->get('export') === 'csv') {
            $rows = Store::withCount('users')
                ->when(
                    $request->filled('q'),
                    fn ($q) => $q->where(function ($w) use ($request) {
                        $w->where('name', 'like', '%' . $request->q . '%')
                            ->orWhere('code', 'like', '%' . $request->q . '%');
                    })
                )
                ->orderBy('is_center', 'desc')->orderBy('name')->limit(5000)->get();
            $filename = 'toko-' . now()->format('Ymd-His') . '.csv';
            return response()->streamDownload(function () use ($rows) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['nama', 'kode', 'alamat', 'telepon', 'aktif'], ';');
                foreach ($rows as $s) {
                    fputcsv($out, [$s->name, $s->code, $s->address ?? '', $s->phone ?? '', $s->is_active ? 'Ya' : 'Tidak'], ';');
                }
                fclose($out);
            }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }
        $stores = Store::withCount('users')
            ->when(
                $request->filled('q'),
                fn ($q) => $q->where(function ($w) use ($request) {
                    $w->where('name', 'like', '%' . $request->q . '%')
                        ->orWhere('code', 'like', '%' . $request->q . '%');
                })
            )
            ->orderBy('is_center', 'desc')->orderBy('name')->paginate(20)->withQueryString();
        return view('stores.index', compact('stores'));
    }

    public function create()
    {
        $this->authorizeManager();
        $users = User::where('is_active', true)->orderBy('name')->get();
        return view('stores.create', compact('users'));
    }

    public function store(Request $request)
    {
        $this->authorizeManager();
        $data = $request->validate([
            'code' => 'required|string|max:50|unique:stores,code',
            'name' => 'required|string|max:255',
            'address' => 'nullable|string',
            'phone' => 'nullable|string|max:50',
            'is_center' => 'nullable|boolean',
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'exists:users,id',
        ]);

        DB::transaction(function () use ($data) {
            $store = Store::create([
                'code' => $data['code'],
                'name' => $data['name'],
                'address' => $data['address'] ?? null,
                'phone' => $data['phone'] ?? null,
                'is_center' => (bool) ($data['is_center'] ?? false),
                'is_active' => true,
            ]);
            if (!empty($data['user_ids'])) {
                $store->users()->attach($data['user_ids'], ['is_default' => false]);
            }
        });

        return redirect()->route('stores.index')->with('success', 'Toko ditambahkan.');
    }

    public function show(Store $store)
    {
        $this->authorizeManager();
        $store->load(['users', 'cashAccounts']);
        return view('stores.show', compact('store'));
    }

    public function edit(Store $store)
    {
        $this->authorizeManager();
        $users = User::where('is_active', true)->orderBy('name')->get();
        $store->load('users');
        return view('stores.edit', compact('store', 'users'));
    }

    public function update(Request $request, Store $store)
    {
        $this->authorizeManager();
        $data = $request->validate([
            'code' => 'required|string|max:50|unique:stores,code,' . $store->id,
            'name' => 'required|string|max:255',
            'address' => 'nullable|string',
            'phone' => 'nullable|string|max:50',
            'is_center' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'exists:users,id',
        ]);

        DB::transaction(function () use ($store, $data) {
            $store->update([
                'code' => $data['code'],
                'name' => $data['name'],
                'address' => $data['address'] ?? null,
                'phone' => $data['phone'] ?? null,
                'is_center' => (bool) ($data['is_center'] ?? false),
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);
            $store->users()->sync($data['user_ids'] ?? []);
        });

        return redirect()->route('stores.index')->with('success', 'Toko diperbarui (termasuk assign user).');
    }

    /** Nonaktifkan toko (soft) alih-alih hapus permanen. */
    public function destroy(Store $store)
    {
        $this->authorizeManager();
        $store->update(['is_active' => false]);
        return back()->with('success', 'Toko dinonaktifkan.');
    }
}
