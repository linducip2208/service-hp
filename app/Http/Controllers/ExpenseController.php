<?php

namespace App\Http\Controllers;

use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ExpenseController extends Controller
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
        $baseQuery = Expense::with('user')
            ->where('store_id', $storeId)
            ->when($request->filled('kategori'), fn ($q) => $q->where('category', $request->kategori))
            ->when(
                $request->filled('q'),
                fn ($q) => $q->where('description', 'like', '%' . $request->q . '%')
            )
            ->when($request->filled('bulan'), function ($q) use ($request) {
                [$y, $m] = array_pad(explode('-', $request->bulan), 2, null);
                if ($y && $m) $q->whereYear('expense_date', $y)->whereMonth('expense_date', $m);
            });

        // Export CSV (?export=csv) — hormati filter kategori+bulan + scope toko,
        // pola BookingController@index (delimiter ; + BOM, streamDownload).
        if ($request->get('export') === 'csv') {
            $rows = (clone $baseQuery)->orderByDesc('expense_date')->orderByDesc('id')->limit(5000)->get();
            $filename = 'pengeluaran_toko_' . ($storeId ?: 'semua')
                . '_' . now()->format('Ymd_His') . '.csv';
            return response()->streamDownload(function () use ($rows) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv(
                    $out,
                    ['id', 'tanggal', 'kategori', 'nominal', 'keterangan', 'user'],
                    ';'
                );
                foreach ($rows as $e) {
                    fputcsv(
                        $out,
                        [
                            $e->id ?? '',
                            $e->expense_date
                                ? \Carbon\Carbon::parse($e->expense_date)->format('Y-m-d')
                                : '',
                            $e->category ?? '',
                            $e->amount ?? '',
                            trim(preg_replace('/\s+/', ' ', (string) $e->description)) ?? '',
                            $e->user->name ?? '',
                        ],
                        ';'
                    );
                }
                fclose($out);
            }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $expenses = (clone $baseQuery)
            ->orderByDesc('expense_date')->orderByDesc('id')
            ->paginate(20)->withQueryString();
        $total = Expense::where('store_id', $storeId)
            ->when($request->filled('bulan'), function ($q) use ($request) {
                [$y, $m] = array_pad(explode('-', $request->bulan), 2, null);
                if ($y && $m) $q->whereYear('expense_date', $y)->whereMonth('expense_date', $m);
            })->sum('amount');
        $accounts = CashAccount::where('store_id', $storeId)->orderBy('name')->get();

        // Ringkasan per kategori bulan berjalan (atau bulan filter) + datalist histori kategori
        $bulan = $request->bulan ?? now()->format('Y-m');
        [$by, $bm] = array_pad(explode('-', $bulan), 2, null);
        $perCategory = collect(); $categoryList = [];
        try {
            $perCategory = Expense::where('store_id', $storeId)
                ->when($by && $bm, fn ($q) => $q->whereYear('expense_date', $by)->whereMonth('expense_date', $bm))
                ->selectRaw('category, SUM(amount) as total, COUNT(*) as jml')
                ->groupBy('category')->orderByDesc('total')->get();
            $categoryList = Expense::where('store_id', $storeId)
                ->select('category')->distinct()->orderBy('category')->pluck('category')->all();
        } catch (\Throwable $e) {
            report($e);
        }

        // Budget per kategori bulan tampil (storage/app/budget.json, key "{store_id}:{YYYY-MM}:{kategori}").
        $expenseBudgets = [];
        try {
            $all = self::readBudgets();
            $prefix = $storeId . ':' . $bulan . ':';
            foreach ($all as $k => $v) {
                if (str_starts_with((string) $k, $prefix)) {
                    $expenseBudgets[substr((string) $k, strlen($prefix))] = (float) $v;
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
        $canSetBudget = in_array(auth()->user()->role ?? '', ['superadmin', 'owner', 'admin']);

        // Tampilan tahunan (?tahun=YYYY): agregat budget bulanan Jan–Des per kategori
        // + realisasi per bulan. Tiru pola targets tahunan dashboard
        // (StoreController targets.json keyed bulan, disum Jan–Des).
        // Tanpa migration/route baru: reuse query ?tahun= di index existing.
        $tahunAnnual = now()->year;
        try {
            $tParam = trim((string) $request->get('tahun', ''));
            if (preg_match('/^\d{4}$/', $tParam)) {
                $tahunAnnual = max(2020, min(2100, (int) $tParam));
            }
        } catch (\Throwable $e) {
            $tahunAnnual = now()->year;
        }
        $annualRows = [];
        $annualTotalBudget = 0;
        $annualTotalReal = 0;
        try {
            $allBudgets = self::readBudgets();
            $yearPrefix = $storeId . ':' . sprintf('%04d', $tahunAnnual) . '-';
            // budgetMap[kategori][bulanInt] = nominal
            $budgetMap = [];
            foreach ($allBudgets as $k => $v) {
                $k = (string) $k;
                if (!str_starts_with($k, $yearPrefix)) continue;
                // key: "{store}:{YYYY-MM}:{kategori}"
                $rest = substr($k, strlen($yearPrefix)); // "MM:kategori"
                $mm = (int) substr($rest, 0, 2);
                $kat = trim(substr($rest, 3));
                if ($mm < 1 || $mm > 12 || $kat === '') continue;
                $budgetMap[$kat][$mm] = ($budgetMap[$kat][$mm] ?? 0) + (float) $v;
            }
            // realisasiMap[kategori][bulanInt] = total
            $realisasiMap = [];
            try {
                $rows = Expense::where('store_id', $storeId)
                    ->whereYear('expense_date', $tahunAnnual)
                    ->selectRaw('category, MONTH(expense_date) as bln, SUM(amount) as total')
                    ->groupBy('category', 'bln')
                    ->get();
                foreach ($rows as $r) {
                    $realisasiMap[$r->category][(int) $r->bln] = (float) $r->total;
                }
            } catch (\Throwable $e) {
                report($e);
            }
            $allKats = array_values(array_unique(array_merge(
                array_keys($budgetMap),
                array_keys($realisasiMap)
            )));
            sort($allKats, SORT_NATURAL | SORT_FLAG_CASE);
            foreach ($allKats as $kat) {
                $months = [];
                $totB = 0;
                $totR = 0;
                for ($m = 1; $m <= 12; $m++) {
                    $b = (float) ($budgetMap[$kat][$m] ?? 0);
                    $r = (float) ($realisasiMap[$kat][$m] ?? 0);
                    $totB += $b;
                    $totR += $r;
                    $months[$m] = [
                        'budget' => $b,
                        'realisasi' => $r,
                        'selisih' => $b - $r,
                        'pct' => $b > 0 ? round($r / $b * 100, 1) : 0,
                        'status' => $b <= 0
                            ? 'no_budget'
                            : ($r > $b ? 'over' : ($b > 0 && $r / $b > 0.9 ? 'kritis' : 'aman')),
                    ];
                }
                $annualTotalBudget += $totB;
                $annualTotalReal += $totR;
                $annualRows[] = [
                    'kategori' => $kat,
                    'months' => $months,
                    'total_budget' => $totB,
                    'total_realisasi' => $totR,
                    'total_selisih' => $totB - $totR,
                    'total_pct' => $totB > 0 ? round($totR / $totB * 100, 1) : 0,
                    'total_status' => $totB <= 0
                        ? 'no_budget'
                        : ($totR > $totB ? 'over' : ($totR / $totB > 0.9 ? 'kritis' : 'aman')),
                ];
            }
        } catch (\Throwable $e) {
            report($e);
        }

        // Peta expense_id => cash_account_id untuk prefill "Ulangi" (tanpa kolom/migrasi baru).
        $expenseAccounts = collect();
        try {
            $ids = $expenses->pluck('id')->all();
            if (!empty($ids)) {
                $expenseAccounts = CashTransaction::where('store_id', $storeId)
                    ->where('ref_type', 'expense')
                    ->whereIn('ref_id', $ids)
                    ->pluck('cash_account_id', 'ref_id');
            }
        } catch (\Throwable $e) {
            report($e);
        }

        // Alert pengeluaran tak biasa: nominal > 2x rata-rata kategorinya (90 hari).
        // Tiru pola alert kenaikan harga beli (PurchaseController@show: last > avg * 1.15).
        // Tanpa migration/route baru: satu query agregat baseline + flag per baris halaman aktif.
        // Ambang: amount > 2 * avg_kategori_90hari, min 2 transaksi baseline per kategori
        // (kategori berisi 1 transaksi tidak pernah di-flag agar tidak noise).
        $unusualThreshold = 2.0;
        $unusualWindowDays = 90;
        $unusualMinSample = 2;
        $expenseAvgMap = [];
        $unusualMap = [];
        try {
            $baseline = Expense::where('store_id', $storeId)
                ->where('expense_date', '>=', now()->subDays($unusualWindowDays)->toDateString())
                ->selectRaw('category, AVG(amount) as rata2, COUNT(*) as jml')
                ->groupBy('category')
                ->get();
            foreach ($baseline as $b) {
                $avg = (float) $b->rata2;
                if ($avg > 0 && (int) $b->jml >= $unusualMinSample) {
                    $expenseAvgMap[$b->category] = [
                        'avg' => $avg,
                        'count' => (int) $b->jml,
                    ];
                }
            }
            foreach ($expenses as $e) {
                $base = $expenseAvgMap[$e->category] ?? null;
                if (!$base) continue;
                $amt = (float) $e->amount;
                if ($amt > $base['avg'] * $unusualThreshold) {
                    $unusualMap[$e->id] = [
                        'avg' => $base['avg'],
                        'count' => $base['count'],
                        'ratio' => $base['avg'] > 0 ? round($amt / $base['avg'], 1) : 0,
                        'batas' => $base['avg'] * $unusualThreshold,
                    ];
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
        $unusualCount = count($unusualMap);

        return view('expenses.index', compact('expenses', 'total', 'accounts', 'storeId', 'perCategory', 'categoryList', 'bulan', 'expenseBudgets', 'canSetBudget', 'expenseAccounts', 'tahunAnnual', 'annualRows', 'annualTotalBudget', 'annualTotalReal', 'expenseAvgMap', 'unusualMap', 'unusualCount', 'unusualThreshold', 'unusualWindowDays', 'unusualMinSample'));
    }

    /** Simpan budget pengeluaran per kategori bulan berjalan (owner/admin). Tanpa tabel baru. */
    public function setBudget(Request $request)
    {
        abort_unless(in_array(auth()->user()->role ?? '', ['superadmin', 'owner', 'admin']), 403, 'Hanya owner/admin.');
        $storeId = $this->activeStoreId($request);
        $data = $request->validate([
            'kategori' => 'required|string|max:100',
            'nominal' => 'required|numeric|min:0|max:999999999999',
            'bulan' => 'required|date_format:Y-m',
        ]);
        $kategori = trim($data['kategori']);
        $budgets = self::readBudgets();
        $budgets[$storeId . ':' . $data['bulan'] . ':' . $kategori] = (float) $data['nominal'];
        self::writeBudgets($budgets);
        return back()->with('success', 'Budget ' . $kategori . ' bulan ' . $data['bulan'] . ' disimpan: Rp ' . number_format((float) $data['nominal'], 0, ',', '.'));
    }

    /** @return array<string,float> key "{store_id}:{YYYY-MM}:{kategori}" => nominal */
    public static function readBudgets(): array
    {
        try {
            if (!Storage::disk('local')->exists('budget.json')) return [];
            $raw = Storage::disk('local')->get('budget.json');
            $arr = json_decode($raw, true);
            return is_array($arr) ? $arr : [];
        } catch (\Throwable $e) { return []; }
    }

    public static function writeBudgets(array $budgets): void
    {
        Storage::disk('local')->put('budget.json', json_encode($budgets, JSON_PRETTY_PRINT));
    }

    /** Ubah kategori massal (maks 100 ID, scope toko aktif). Tanpa migration. */
    public function bulkUpdateCategory(Request $request)
    {
        $storeId = $this->activeStoreId($request);
        $data = $request->validate([
            'ids' => 'required|array|min:1|max:100',
            'ids.*' => 'integer',
            'category' => 'required|string|max:100',
        ]);
        $category = trim($data['category']);
        if ($category === '') {
            return back()->withErrors(['category' => 'Kategori tujuan wajib diisi.']);
        }
        $ids = array_values(array_unique(array_map('intval', $data['ids'])));
        $diminta = count($ids);
        $updated = Expense::where('store_id', $storeId)
            ->whereIn('id', $ids)
            ->update(['category' => $category]);

        return back()->with(
            'success',
            'Kategori ' . $updated . ' dari ' . $diminta . ' pengeluaran terpilih diubah ke "' . $category . '".'
        );
    }

    public function store(Request $request)
    {
        $storeId = $this->activeStoreId($request);
        $data = $request->validate([
            'cash_account_id' => 'required|exists:cash_accounts,id',
            'category' => 'required|string|max:100',
            'amount' => 'required|numeric|min:1',
            'description' => 'nullable|string|max:1000',
            'expense_date' => 'required|date',
            'bukti' => 'nullable|image|max:2048',
        ]);

        // KEPUTUSAN BISNIS: bukti foto WAJIB untuk pengeluaran > Rp1.000.000.
        // Tanpa migration: validasi di level controller, file tetap opsional
        // di aturan 'bukti' untuk nominal kecil.
        if ((float) $data['amount'] > 1000000 && !$request->hasFile('bukti')) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'bukti' => 'Pengeluaran di atas Rp1.000.000 wajib melampirkan foto bukti (nota/kwitansi).',
            ]);
        }

        // GATE PENGELUARAN BESAR: nominal > Rp10.000.000 hanya owner/admin/superadmin.
        // Tanpa migration: gate role di level controller, setelah validasi dasar.
        if ((float) $data['amount'] > 10000000
            && !in_array($request->user()?->role ?? '', ['superadmin', 'owner', 'admin'])) {
            abort(403, 'Pengeluaran di atas Rp10.000.000 hanya boleh dicatat oleh owner/admin.');
        }

        // Kunci Periode: tolak bila tanggal pengeluaran < batas (banding date-only).
        \App\Support\PeriodLock::check($storeId, $data['expense_date'], 'Tanggal pengeluaran');

        // Pola marker bukti ala SaleController@pay: path foto nota opsional
        // ditempel di kolom description sebagai "[BUKTI:path]", tanpa kolom/migrasi baru.
        $desc = $data['description'] ?? null;
        if ($request->hasFile('bukti')) {
            $path = $request->file('bukti')->store('bukti-pengeluaran', 'public');
            $desc = trim(($desc ? $desc . ' ' : '') . '[BUKTI:' . $path . ']');
        }

        DB::transaction(function () use ($data, $desc, $storeId, $request) {
            $account = CashAccount::whereKey($data['cash_account_id'])
                ->where('store_id', $storeId)->lockForUpdate()->firstOrFail();
            if ((float) $account->balance < (float) $data['amount']) {
                abort(422, 'Saldo akun kas tidak mencukupi.');
            }

            $expense = Expense::create([
                'store_id' => $storeId,
                'category' => $data['category'],
                'amount' => $data['amount'],
                'description' => $desc,
                'expense_date' => $data['expense_date'],
                'user_id' => $request->user()?->id,
            ]);

            $account->decrement('balance', $data['amount']);

            CashTransaction::create([
                'store_id' => $storeId,
                'cash_account_id' => $account->id,
                'type' => 'keluar',
                'category' => 'pengeluaran',
                'amount' => $data['amount'],
                'ref_type' => 'expense',
                'ref_id' => $expense->id,
                'description' => 'Pengeluaran: ' . $data['category'] . ($desc ? ' — ' . $desc : ''),
                'user_id' => $request->user()?->id,
            ]);
        });

        return back()->with('success', 'Pengeluaran tersimpan & kas berkurang.');
    }

    /**
     * UNDUH TEMPLATE CSV pengeluaran: header + 1 baris contoh, delimiter ;, BOM.
     * Kolom cocok dengan @import (tanggal,kategori,nominal,keterangan,akun_kas).
     * GET /expenses/template — didaftarkan SEBELUM resource agar tak tertabrak {expense}.
     */
    public function template()
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv(
                $out,
                ['tanggal', 'kategori', 'nominal', 'keterangan', 'akun_kas'],
                ';'
            );
            fputcsv(
                $out,
                [date('Y-m-d'), 'listrik', '250000', 'Bayar listrik bulan berjalan', 'Kas Utama'],
                ';'
            );
            fclose($out);
        }, 'template_pengeluaran.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * IMPORT CSV pengeluaran massal. Pola ProductController@import
     * (delimiter auto ;/, + BOM strip, validasi per baris, laporan baris gagal).
     *
     * Format kolom: tanggal,kategori,nominal,keterangan[,akun_kas].
     * - tanggal kosong = hari ini; format diterima Y-m-d / d-m-Y / d/m/Y / Y/m/d.
     * - akun_kas opsional: dicocokkan NAMA akun (case-insensitive) di toko aktif;
     *   kosong/tak diisi = akun kas default toko (akun pertama).
     *
     * TANPA JURNAL GANDA: setiap baris valid = 1 Expense + 1 CashTransaction
     * 'keluar' biasa (kas dipotong, persis alur store manual). Ini transaksi
     * berjalan, BUKAN saldo awal — jangan import saldo awal lewat sini.
     *
     * Aturan bisnis yang ditegakkan per baris: nominal >= 1, gate > Rp10jt
     * hanya owner/admin/superadmin, kunci periode, saldo kas mencukupi.
     * Aturan bukti foto > Rp1jt (store manual) TIDAK berlaku di import
     * karena CSV tidak membawa file — baris besar tercatat tanpa marker bukti.
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
        $canBig = in_array($request->user()?->role ?? '', ['superadmin', 'owner', 'admin'], true);

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
            $kategori = $g('kategori');
            $nominalRaw = str_replace([' ', '_'], '', $g('nominal'));
            $tanggalRaw = $g('tanggal');
            $keterangan = $g('keterangan');
            $akunKasRaw = $g('akun_kas');

            // Normalisasi tanggal: kosong = hari ini.
            $tanggal = null;
            if ($tanggalRaw === '') {
                $tanggal = date('Y-m-d');
            } else {
                foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'Y/m/d'] as $fmt) {
                    try {
                        $dt = \Carbon\Carbon::createFromFormat($fmt, $tanggalRaw);
                        if ($dt && $dt->format($fmt) === $tanggalRaw) { $tanggal = $dt->format('Y-m-d'); break; }
                    } catch (\Throwable $e) { /* coba format berikut */ }
                }
                if ($tanggal === null) {
                    $ts = strtotime($tanggalRaw);
                    $tanggal = $ts ? date('Y-m-d', $ts) : null;
                }
            }

            if ($kategori === '') $err = 'kategori kosong';
            elseif (mb_strlen($kategori) > 100) $err = 'kategori maksimal 100 karakter';
            elseif ($nominalRaw === '' || !is_numeric($nominalRaw)) $err = 'nominal bukan angka';
            elseif ((float) $nominalRaw < 1) $err = 'nominal minimal 1';
            elseif ($tanggal === null) $err = 'tanggal tidak valid: ' . $tanggalRaw;
            elseif (mb_strlen($keterangan) > 1000) $err = 'keterangan maksimal 1000 karakter';
            elseif ((float) $nominalRaw > 10000000 && !$canBig) $err = 'nominal di atas Rp10.000.000 hanya boleh dicatat owner/admin';
            if ($err) { $fail[] = "Baris $line: $err"; continue; }

            $nominal = (float) $nominalRaw;
            $account = $defaultAccount;
            if ($akunKasRaw !== '') {
                $account = $accountMap[mb_strtolower($akunKasRaw)] ?? null;
                if (!$account) { $fail[] = "Baris $line: akun_kas tidak dikenal: " . $akunKasRaw; continue; }
            }

            // Kunci periode: tolak baris bertanggal < batas (tanpa abort agar baris lain lanjut).
            if (\App\Support\PeriodLock::isLocked($storeId, $tanggal)) {
                $fail[] = "Baris $line: periode terkunci (tutup buku per "
                    . \App\Support\PeriodLock::get($storeId) . ')';
                continue;
            }

            try {
                DB::transaction(function () use ($nominal, $tanggal, $kategori, $keterangan, $account, $storeId, $request) {
                    $locked = CashAccount::whereKey($account->id)
                        ->where('store_id', $storeId)->lockForUpdate()->firstOrFail();
                    if ((float) $locked->balance < $nominal) {
                        throw new \RuntimeException('Saldo akun kas "' . $locked->name . '" tidak mencukupi.');
                    }
                    $expense = Expense::create([
                        'store_id' => $storeId,
                        'category' => $kategori,
                        'amount' => $nominal,
                        'description' => $keterangan !== '' ? $keterangan : null,
                        'expense_date' => $tanggal,
                        'user_id' => $request->user()?->id,
                    ]);
                    $locked->decrement('balance', $nominal);
                    CashTransaction::create([
                        'store_id' => $storeId,
                        'cash_account_id' => $locked->id,
                        'type' => 'keluar',
                        'category' => 'pengeluaran',
                        'amount' => $nominal,
                        'ref_type' => 'expense',
                        'ref_id' => $expense->id,
                        'description' => 'Pengeluaran: ' . $kategori . ($keterangan !== '' ? ' — ' . $keterangan : ''),
                        'user_id' => $request->user()?->id,
                    ]);
                });
                $ok++;
            } catch (\Throwable $e) {
                $fail[] = "Baris $line: " . $e->getMessage();
            }
        }
        fclose($handle);

        return redirect()->route('expenses.index')
            ->with('success', "Import selesai: $ok baris berhasil, " . count($fail) . ' gagal.'
                . ' Setiap baris = 1 pengeluaran + 1 kas keluar (transaksi berjalan, bukan saldo awal).')
            ->with('import_fail', $fail);
    }

    public function destroy(Request $request, Expense $expense)
    {
        $storeId = $this->activeStoreId($request);
        if ((int) $expense->store_id !== (int) $storeId) abort(403);
        // Kunci Periode: pengeluaran di periode terkunci tidak boleh dihapus.
        \App\Support\PeriodLock::check($expense->store_id, $expense->expense_date, 'Tanggal pengeluaran');

        DB::transaction(function () use ($expense, $storeId, $request) {
            $cash = CashTransaction::where('store_id', $storeId)
                ->where('ref_type', 'expense')->where('ref_id', $expense->id)
                ->orderByDesc('id')->first();
            if ($cash && $cash->cash_account_id) {
                $account = CashAccount::whereKey($cash->cash_account_id)->lockForUpdate()->first();
                if ($account) $account->increment('balance', $expense->amount);
                $cash->delete();
            }
            $expense->delete();
        });

        return back()->with('success', 'Pengeluaran dihapus & saldo kas dikembalikan.');
    }
}
