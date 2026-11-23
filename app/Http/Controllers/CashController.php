<?php

namespace App\Http\Controllers;

use App\Models\CashAccount;
use App\Models\CashTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CashController extends Controller
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

        $accounts = CashAccount::where('store_id', $storeId)->orderBy('is_default', 'desc')->orderBy('name')->get();

        // Pastikan selalu ada akun default agar form kas bisa dipakai
        if ($accounts->isEmpty() && $storeId) {
            CashAccount::create(['store_id' => $storeId, 'name' => 'Kas Tunai', 'balance' => 0, 'is_default' => true]);
            $accounts = CashAccount::where('store_id', $storeId)->get();
        }

        // Filter akun tunggal (?akun=id) — abaikan bila bukan akun toko aktif
        $accountFilter = (int) $request->get('akun', 0);
        if ($accountFilter > 0 && ! $accounts->contains('id', $accountFilter)) {
            $accountFilter = 0;
        }

        $tx = CashTransaction::with(['cashAccount', 'user'])
            ->where('store_id', $storeId)
            ->when($accountFilter > 0, fn ($q) => $q->where('cash_account_id', $accountFilter))
            ->when($request->filled('kategori'), fn ($q) => $q->where('category', $request->kategori))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when(
                $request->filled('q'),
                fn ($q) => $q->where('description', 'like', '%' . $request->q . '%')
            )
            ->when($request->filled('bulan'), function ($q) use ($request) {
                [$y, $m] = array_pad(explode('-', $request->bulan), 2, null);
                if ($y && $m) $q->whereYear('created_at', $y)->whereMonth('created_at', $m);
            })
            ->orderByDesc('id')->paginate(20)->withQueryString();

        $totalMasuk = CashTransaction::where('store_id', $storeId)->where('type', 'masuk')
            ->when($accountFilter > 0, fn ($q) => $q->where('cash_account_id', $accountFilter))
            ->when($request->filled('bulan'), function ($q) use ($request) {
                [$y, $m] = array_pad(explode('-', $request->bulan), 2, null);
                if ($y && $m) $q->whereYear('created_at', $y)->whereMonth('created_at', $m);
            })->sum('amount');
        $totalKeluar = CashTransaction::where('store_id', $storeId)->where('type', 'keluar')
            ->when($accountFilter > 0, fn ($q) => $q->where('cash_account_id', $accountFilter))
            ->when($request->filled('bulan'), function ($q) use ($request) {
                [$y, $m] = array_pad(explode('-', $request->bulan), 2, null);
                if ($y && $m) $q->whereYear('created_at', $y)->whereMonth('created_at', $m);
            })->sum('amount');

        // SALDO BERJALAN (Continuous 208, tanpa migration).
        // Rumus per akun: opening[a] = balance_kini[a] - (SUM masuk[a] - SUM keluar[a] sepanjang waktu);
        // saldo_berjalan(tx) = opening[akun(tx)] + kumulatif kronologis (masuk - keluar) s/d tx itu (id ASC).
        // Dihitung dari SELURUH riwayat (filter tampilan diabaikan) agar angka = posisi saldo
        // sebenarnya sesaat setelah mutasi itu terjadi; pagination tidak memutus angka.
        // Mode semua-akun: kolom = saldo berjalan AKUN baris tsb (per-akun, bukan gabungan).
        $sumPerAkun = CashTransaction::where('store_id', $storeId)
            ->selectRaw('cash_account_id, type, SUM(amount) as total')
            ->groupBy('cash_account_id', 'type')
            ->get();
        $jumlahPerAkun = [];
        foreach ($sumPerAkun as $r) {
            $jumlahPerAkun[(int) $r->cash_account_id][$r->type] = (float) $r->total;
        }
        $openingByAccount = [];
        foreach ($accounts as $a) {
            $masukSemua = $jumlahPerAkun[(int) $a->id]['masuk'] ?? 0;
            $keluarSemua = $jumlahPerAkun[(int) $a->id]['keluar'] ?? 0;
            $openingByAccount[(int) $a->id] = (float) $a->balance - ($masukSemua - $keluarSemua);
        }

        $riwayat = CashTransaction::where('store_id', $storeId)
            ->when($accountFilter > 0, fn ($q) => $q->where('cash_account_id', $accountFilter))
            ->orderBy('id')
            ->select(['id', 'cash_account_id', 'type', 'amount'])
            ->get();
        $kumulatifPerAkun = [];
        $runningBalances = [];
        foreach ($riwayat as $h) {
            $aid = (int) $h->cash_account_id;
            if (! array_key_exists($aid, $kumulatifPerAkun)) {
                $kumulatifPerAkun[$aid] = $openingByAccount[$aid] ?? 0;
            }
            $kumulatifPerAkun[$aid] += $h->type === 'masuk' ? (float) $h->amount : -(float) $h->amount;
            $runningBalances[(int) $h->id] = $kumulatifPerAkun[$aid];
        }
        $singleAccount = $accountFilter > 0;
        $selectedAccount = $singleAccount ? $accounts->firstWhere('id', $accountFilter) : null;
        $selectedOpening = $selectedAccount ? ($openingByAccount[(int) $selectedAccount->id] ?? 0) : null;

        // Export CSV (?export=csv) — hormati filter aktif + scope toko
        if ($request->get('export') === 'csv') {
            $rows = CashTransaction::with('cashAccount')
                ->where('store_id', $storeId)
                ->when($accountFilter > 0, fn ($q) => $q->where('cash_account_id', $accountFilter))
                ->when($request->filled('kategori'), fn ($q) => $q->where('category', $request->kategori))
                ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
                ->when(
                    $request->filled('q'),
                    fn ($q) => $q->where('description', 'like', '%' . $request->q . '%')
                )
                ->when($request->filled('bulan'), function ($q) use ($request) {
                    [$y, $m] = array_pad(explode('-', $request->bulan), 2, null);
                    if ($y && $m) $q->whereYear('created_at', $y)->whereMonth('created_at', $m);
                })
                ->orderByDesc('id')->limit(5000)->get();
            $filename = 'kas-' . now()->format('Ymd-His') . '.csv';
            return response()->streamDownload(function () use ($rows) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['id', 'tanggal', 'akun', 'tipe', 'kategori', 'nominal', 'saldo_berjalan', 'keterangan'], ';');
                foreach ($rows as $t) {
                    fputcsv($out, [$t->id, $t->created_at?->format('Y-m-d H:i') ?? '', $t->cashAccount->name ?? '', $t->type, $t->category, (float) $t->amount, $runningBalances[(int) $t->id] ?? '', $t->description ?? ''], ';');
                }
                fclose($out);
            }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $categories = ['penjualan', 'service', 'pembelian', 'pengeluaran', 'operasional', 'hutang', 'piutang', 'modal', 'lain'];

        return view('cash.index', compact('accounts', 'tx', 'totalMasuk', 'totalKeluar', 'categories', 'storeId', 'runningBalances', 'singleAccount', 'selectedAccount', 'selectedOpening', 'accountFilter', 'openingByAccount'));
    }

    public function store(Request $request)
    {
        $storeId = $this->activeStoreId($request);
        // Kunci Periode: transaksi kas bertanggal hari ini — tolak bila hari ini < batas.
        \App\Support\PeriodLock::check($storeId, now()->toDateString(), 'Tanggal transaksi kas');
        $data = $request->validate([
            'cash_account_id' => 'required|exists:cash_accounts,id',
            'type' => 'required|in:masuk,keluar',
            'category' => 'required|in:penjualan,service,pembelian,pengeluaran,operasional,hutang,piutang,modal,lain',
            'amount' => 'required|numeric|min:1',
            'description' => 'nullable|string|max:1000',
            'bukti' => 'nullable|image|max:2048',
        ]);

        // KEPUTUSAN BISNIS (Continuous 136): bukti foto WAJIB untuk kas keluar
        // > Rp10.000.000 (konsisten dgn approval owner Continuous 123 + bukti
        // wajib expense >1jt). Tanpa migration: validasi di level controller,
        // file tetap opsional di aturan 'bukti' untuk nominal kecil / kas masuk.
        if (
            ($data['type'] ?? '') === 'keluar'
            && (float) ($data['amount'] ?? 0) > 10000000
            && ! $request->hasFile('bukti')
        ) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'bukti' => 'Kas keluar di atas Rp10.000.000 wajib melampirkan foto bukti (nota/kwitansi/transfer).',
            ]);
        }

        // KEPUTUSAN BISNIS (Continuous 123): kas keluar manual > Rp10.000.000
        // hanya owner/admin/superadmin. Kasir/teknisi/gudang (dan role lain di
        // luar daftar) ditolak 422. Guard saldo existing di bawah tidak diubah.
        $roleNow = $request->user()?->role ?? auth()->user()->role ?? '';
        if (
            ($data['type'] ?? '') === 'keluar'
            && (float) ($data['amount'] ?? 0) > 10000000
            && ! in_array($roleNow, ['superadmin', 'owner', 'admin'], true)
        ) {
            abort(422, 'Kas keluar di atas Rp10.000.000 hanya boleh diinput owner/admin/superadmin. Minta owner/admin/superadmin yang input (login dengan akun tersebut lalu ulangi transaksi).');
        }

        // Pola marker bukti ala ExpenseController@store / SaleController@pay:
        // foto opsional disimpan di storage + path dicatat [BUKTI:path] di description.
        $desc = $data['description'] ?? null;
        if ($request->hasFile('bukti')) {
            $path = $request->file('bukti')->store('bukti-kas', 'public');
            $desc = trim(($desc ? $desc . ' ' : '') . '[BUKTI:' . $path . ']');
        }

        DB::transaction(function () use ($data, $desc, $storeId, $request) {
            $account = CashAccount::whereKey($data['cash_account_id'])
                ->where('store_id', $storeId)->lockForUpdate()->firstOrFail();

            if ($data['type'] === 'masuk') {
                $account->increment('balance', $data['amount']);
            } else {
                if ((float) $account->balance < (float) $data['amount']) {
                    abort(422, 'Saldo akun kas tidak mencukupi.');
                }
                $account->decrement('balance', $data['amount']);
            }

            CashTransaction::create([
                'store_id' => $storeId,
                'cash_account_id' => $account->id,
                'type' => $data['type'],
                'category' => $data['category'],
                'amount' => $data['amount'],
                'description' => $desc,
                'user_id' => $request->user()?->id,
            ]);
        });

        return back()->with('success', 'Transaksi kas tersimpan.');
    }

    /** Transfer antar akun kas satu toko: catat 2 mutasi linked + update saldo atomik (tanpa tabel baru). */
    public function transfer(Request $request)
    {
        $storeId = $this->activeStoreId($request);
        // Kunci Periode: transfer bertanggal hari ini.
        \App\Support\PeriodLock::check($storeId, now()->toDateString(), 'Tanggal transfer kas');
        $data = $request->validate([
            'from_account_id' => 'required|exists:cash_accounts,id|different:to_account_id',
            'to_account_id' => 'required|exists:cash_accounts,id',
            'amount' => 'required|numeric|min:1',
            'note' => 'nullable|string|max:1000',
        ], [
            'from_account_id.different' => 'Akun asal dan akun tujuan harus berbeda.',
        ]);

        DB::transaction(function () use ($data, $storeId, $request) {
            // Kunci berurutan by id agar konsisten & hindari deadlock
            $ids = [(int) $data['from_account_id'], (int) $data['to_account_id']];
            sort($ids);
            $locked = CashAccount::whereIn('id', $ids)
                ->where('store_id', $storeId)->lockForUpdate()->get()->keyBy('id');

            $from = $locked->get((int) $data['from_account_id']);
            $to = $locked->get((int) $data['to_account_id']);
            if (! $from || ! $to) {
                abort(422, 'Akun kas tidak ditemukan / beda toko.');
            }

            if ((float) $from->balance < (float) $data['amount']) {
                abort(422, 'Saldo akun asal tidak mencukupi untuk transfer.');
            }

            $from->decrement('balance', $data['amount']);
            $to->increment('balance', $data['amount']);

            $note = $data['note'] ?? null;
            $suffix = $note ? " — {$note}" : '';

            $out = CashTransaction::create([
                'store_id' => $storeId,
                'cash_account_id' => $from->id,
                'type' => 'keluar',
                'category' => 'lain',
                'amount' => $data['amount'],
                'ref_type' => 'transfer',
                'description' => "Transfer ke {$to->name}{$suffix}",
                'user_id' => $request->user()?->id,
            ]);

            $in = CashTransaction::create([
                'store_id' => $storeId,
                'cash_account_id' => $to->id,
                'type' => 'masuk',
                'category' => 'lain',
                'amount' => $data['amount'],
                'ref_type' => 'transfer',
                'ref_id' => $out->id,
                'description' => "Transfer dari {$from->name}{$suffix}",
                'user_id' => $request->user()?->id,
            ]);

            // Taut balik: transaksi keluar menunjuk ke transaksi masuk pasangannya
            $out->update(['ref_id' => $in->id]);
        });

        return back()->with('success', 'Transfer antar akun kas berhasil.');
    }

    /** Tambah akun kas (Continuous 201): nama + saldo awal opsional.
     *  Saldo awal > 0 dicatat sebagai mutasi kas MASUK kategori modal
     *  "Saldo awal {nama}" agar balance = sum mutasi tetap konsisten
     *  (akun dibuat balance 0 lalu increment + transaksi, atomik).
     *  Tanpa migration. Kolom is_active tidak ada → nonaktifkan dilewati. */
    public function storeAccount(Request $request)
    {
        $storeId = $this->activeStoreId($request);
        // Kunci Periode: saldo awal bertanggal hari ini.
        \App\Support\PeriodLock::check($storeId, now()->toDateString(), 'Tanggal saldo awal kas');
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'opening_balance' => 'nullable|numeric|min:0|max:999999999999',
        ]);

        $nama = trim($data['name']);
        if ($nama === '') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'name' => 'Nama akun kas wajib diisi.',
            ]);
        }
        $duplikat = CashAccount::where('store_id', $storeId)->where('name', $nama)->exists();
        if ($duplikat) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'name' => 'Nama akun kas sudah dipakai di toko ini.',
            ]);
        }

        DB::transaction(function () use ($nama, $data, $storeId, $request) {
            $isFirst = ! CashAccount::where('store_id', $storeId)->exists();
            $account = CashAccount::create([
                'store_id' => $storeId,
                'name' => $nama,
                'balance' => 0,
                'is_default' => $isFirst,
            ]);

            $opening = (float) ($data['opening_balance'] ?? 0);
            if ($opening > 0) {
                $account->increment('balance', $opening);
                CashTransaction::create([
                    'store_id' => $storeId,
                    'cash_account_id' => $account->id,
                    'type' => 'masuk',
                    'category' => 'modal',
                    'amount' => $opening,
                    'description' => "Saldo awal {$nama}",
                    'user_id' => $request->user()?->id,
                ]);
            }
        });

        return back()->with('success', 'Akun kas "' . $nama . '" ditambahkan.');
    }

    /** Ubah nama + set default akun kas (Continuous 201). Scope toko aktif.
     *  Set default: hanya bila checkbox dicentang (tidak ada unset default
     *  agar form kas selalu punya akun default). Tanpa migration. */
    public function updateAccount(Request $request, CashAccount $account)
    {
        $storeId = $this->activeStoreId($request);
        if ((int) $account->store_id !== (int) $storeId) {
            abort(404);
        }
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'is_default' => 'nullable|boolean',
        ]);

        $nama = trim($data['name']);
        if ($nama === '') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'name' => 'Nama akun kas wajib diisi.',
            ]);
        }
        $duplikat = CashAccount::where('store_id', $storeId)
            ->where('name', $nama)
            ->whereKeyNot($account->id)
            ->exists();
        if ($duplikat) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'name' => 'Nama akun kas sudah dipakai di toko ini.',
            ]);
        }

        DB::transaction(function () use ($request, $account, $nama, $storeId) {
            $account->update(['name' => $nama]);
            if ($request->boolean('is_default')) {
                CashAccount::where('store_id', $storeId)
                    ->whereKeyNot($account->id)
                    ->update(['is_default' => false]);
                $account->update(['is_default' => true]);
            }
        });

        return back()->with('success', 'Akun kas diperbarui.');
    }

    /** Rincian arus per akun kas (Continuous 209): masuk/keluar/net per akun
     *  dalam periode (tahun + bulan opsional). Tanpa migration.
     *  @return array{0: array, 1: ?int} [perAkun terurut masuk desc, id akun terbesar] */
    private function breakdownPerAkun(?int $storeId, int $y, ?int $m = null): array
    {
        $names = \App\Models\CashAccount::where('store_id', $storeId)->pluck('name', 'id');
        $rows = CashTransaction::where('store_id', $storeId)
            ->whereYear('created_at', $y)
            ->when($m !== null, fn ($q) => $q->whereMonth('created_at', $m))
            ->selectRaw('cash_account_id, type, SUM(amount) as total')
            ->groupBy('cash_account_id', 'type')
            ->get();

        $map = [];
        foreach ($rows as $r) {
            $aid = (int) $r->cash_account_id;
            if (! isset($map[$aid])) {
                $map[$aid] = [
                    'id' => $aid,
                    'name' => $names[$aid] ?? ('Akun #' . $aid),
                    'masuk' => 0,
                    'keluar' => 0,
                    'net' => 0,
                ];
            }
            $map[$aid][$r->type] = (float) $r->total;
        }
        foreach ($map as $aid => $a) {
            $map[$aid]['net'] = $a['masuk'] - $a['keluar'];
        }
        $perAkun = array_values($map);
        usort($perAkun, fn ($a, $b) => $b['masuk'] <=> $a['masuk']);
        $akunTopId = ! empty($perAkun) && ($perAkun[0]['masuk'] ?? 0) > 0
            ? (int) $perAkun[0]['id']
            : null;

        return [$perAkun, $akunTopId];
    }

    /** Arus kas bulanan: tabel harian (masuk/keluar/net/kumulatif) + ringkasan per kategori
     *  + grafik garis net kumulatif. Scope toko aktif. Tanpa migration. */
    public function flow(Request $request)
    {
        $storeId = $this->activeStoreId($request);

        // Mode tahun (?tahun=YYYY): agregat per bulan Jan–Des. Default tetap mode bulan.
        $tahunParam = $request->get('tahun');
        if (is_string($tahunParam) && preg_match('/^\d{4}$/', trim($tahunParam))) {
            return $this->flowTahunan($request, $storeId, (int) trim($tahunParam));
        }

        $bulan = $request->get('bulan', now()->format('Y-m'));
        if (! preg_match('/^\d{4}-\d{2}$/', (string) $bulan)) {
            $bulan = now()->format('Y-m');
        }
        [$y, $m] = array_map('intval', explode('-', $bulan));

        $daysInMonth = \Carbon\Carbon::create($y, $m, 1)->daysInMonth;

        $perDay = CashTransaction::where('store_id', $storeId)
            ->whereYear('created_at', $y)->whereMonth('created_at', $m)
            ->selectRaw('DATE(created_at) as tgl, type, SUM(amount) as total')
            ->groupBy('tgl', 'type')->get();

        $map = [];
        foreach ($perDay as $r) {
            $map[$r->tgl][$r->type] = (float) $r->total;
        }

        $days = [];
        $kum = 0;
        $totalMasuk = 0; $totalKeluar = 0;
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $tgl = sprintf('%04d-%02d-%02d', $y, $m, $d);
            $masuk = $map[$tgl]['masuk'] ?? 0;
            $keluar = $map[$tgl]['keluar'] ?? 0;
            $net = $masuk - $keluar;
            $kum += $net;
            $totalMasuk += $masuk; $totalKeluar += $keluar;
            $days[] = ['tgl' => $tgl, 'masuk' => $masuk, 'keluar' => $keluar, 'net' => $net, 'kumulatif' => $kum];
        }
        $netTotal = $totalMasuk - $totalKeluar;

        // Proyeksi akhir bulan: kumulatif kini + rata-rata net harian x sisa hari.
        // Asumsi: bila pola sama (rata-rata net harian berjalan berlanjut sampai akhir bulan).
        $now = now();
        $isBulanBerjalan = ((int) $now->format('Y') === $y && (int) $now->format('n') === $m);
        $isBulanDepan = ($y > (int) $now->format('Y'))
            || ($y === (int) $now->format('Y') && $m > (int) $now->format('n'));
        if ($isBulanBerjalan) {
            $hariBerlalu = min((int) $now->format('j'), $daysInMonth);
        } elseif ($isBulanDepan) {
            $hariBerlalu = 0;
        } else {
            $hariBerlalu = $daysInMonth;
        }
        $sisaHari = max(0, $daysInMonth - $hariBerlalu);
        $rataNetHarian = $hariBerlalu > 0 ? $netTotal / $hariBerlalu : 0;
        $proyeksiAkhirBulan = $netTotal + ($rataNetHarian * $sisaHari);

        $perKategori = CashTransaction::where('store_id', $storeId)
            ->whereYear('created_at', $y)->whereMonth('created_at', $m)
            ->selectRaw('category, type, SUM(amount) as total')
            ->groupBy('category', 'type')->orderBy('category')->orderBy('type')->get();

        // Rincian per akun kas (Continuous 209): masuk/keluar/net per akun bulan ini.
        [$perAkun, $akunTopId] = $this->breakdownPerAkun($storeId, $y, $m);

        // Export CSV (?export=csv) — hormati filter bulan + scope toko
        if ($request->get('export') === 'csv') {
            $filename = 'arus-kas-' . $bulan . '.csv';
            return response()->streamDownload(function () use ($days, $perKategori, $perAkun) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['tanggal', 'masuk', 'keluar', 'net', 'kumulatif_bulan'], ';');
                foreach ($days as $d) {
                    fputcsv($out, [$d['tgl'], (float) $d['masuk'], (float) $d['keluar'], (float) $d['net'], (float) $d['kumulatif']], ';');
                }
                fputcsv($out, [], ';');
                fputcsv($out, ['akun', 'masuk', 'keluar', 'net'], ';');
                foreach ($perAkun as $a) {
                    fputcsv($out, [$a['name'], (float) $a['masuk'], (float) $a['keluar'], (float) $a['net']], ';');
                }
                fputcsv($out, [], ';');
                fputcsv($out, ['kategori', 'tipe', 'total'], ';');
                foreach ($perKategori as $k) {
                    fputcsv($out, [$k->category, $k->type, (float) $k->total], ';');
                }
                fclose($out);
            }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $chartLabels = array_map(fn ($d) => substr($d['tgl'], 8, 2), $days);
        $chartKumulatif = array_map(fn ($d) => (float) $d['kumulatif'], $days);
        $chartNet = array_map(fn ($d) => (float) $d['net'], $days);

        $mode = 'bulan';
        $tahun = null;
        $months = [];
        $chartMasuk = [];
        $chartKeluar = [];

        return view('cash.flow', compact(
            'mode', 'tahun', 'bulan', 'days', 'months',
            'totalMasuk', 'totalKeluar', 'netTotal',
            'perKategori', 'perAkun', 'akunTopId',
            'chartLabels', 'chartKumulatif', 'chartNet',
            'chartMasuk', 'chartKeluar', 'storeId',
            'daysInMonth', 'hariBerlalu', 'sisaHari', 'rataNetHarian', 'proyeksiAkhirBulan'
        ));
    }

    /** Arus kas tahunan (?tahun=YYYY): agregat per bulan Jan–Des + grafik + tabel.
     *  Scope toko aktif. Tanpa migration / route baru — dipanggil dari flow(). */
    private function flowTahunan(Request $request, ?int $storeId, int $y)
    {
        $tahun = sprintf('%04d', $y);
        $namaBulan = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];
        $namaPendek = [
            1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
            5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu',
            9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
        ];

        $perMonth = CashTransaction::where('store_id', $storeId)
            ->whereYear('created_at', $y)
            ->selectRaw('MONTH(created_at) as bln, type, SUM(amount) as total')
            ->groupBy('bln', 'type')
            ->get();

        $map = [];
        foreach ($perMonth as $r) {
            $map[(int) $r->bln][$r->type] = (float) $r->total;
        }

        $months = [];
        $kum = 0;
        $totalMasuk = 0;
        $totalKeluar = 0;
        for ($m = 1; $m <= 12; $m++) {
            $masuk = $map[$m]['masuk'] ?? 0;
            $keluar = $map[$m]['keluar'] ?? 0;
            $net = $masuk - $keluar;
            $kum += $net;
            $totalMasuk += $masuk;
            $totalKeluar += $keluar;
            $months[] = [
                'bulan' => $m,
                'kode' => sprintf('%04d-%02d', $y, $m),
                'nama' => $namaBulan[$m],
                'pendek' => $namaPendek[$m],
                'masuk' => $masuk,
                'keluar' => $keluar,
                'net' => $net,
                'kumulatif' => $kum,
            ];
        }
        $netTotal = $totalMasuk - $totalKeluar;

        $perKategori = CashTransaction::where('store_id', $storeId)
            ->whereYear('created_at', $y)
            ->selectRaw('category, type, SUM(amount) as total')
            ->groupBy('category', 'type')
            ->orderBy('category')
            ->orderBy('type')
            ->get();

        // Rincian per akun kas (Continuous 209): masuk/keluar/net per akun tahun ini.
        [$perAkun, $akunTopId] = $this->breakdownPerAkun($storeId, $y);

        if ($request->get('export') === 'csv') {
            $filename = 'arus-kas-' . $tahun . '.csv';
            return response()->streamDownload(function () use ($months, $perKategori, $perAkun) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['bulan', 'masuk', 'keluar', 'net', 'kumulatif_tahun'], ';');
                foreach ($months as $r) {
                    fputcsv($out, [
                        $r['kode'],
                        (float) $r['masuk'],
                        (float) $r['keluar'],
                        (float) $r['net'],
                        (float) $r['kumulatif'],
                    ], ';');
                }
                fputcsv($out, [], ';');
                fputcsv($out, ['akun', 'masuk', 'keluar', 'net'], ';');
                foreach ($perAkun as $a) {
                    fputcsv($out, [$a['name'], (float) $a['masuk'], (float) $a['keluar'], (float) $a['net']], ';');
                }
                fputcsv($out, [], ';');
                fputcsv($out, ['kategori', 'tipe', 'total'], ';');
                foreach ($perKategori as $k) {
                    fputcsv($out, [$k->category, $k->type, (float) $k->total], ';');
                }
                fclose($out);
            }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $chartLabels = array_map(fn ($r) => $r['pendek'], $months);
        $chartMasuk = array_map(fn ($r) => (float) $r['masuk'], $months);
        $chartKeluar = array_map(fn ($r) => (float) $r['keluar'], $months);
        $chartNet = array_map(fn ($r) => (float) $r['net'], $months);
        $chartKumulatif = array_map(fn ($r) => (float) $r['kumulatif'], $months);

        // Variabel mode-bulan dikosongkan agar blade cabang tahun aman dipakai ulang.
        $mode = 'tahun';
        $bulan = now()->format('Y-m');
        $days = [];
        $daysInMonth = 0;
        $hariBerlalu = 0;
        $sisaHari = 0;
        $rataNetHarian = 0;
        $proyeksiAkhirBulan = $netTotal;

        return view('cash.flow', compact(
            'mode', 'tahun', 'bulan', 'days', 'months',
            'totalMasuk', 'totalKeluar', 'netTotal',
            'perKategori', 'perAkun', 'akunTopId',
            'chartLabels', 'chartKumulatif', 'chartNet',
            'chartMasuk', 'chartKeluar', 'storeId',
            'daysInMonth', 'hariBerlalu', 'sisaHari', 'rataNetHarian', 'proyeksiAkhirBulan'
        ));
    }

    /** Bukti kas massal: gabungkan voucher terpilih (maks 50) dalam 1 halaman cetak + page-break.
     *  Route: GET /cash/vouchers?ids[]=... (didefinisikan SEBELUM cash/{transaction}/voucher;
     *  "vouchers" non-numerik sehingga whereNumber('transaction') tetap aman, tanpa bentrok flow/closing). */
    public function bulkVouchers(Request $request)
    {
        $storeId = $this->activeStoreId($request);
        $ids = array_values(array_unique(array_map(
            'intval',
            (array) $request->query('ids', $request->input('ids', []))
        )));
        $ids = array_values(array_filter($ids, fn ($v) => $v > 0));
        if (empty($ids)) {
            return back()->with('error', 'Pilih minimal 1 mutasi kas untuk dicetak.');
        }
        if (count($ids) > 50) {
            return back()->with('error', 'Maksimal 50 voucher per cetak massal.');
        }

        $transactions = CashTransaction::with(['cashAccount', 'user', 'store'])
            ->where('store_id', $storeId)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();
        if ($transactions->isEmpty()) {
            return back()->with('error', 'Tidak ada mutasi kas valid yang dipilih.');
        }

        $store = \App\Models\Store::whereKey($storeId)->first();
        $terbilangById = [];
        foreach ($transactions as $t) {
            $terbilangById[$t->id] = $this->terbilang((float) $t->amount);
        }

        return view('cash.vouchers', compact('transactions', 'store', 'terbilangById', 'storeId'));
    }

    /** Bukti kas per transaksi (masuk/keluar): voucher cetak + tanda tangan. Tanpa migration.
     *  Route: GET /cash/{transaction}/voucher (whereNumber agar tak bentrok cash.flow/cash.closing). */
    public function voucher(Request $request, CashTransaction $transaction)
    {
        $storeId = $this->activeStoreId($request);
        if ((int) $transaction->store_id !== (int) $storeId) {
            abort(404);
        }
        $transaction->load(['cashAccount', 'user', 'store']);
        $terbilang = $this->terbilang((float) $transaction->amount);

        return view('cash.voucher', compact('transaction', 'terbilang', 'storeId'));
    }

    /** Terbilang rupiah sederhana (bilangan bulat positif). */
    private function terbilang(float $n): string
    {
        $n = (int) round($n);
        if ($n < 0) {
            return 'minus ' . $this->terbilang(abs($n));
        }
        $kata = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];
        if ($n < 12) {
            return $kata[$n];
        }
        if ($n < 20) {
            return $this->terbilang($n - 10) . ' belas';
        }
        if ($n < 100) {
            $sisa = $n % 10;
            return trim($this->terbilang(intval($n / 10)) . ' puluh ' . ($sisa ? $this->terbilang($sisa) : ''));
        }
        if ($n < 200) {
            return trim('seratus ' . ($n - 100 ? $this->terbilang($n - 100) : ''));
        }
        if ($n < 1000) {
            $sisa = $n % 100;
            return trim($this->terbilang(intval($n / 100)) . ' ratus ' . ($sisa ? $this->terbilang($sisa) : ''));
        }
        if ($n < 2000) {
            return trim('seribu ' . ($n - 1000 ? $this->terbilang($n - 1000) : ''));
        }
        if ($n < 1000000) {
            $sisa = $n % 1000;
            return trim($this->terbilang(intval($n / 1000)) . ' ribu ' . ($sisa ? $this->terbilang($sisa) : ''));
        }
        if ($n < 1000000000) {
            $sisa = $n % 1000000;
            return trim($this->terbilang(intval($n / 1000000)) . ' juta ' . ($sisa ? $this->terbilang($sisa) : ''));
        }
        if ($n < 1000000000000) {
            $sisa = $n % 1000000000;
            return trim($this->terbilang(intval($n / 1000000000)) . ' miliar ' . ($sisa ? $this->terbilang($sisa) : ''));
        }
        $sisa = $n % 1000000000000;
        return trim($this->terbilang(intval($n / 1000000000000)) . ' triliun ' . ($sisa ? $this->terbilang($sisa) : ''));
    }

    /** Tutup kasir / handover shift: rekap saldo awal vs akhir per akun + selisih kas + berita acara cetak.
     *  Kas awal shift disimpan di SESSION per akun+tanggal (tanpa migration):
     *  isi kolom "Kas awal shift" lalu "Hitung Selisih" — nilai tersimpan & dipakai di rekap berikutnya.
     *  + Section Kunci Periode (tutup buku) khusus owner/superadmin:
     *    melihat/menyetel/menghapus batas kunci via PeriodLock (storage/app/period_lock.json). */
    public function closing(Request $request)
    {
        $storeId = $this->activeStoreId($request);
        $tanggal = $request->get('tanggal', now()->toDateString());

        $accounts = CashAccount::where('store_id', $storeId)->orderBy('is_default', 'desc')->orderBy('name')->get();
        $rows = [];
        $grandAwal = 0; $grandMasuk = 0; $grandKeluar = 0; $grandAkhir = 0; $grandFisik = 0;
        foreach ($accounts as $a) {
            // Simpan kas awal shift ke session bila diisi (sumber manual kasir)
            $sesiKey = "kas_awal_{$storeId}_{$a->id}_{$tanggal}";
            if ($request->filled("awal_{$a->id}")) {
                session([$sesiKey => (float) $request->get("awal_{$a->id}")]);
            } elseif ($request->boolean('reset_awal')) {
                session()->forget($sesiKey);
            }
            $awalSesi = session($sesiKey);
            $masuk = (float) CashTransaction::where('store_id', $storeId)
                ->where('cash_account_id', $a->id)->where('type', 'masuk')
                ->whereDate('created_at', $tanggal)->sum('amount');
            $keluar = (float) CashTransaction::where('store_id', $storeId)
                ->where('cash_account_id', $a->id)->where('type', 'keluar')
                ->whereDate('created_at', $tanggal)->sum('amount');
            $akhir = (float) $a->balance;
            $awalHitung = $akhir - ($masuk - $keluar);
            // Rekap memakai kas awal session bila diisi, fallback ke hitungan existing
            $awal = $awalSesi !== null ? (float) $awalSesi : $awalHitung;
            $dariSesi = $awalSesi !== null;
            $fisik = $request->filled("fisik_{$a->id}") ? (float) $request->get("fisik_{$a->id}") : $akhir;
            $selisih = $fisik - $akhir;
            $rows[] = compact('awal', 'masuk', 'keluar', 'akhir', 'fisik', 'selisih', 'dariSesi') + ['account' => $a];
            $grandAwal += $awal; $grandMasuk += $masuk; $grandKeluar += $keluar;
            $grandAkhir += $akhir; $grandFisik += $fisik;
        }
        $grandSelisih = $grandFisik - $grandAkhir;

        $kasir = $request->user();
        // Peta kas awal session per akun (untuk prefill input di view)
        $kasAwalSesi = [];
        foreach ($accounts as $a) {
            $kasAwalSesi[$a->id] = session("kas_awal_{$storeId}_{$a->id}_{$tanggal}");
        }
        // Kunci Periode (tutup buku) toko aktif + flag kelola (owner/superadmin saja)
        $periodLock = \App\Support\PeriodLock::get($storeId);
        $canManageLock = \App\Support\PeriodLock::canManage();
        return view('cash.closing', compact(
            'accounts', 'rows', 'tanggal', 'storeId', 'kasir', 'kasAwalSesi',
            'grandAwal', 'grandMasuk', 'grandKeluar', 'grandAkhir', 'grandFisik', 'grandSelisih',
            'periodLock', 'canManageLock'
        ));
    }

    /** Set/ubah batas Kunci Periode toko aktif (khusus owner/superadmin). Tanpa migration. */
    public function setPeriodLock(Request $request)
    {
        abort_unless(\App\Support\PeriodLock::canManage(), 403, 'Hanya owner yang boleh mengatur kunci periode.');
        $storeId = $this->activeStoreId($request);
        $data = $request->validate([
            'lock_date' => 'required|date_format:Y-m-d|before_or_equal:today',
        ], [
            'lock_date.required' => 'Tanggal batas kunci wajib diisi.',
            'lock_date.date_format' => 'Format tanggal kunci harus YYYY-MM-DD.',
            'lock_date.before_or_equal' => 'Batas kunci maksimal hari ini (mengunci ke depan akan memblokir transaksi berjalan).',
        ]);
        \App\Support\PeriodLock::set($storeId, $data['lock_date']);
        return back()->with('success', 'Periode dikunci: transaksi bertanggal sebelum ' . $data['lock_date'] . ' ditolak.');
    }

    /** Hapus Kunci Periode toko aktif (khusus owner/superadmin). */
    public function clearPeriodLock(Request $request)
    {
        abort_unless(\App\Support\PeriodLock::canManage(), 403, 'Hanya owner yang boleh membuka kunci periode.');
        $storeId = $this->activeStoreId($request);
        \App\Support\PeriodLock::clear($storeId);
        return back()->with('success', 'Kunci periode dibuka — transaksi tanggal lama kembali bisa diinput.');
    }
}
