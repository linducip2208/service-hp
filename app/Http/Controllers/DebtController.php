<?php

namespace App\Http\Controllers;

use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\Debt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DebtController extends Controller
{
    private function activeStoreId(Request $request): ?int
    {
        return session('active_store_id')
            ?? $request->user()?->active_store_id
            ?? \App\Models\Store::where('is_active', true)->value('id');
    }

    private function autoStatus(float $total, float $paid): string
    {
        if ($paid <= 0) return 'belum';
        if ($paid >= $total) return 'lunas';
        return 'sebagian';
    }

    public function index(Request $request)
    {
        $storeId = $this->activeStoreId($request);

        // Kirim pengingat WA via ?remind={id} (pakai route index yang sudah ada)
        if ($request->filled('remind')) {
            $d = Debt::where('store_id', $storeId)->whereKey($request->remind)->firstOrFail();
            $phone = $this->resolvePhone($d, $storeId);
            if (!$phone) {
                return back()->with('error', 'Nomor HP tidak ditemukan untuk "' . $d->party_name . '".');
            }
            $msg = \App\Services\WaService::render('hutang_jatuh_tempo', [
                'nama' => $d->party_name, 'tiket' => '#' . $d->id, 'status' => $d->status,
                'total' => number_format($d->remaining, 0, ',', '.'),
                'jadwal' => ($d->due_date?->format('d/m/Y') ?? '-'),
                'track' => url('/track'),
            ]);
            if ($msg === '') {
                $msg = "Halo {$d->party_name}, pengingat " . ($d->type === 'hutang' ? 'tagihan kami' : 'pembayaran Anda')
                    . " sebesar Rp " . number_format($d->remaining, 0, ',', '.')
                    . " (jatuh tempo: " . ($d->due_date?->format('d/m/Y') ?? '-') . "). Terima kasih. — " . ($request->user()?->name ?? 'ServiceHP');
            }
            $ok = \App\Services\WaService::send($phone, $msg, 'debt_reminder', $storeId);
            return back()->with($ok ? 'success' : 'error', $ok ? 'Pengingat WA dikirim/diantrikan.' : 'Gagal mengirim WA (cek konfigurasi gateway).');
        }

        $debts = Debt::where('store_id', $storeId)
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when(
                $request->filled('q'),
                fn ($q) => $q->where('party_name', 'like', '%' . $request->q . '%')
            )
            ->when($request->boolean('tanpa_tempo'), fn ($q) => $q->whereNull('due_date')->where('status', '!=', 'lunas'))
            ->orderByDesc('id')->paginate(20)->withQueryString();
        // Export CSV (?export=csv) — hormati filter aktif + scope toko
        if ($request->get('export') === 'csv') {
            $rows = Debt::where('store_id', $storeId)
                ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
                ->when(
                    $request->filled('q'),
                    fn ($q) => $q->where('party_name', 'like', '%' . $request->q . '%')
                )
                ->when($request->boolean('tanpa_tempo'), fn ($q) => $q->whereNull('due_date')->where('status', '!=', 'lunas'))
                ->orderByDesc('id')->limit(5000)->get();
            $filename = 'hutang-piutang-' . now()->format('Ymd-His') . '.csv';
            return response()->streamDownload(function () use ($rows) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['id', 'tipe', 'pihak', 'total', 'dibayar', 'sisa', 'status', 'jatuh_tempo', 'dibuat'], ';');
                foreach ($rows as $d) {
                    fputcsv($out, [$d->id, $d->type, $d->party_name, (float) $d->total, (float) $d->paid, (float) $d->remaining, $d->status, $d->due_date?->format('Y-m-d') ?? '', $d->created_at?->format('Y-m-d H:i') ?? ''], ';');
                }
                fclose($out);
            }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }
        $totalHutang = Debt::where('store_id', $storeId)->where('type', 'hutang')->where('status', '!=', 'lunas')->sum('remaining');
        $totalPiutang = Debt::where('store_id', $storeId)->where('type', 'piutang')->where('status', '!=', 'lunas')->sum('remaining');
        $accounts = CashAccount::where('store_id', $storeId)->orderBy('name')->get();

        // Widget: jatuh tempo ≤ 7 hari (belum lunas)
        $dueSoon = Debt::where('store_id', $storeId)->where('status', '!=', 'lunas')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', now()->addDays(7)->toDateString())
            ->orderBy('due_date')->limit(10)->get();

        // Widget: belum lunas TANPA due_date (minta tetapkan tempo) — tanpa migration/route baru
        $noDue = Debt::where('store_id', $storeId)->where('status', '!=', 'lunas')
            ->whereNull('due_date')
            ->orderByDesc('id')->limit(10)->get();
        $noDueTotal = Debt::where('store_id', $storeId)->where('status', '!=', 'lunas')
            ->whereNull('due_date')->count();

        // Aging sederhana per tipe berdasar umur (hari) sejak dibuat: 0-30 / 31-60 / >60
        $aging = ['hutang' => ['0-30' => 0, '31-60' => 0, '>60' => 0], 'piutang' => ['0-30' => 0, '31-60' => 0, '>60' => 0]];
        try {
            $rows = Debt::where('store_id', $storeId)->where('status', '!=', 'lunas')
                ->select('type', 'remaining', 'created_at')->get();
            foreach ($rows as $r) {
                $days = (int) now()->diffInDays($r->created_at);
                $bucket = $days <= 30 ? '0-30' : ($days <= 60 ? '31-60' : '>60');
                $aging[$r->type][$bucket] += (float) $r->remaining;
            }
        } catch (\Throwable $e) {
            report($e);
        }

        // Ringkasan per pihak: nama → total tagihan, dibayar, sisa, jumlah nota, jatuh tempo terdekat.
        // Hormati filter aktif (type/status/tanpa_tempo) + scope toko, tanpa query tambahan per baris.
        $perParty = Debt::where('store_id', $storeId)
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when(
                $request->filled('q'),
                fn ($q) => $q->where('party_name', 'like', '%' . $request->q . '%')
            )
            ->when($request->boolean('tanpa_tempo'), fn ($q) => $q->whereNull('due_date')->where('status', '!=', 'lunas'))
            ->selectRaw('party_name, SUM(total) as sum_total, SUM(paid) as sum_paid, SUM(remaining) as sum_remaining, COUNT(*) as nota_count, MIN(due_date) as nearest_due')
            ->groupBy('party_name')
            ->orderByDesc('sum_remaining')
            ->limit(50)
            ->get();

        // Riwayat cicilan per nota di halaman ini (cash ref debt) untuk kolom Riwayat —
        // 1 query grup, tanpa query tambahan per baris.
        $paymentsByDebt = collect();
        try {
            $pageIds = $debts->getCollection()->pluck('id')->all();
            if (! empty($pageIds)) {
                $paymentsByDebt = CashTransaction::where('store_id', $storeId)
                    ->where('ref_type', 'debt')
                    ->whereIn('ref_id', $pageIds)
                    ->orderByDesc('created_at')
                    ->get()
                    ->groupBy('ref_id');
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return view('debts.index', compact('debts', 'totalHutang', 'totalPiutang', 'accounts', 'storeId', 'dueSoon', 'aging', 'perParty', 'paymentsByDebt', 'noDue', 'noDueTotal'));
    }

    /** Cari nomor HP pihak dari tabel customers/suppliers berdasar kecocokan nama. */
    private function resolvePhone(Debt $debt, ?int $storeId): ?string
    {
        try {
            $name = trim((string) $debt->party_name);
            if ($debt->type === 'piutang') {
                $c = \App\Models\Customer::where('store_id', $storeId)->where('name', $name)->first()
                    ?? \App\Models\Customer::where('store_id', $storeId)->where('name', 'like', "%{$name}%")->first();
                if ($c?->phone) return $c->phone;
            } else {
                $s = \App\Models\Supplier::where('name', $name)->first()
                    ?? \App\Models\Supplier::where('name', 'like', "%{$name}%")->first();
                if ($s?->phone) return $s->phone;
            }
        } catch (\Throwable $e) {
            report($e);
        }
        return null;
    }

    public function store(Request $request)
    {
        $storeId = $this->activeStoreId($request);
        $data = $request->validate([
            'type' => 'required|in:hutang,piutang',
            'party_name' => 'required|string|max:255',
            'total' => 'required|numeric|min:1',
            'paid' => 'nullable|numeric|min:0',
            'due_date' => 'nullable|date',
        ], [
            'type.required' => 'Jenis (hutang/piutang) wajib dipilih.',
            'party_name.required' => 'Nama pihak wajib diisi.',
            'total.required' => 'Nominal total wajib diisi.',
            'total.min' => 'Nominal total minimal Rp 1.',
            'due_date.date' => 'Jatuh tempo harus tanggal yang valid.',
        ]);
        $paid = (float) ($data['paid'] ?? 0);
        if ($paid > (float) $data['total']) $paid = (float) $data['total'];

        // Kunci Periode: pencatatan baru bertanggal hari ini; tolak bila hari ini < batas.
        // (due_date = jatuh tempo, bukan tanggal dokumen — tidak ikut dikunci.)
        \App\Support\PeriodLock::check($storeId, now()->toDateString(), 'Tanggal pencatatan');

        Debt::create([
            'store_id' => $storeId,
            'type' => $data['type'],
            'party_name' => $data['party_name'],
            'total' => $data['total'],
            'paid' => $paid,
            'remaining' => (float) $data['total'] - $paid,
            'due_date' => $data['due_date'] ?? null,
            'status' => $this->autoStatus((float) $data['total'], $paid),
        ]);

        return back()->with('success', 'Data hutang/piutang tersimpan.');
    }

    /** Surat resmi: PIUTANG = surat tagihan; HUTANG = surat pernyataan hutang ke supplier. */
    public function statement(Request $request, Debt $debt)
    {
        $storeId = $this->activeStoreId($request);
        if ((int) $debt->store_id !== (int) $storeId) abort(403, 'Bukan data hutang/piutang toko aktif.');
        $store = \App\Models\Store::whereKey($debt->store_id)->first();
        $payments = \App\Models\CashTransaction::where('store_id', $debt->store_id)
            ->where('ref_type', 'debt')
            ->where('ref_id', $debt->id)
            ->orderBy('created_at')
            ->get();
        return view('debts.statement', compact('debt', 'store', 'payments'));
    }

    /** Cetak massal: gabungkan surat tagihan (piutang) + surat pernyataan hutang (hutang) dalam 1 halaman cetak. */
    public function bulkStatements(Request $request)
    {
        $storeId = $this->activeStoreId($request);
        $ids = array_values(array_unique(array_map('intval', (array) $request->query('ids', $request->input('ids', [])))));
        $ids = array_values(array_filter($ids, fn ($v) => $v > 0));
        if (empty($ids)) {
            return back()->with('error', 'Pilih minimal 1 hutang/piutang untuk dicetak.');
        }
        if (count($ids) > 100) {
            return back()->with('error', 'Maksimal 100 surat per cetak massal.');
        }

        $debts = Debt::where('store_id', $storeId)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();
        if ($debts->isEmpty()) {
            return back()->with('error', 'Tidak ada hutang/piutang valid yang dipilih.');
        }

        $store = \App\Models\Store::whereKey($storeId)->first();
        $paymentsByDebt = \App\Models\CashTransaction::where('store_id', $storeId)
            ->where('ref_type', 'debt')
            ->whereIn('ref_id', $debts->pluck('id')->all())
            ->orderBy('created_at')
            ->get()
            ->groupBy('ref_id');

        return view('debts.statements', compact('debts', 'store', 'paymentsByDebt'));
    }

    /** Kirim pengingat WA massal ke piutang/hutang terpilih (maks 50, lewati tanpa nomor). */
    public function bulkRemind(Request $request)
    {
        $storeId = $this->activeStoreId($request);
        $data = $request->validate([
            'debt_ids' => 'required|array|min:1|max:50',
            'debt_ids.*' => 'integer',
        ], [
            'debt_ids.required' => 'Pilih minimal 1 hutang/piutang untuk diingatkan.',
            'debt_ids.min' => 'Pilih minimal 1 hutang/piutang untuk diingatkan.',
            'debt_ids.max' => 'Maksimal 50 baris per pengingat massal.',
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['debt_ids'])));
        $ids = array_values(array_filter($ids, fn ($v) => $v > 0));
        $ids = array_slice($ids, 0, 50);
        if (empty($ids)) {
            return back()->with('error', 'Pilih minimal 1 hutang/piutang untuk diingatkan.');
        }

        $debts = Debt::where('store_id', $storeId)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();
        if ($debts->isEmpty()) {
            return back()->with('error', 'Tidak ada hutang/piutang valid yang dipilih.');
        }

        // Batch: petakan exact nama→telepon sekali (dulu 2 query per baris).
        // LIKE fallback hanya untuk nama yang tak cocok exact.
        $exactCustomers = \App\Models\Customer::where('store_id', $storeId)
            ->whereIn('name', $debts->where('type', 'piutang')->map(fn ($d) => trim((string) $d->party_name))->filter()->unique()->values()->all())
            ->pluck('phone', 'name');
        $exactSuppliers = \App\Models\Supplier::whereIn('name', $debts->where('type', 'hutang')->map(fn ($d) => trim((string) $d->party_name))->filter()->unique()->values()->all())
            ->pluck('phone', 'name');

        $lines = [];
        $sent = 0;
        $skipped = 0;
        $failed = 0;
        foreach ($debts as $d) {
            $phone = $exactCustomers->get(trim((string) $d->party_name)) ?? $exactSuppliers->get(trim((string) $d->party_name));
            if (!$phone) {
                $phone = $this->resolvePhone($d, $storeId);
            }
            if (!$phone) {
                $skipped++;
                $lines[] = '#' . $d->id . ' ' . $d->party_name . ': dilewati (tanpa nomor)';
                continue;
            }
            $msg = \App\Services\WaService::render('hutang_jatuh_tempo', [
                'nama' => $d->party_name,
                'tiket' => '#' . $d->id,
                'status' => $d->status,
                'total' => number_format($d->remaining, 0, ',', '.'),
                'jadwal' => ($d->due_date?->format('d/m/Y') ?? '-'),
                'track' => url('/track'),
            ]);
            if ($msg === '') {
                $msg = 'Halo ' . $d->party_name . ', pengingat '
                    . ($d->type === 'hutang' ? 'tagihan kami' : 'pembayaran Anda')
                    . ' sebesar Rp ' . number_format($d->remaining, 0, ',', '.')
                    . ' (jatuh tempo: ' . ($d->due_date?->format('d/m/Y') ?? '-') . '). '
                    . 'Terima kasih. — ' . ($request->user()?->name ?? 'ServiceHP');
            }
            $ok = \App\Services\WaService::send($phone, $msg, 'debt_reminder', $storeId);
            if ($ok) {
                $sent++;
                $lines[] = '#' . $d->id . ' ' . $d->party_name . ': terkirim/diantrikan';
            } else {
                $failed++;
                $lines[] = '#' . $d->id . ' ' . $d->party_name . ': gagal kirim (cek gateway)';
            }
        }

        $summary = 'Pengingat massal: ' . $sent . ' terkirim/diantrikan, '
            . $skipped . ' dilewati (tanpa nomor), '
            . $failed . ' gagal. ' . implode(' | ', $lines);

        return back()->with($sent > 0 ? 'success' : 'error', $summary);
    }

    /** Bayar massal: lunasi penuh hutang/piutang terpilih via 1 akun kas. */
    public function bulkPay(Request $request)
    {
        $storeId = $this->activeStoreId($request);
        $data = $request->validate([
            'debt_ids' => 'required|array|min:1',
            'debt_ids.*' => 'integer|exists:debts,id',
            'cash_account_id' => 'required|exists:cash_accounts,id',
            'bukti' => 'nullable|image|max:2048',
        ], [
            'debt_ids.required' => 'Pilih minimal 1 hutang/piutang yang belum lunas.',
            'debt_ids.min' => 'Pilih minimal 1 hutang/piutang yang belum lunas.',
            'cash_account_id.required' => 'Pilih akun kas dulu.',
            'cash_account_id.exists' => 'Akun kas tidak ditemukan.',
            'bukti.image' => 'Bukti harus berupa gambar.',
            'bukti.max' => 'Ukuran bukti maksimal 2 MB.',
        ]);

        // Kunci Periode: pelunasan bertanggal hari ini.
        \App\Support\PeriodLock::check($storeId, now()->toDateString(), 'Tanggal pembayaran');

        // Bukti opsional: pola marker [BUKTI:path] ala DebtController@update —
        // file disimpan di storage, path dicatat di description kas (tanpa kolom baru).
        $buktiSuffix = '';
        if ($request->hasFile('bukti')) {
            $buktiSuffix = ' [BUKTI:' . $request->file('bukti')->store('bukti-pembayaran', 'public') . ']';
        }

        $ids = array_values(array_unique(array_map('intval', $data['debt_ids'])));

        $result = DB::transaction(function () use ($ids, $data, $storeId, $request, $buktiSuffix) {
            $account = CashAccount::whereKey($data['cash_account_id'])
                ->where('store_id', $storeId)->lockForUpdate()->firstOrFail();

            $debts = Debt::where('store_id', $storeId)
                ->whereIn('id', $ids)
                ->where('status', '!=', 'lunas')
                ->lockForUpdate()->get();

            if ($debts->isEmpty()) abort(422, 'Tidak ada data belum lunas yang dipilih.');

            $need = (float) $debts->where('type', 'hutang')->sum('remaining');

            // GATE KAS (Continuous 168): bayar hutang = kas KELUAR — samakan dengan
            // CashController@store: total keluar > Rp10jt hanya owner/admin/superadmin.
            // Gate di TOTAL hutang (bukan per-nota) agar tak bisa dipecah via bulk.
            // Terima piutang (kas MASUK) tetap bebas agar tidak hambat penagihan.
            $roleNow = $request->user()?->role ?? auth()->user()->role ?? '';
            if ($need > 10000000 && ! in_array($roleNow, ['superadmin', 'owner', 'admin'], true)) {
                abort(422, 'Total bayar hutang di atas Rp10.000.000 hanya boleh dilakukan owner/admin/superadmin. Minta owner/admin/superadmin yang login lalu ulangi pelunasan massal.');
            }
            // GATE BUKTI (Continuous 170): samakan CashController@store — total
            // keluar > Rp10jt wajib foto bukti. Terima piutang (kas MASUK) bebas.
            if ($need > 10000000 && ! $request->hasFile('bukti')) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'bukti' => 'Total bayar hutang di atas Rp10.000.000 wajib melampirkan foto bukti transfer (maks 2 MB).',
                ]);
            }

            if ($need > 0 && (float) $account->balance < $need) {
                abort(422, 'Saldo akun kas tidak mencukupi untuk melunasi hutang terpilih (butuh Rp ' . number_format($need, 0, ',', '.') . ').');
            }

            $count = 0;
            foreach ($debts as $debt) {
                $sisa = (float) $debt->total - (float) $debt->paid;
                if ($sisa <= 0) continue;
                if ($debt->type === 'hutang') {
                    $account->decrement('balance', $sisa);
                    $cashType = 'keluar';
                    $desc = "Bayar hutang (massal) ke {$debt->party_name}" . $buktiSuffix;
                } else {
                    $account->increment('balance', $sisa);
                    $cashType = 'masuk';
                    $desc = "Terima piutang (massal) dari {$debt->party_name}";
                }
                $debt->update([
                    'paid' => (float) $debt->total,
                    'remaining' => 0,
                    'status' => 'lunas',
                ]);
                CashTransaction::create([
                    'store_id' => $storeId,
                    'cash_account_id' => $account->id,
                    'type' => $cashType,
                    'category' => $debt->type,
                    'amount' => $sisa,
                    'ref_type' => 'debt',
                    'ref_id' => $debt->id,
                    'description' => $desc,
                    'user_id' => $request->user()?->id,
                ]);
                $count++;
            }

            if ($count === 0) abort(422, 'Tidak ada data yang bisa dilunasi.');
            return $count;
        });

        return back()->with('success', "Berhasil melunasi {$result} hutang/piutang terpilih.");
    }

    /** Ubah tanggal jatuh tempo inline per baris (tanpa mengubah cicilan/status). */
    public function updateDueDate(Request $request, Debt $debt)
    {
        $storeId = $this->activeStoreId($request);
        if ((int) $debt->store_id !== (int) $storeId) abort(403, 'Bukan data hutang/piutang toko aktif.');

        $data = $request->validate([
            'due_date' => 'nullable|date',
        ], [
            'due_date.date' => 'Jatuh tempo harus tanggal yang valid.',
        ]);

        $debt->update(['due_date' => $data['due_date'] ?? null]);

        return back()->with('success', 'Jatuh tempo diperbarui.');
    }

    /**
     * Geser jatuh tempo massal (cermin BookingController@bulkReschedule):
     * update due_date massal tanpa migration.
     * Hanya belum lunas (belum/sebagian) — baris lunas dilewati + dilaporkan.
     * Target: tanggal baru ATAU geser +N hari (bila keduanya diisi,
     * tanggal baru menang). Maks 50 per submit.
     */
    public function bulkDueDate(Request $request)
    {
        $storeId = $this->activeStoreId($request);
        $data = $request->validate([
            'debt_ids' => 'required|array|min:1|max:50',
            'debt_ids.*' => 'integer|exists:debts,id',
            'target_date' => 'nullable|date',
            'shift_days' => 'nullable|integer|min:1|max:90',
        ], [
            'debt_ids.required' => 'Pilih minimal 1 hutang/piutang belum lunas.',
            'debt_ids.min' => 'Pilih minimal 1 hutang/piutang belum lunas.',
            'debt_ids.max' => 'Maksimal 50 baris per geser jatuh tempo massal.',
            'target_date.date' => 'Tanggal baru tidak valid (format: YYYY-MM-DD).',
            'shift_days.integer' => 'Geser hari harus angka 1–90.',
            'shift_days.min' => 'Geser hari minimal +1 hari.',
            'shift_days.max' => 'Geser hari maksimal +90 hari.',
        ]);

        $target = trim((string) ($data['target_date'] ?? ''));
        $shift = isset($data['shift_days']) && $data['shift_days'] !== null
            ? (int) $data['shift_days']
            : null;

        if ($target === '' && $shift === null) {
            return back()->with('error', 'Isi tanggal baru ATAU geser +N hari dulu.');
        }

        try {
            $targetCarbon = $target !== '' ? \Carbon\Carbon::parse($target)->startOfDay() : null;
        } catch (\Throwable $e) {
            return back()->with('error', 'Tanggal baru tidak valid (format: YYYY-MM-DD).');
        }

        $ids = array_values(array_unique(array_map('intval', $data['debt_ids'])));
        $ids = array_values(array_filter($ids, fn ($v) => $v > 0));
        $ids = array_slice($ids, 0, 50);
        if (empty($ids)) {
            return back()->with('error', 'Pilih minimal 1 hutang/piutang belum lunas.');
        }

        $rows = Debt::where('store_id', $storeId)->whereIn('id', $ids)->get()->keyBy('id');

        $okLines = [];
        $skipLines = [];
        $failLines = [];

        foreach ($ids as $id) {
            $d = $rows->get($id);
            if (! $d) {
                $failLines[] = "#{$id}: bukan data hutang/piutang toko aktif";
                continue;
            }
            if ($d->status === 'lunas') {
                $skipLines[] = "#{$d->id} {$d->party_name}: dilewati (sudah lunas)";
                continue;
            }
            try {
                $old = $d->due_date ? $d->due_date->format('d/m/Y') : '-';
                $base = $d->due_date
                    ? \Carbon\Carbon::parse($d->due_date->format('Y-m-d'))->startOfDay()
                    : now()->startOfDay();
                $newDate = $targetCarbon ? $targetCarbon->copy() : $base->copy()->addDays($shift);
                Debt::whereKey($d->id)->update(['due_date' => $newDate->format('Y-m-d')]);
                $okLines[] = "#{$d->id} {$d->party_name}: {$old} → " . $newDate->format('d/m/Y');
            } catch (\Throwable $e) {
                report($e);
                $failLines[] = "#{$d->id} {$d->party_name}: gagal ({$e->getMessage()})";
            }
        }

        $mode = $targetCarbon ? 'ke ' . $targetCarbon->format('d/m/Y') : "+{$shift} hari";
        $summary = 'Geser jatuh tempo massal ' . $mode . ': ' . count($okLines) . ' digeser, '
            . count($skipLines) . ' dilewati (sudah lunas / bukan toko aktif), '
            . count($failLines) . ' gagal.';
        $detail = array_merge($okLines, $skipLines, $failLines);
        if ($detail) {
            $summary .= ' | ' . implode('; ', $detail);
        }

        if (count($okLines) === 0 && count($failLines) > 0) {
            return back()->with('error', $summary);
        }

        return back()->with(count($okLines) > 0 ? 'success' : 'error', $summary);
    }

    /** Bayar cicilan: tambah paid, catat kas sesuai type, update status otomatis. */
    public function update(Request $request, Debt $debt)
    {
        $storeId = $this->activeStoreId($request);
        if ((int) $debt->store_id !== (int) $storeId) abort(403, 'Bukan data hutang/piutang toko aktif.');

        $data = $request->validate([
            'pay_amount' => 'required|numeric|min:1',
            'cash_account_id' => 'required|exists:cash_accounts,id',
            'bukti' => 'nullable|image|max:2048',
        ], [
            'pay_amount.required' => 'Nominal cicilan wajib diisi.',
            'pay_amount.numeric' => 'Nominal cicilan harus berupa angka.',
            'pay_amount.min' => 'Nominal cicilan minimal Rp 1.',
            'cash_account_id.required' => 'Pilih akun kas dulu.',
            'cash_account_id.exists' => 'Akun kas tidak ditemukan.',
            'bukti.image' => 'Bukti harus berupa file gambar.',
            'bukti.max' => 'Ukuran bukti maksimal 2 MB.',
        ]);

        // Kunci Periode: cicilan dibayar hari ini (tanggal bayar hari ini);
        // bila request membawa input tanggal eksplisit, tegakkan juga untuk input itu.
        \App\Support\PeriodLock::check($storeId, now()->toDateString(), 'Tanggal pembayaran');
        \App\Support\PeriodLock::checkRequestDates($storeId, $request, 'Tanggal pembayaran');

        // Bukti opsional: pola marker [BUKTI:path] ala SaleController@pay —
        // file disimpan di storage, path dicatat di description kas (tanpa kolom baru).
        $buktiSuffix = '';
        if ($request->hasFile('bukti')) {
            $path = $request->file('bukti')->store('bukti-pembayaran', 'public');
            $buktiSuffix = ' [BUKTI:' . $path . ']';
        }

        DB::transaction(function () use ($data, $debt, $storeId, $request, $buktiSuffix) {
            $debt = Debt::whereKey($debt->id)->lockForUpdate()->firstOrFail();
            $sisa = (float) $debt->total - (float) $debt->paid;
            $bayar = min((float) $data['pay_amount'], $sisa);

            $account = CashAccount::whereKey($data['cash_account_id'])
                ->where('store_id', $storeId)->lockForUpdate()->firstOrFail();

            if ($debt->type === 'hutang') {
                // Bayar hutang -> kas keluar
                // GATE KAS (Continuous 168): samakan dengan CashController@store —
                // keluar > Rp10jt hanya owner/admin/superadmin. Cicilan piutang
                // (kas MASUK) sengaja bebas agar tidak hambat penagihan.
                $roleNow = $request->user()?->role ?? auth()->user()->role ?? '';
                if ($bayar > 10000000 && ! in_array($roleNow, ['superadmin', 'owner', 'admin'], true)) {
                    abort(422, 'Bayar hutang di atas Rp10.000.000 hanya boleh dilakukan owner/admin/superadmin. Minta owner/admin/superadmin yang login lalu ulangi pembayaran.');
                }
                // GATE BUKTI (Continuous 170): samakan CashController@store —
                // bayar hutang > Rp10jt wajib foto bukti (bukti sudah opsional
                // untuk nominal kecil via $buktiSuffix di atas).
                if ($bayar > 10000000 && ! $request->hasFile('bukti')) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'bukti' => 'Bayar hutang di atas Rp10.000.000 wajib melampirkan foto bukti transfer (maks 2 MB).',
                    ]);
                }
                if ((float) $account->balance < $bayar) abort(422, 'Saldo akun kas tidak mencukupi.');
                $account->decrement('balance', $bayar);
                $cashType = 'keluar';
                $desc = "Bayar hutang ke {$debt->party_name}" . $buktiSuffix;
            } else {
                // Terima piutang -> kas masuk
                $account->increment('balance', $bayar);
                $cashType = 'masuk';
                $desc = "Terima piutang dari {$debt->party_name}" . $buktiSuffix;
            }

            $newPaid = (float) $debt->paid + $bayar;
            $debt->update([
                'paid' => $newPaid,
                'remaining' => (float) $debt->total - $newPaid,
                'status' => $this->autoStatus((float) $debt->total, $newPaid),
            ]);

            CashTransaction::create([
                'store_id' => $storeId,
                'cash_account_id' => $account->id,
                'type' => $cashType,
                'category' => $debt->type,
                'amount' => $bayar,
                'ref_type' => 'debt',
                'ref_id' => $debt->id,
                'description' => $desc,
                'user_id' => $request->user()?->id,
            ]);
        });

        return back()->with('success', 'Cicilan tercatat & status diperbarui.');
    }

    /** Template CSV migrasi saldo awal hutang/piutang (delimiter ; + BOM ala Excel Indonesia). */
    public function template()
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['tipe', 'pihak', 'total', 'terbayar', 'jatuh_tempo', 'ref'], ';');
            fputcsv($out, ['hutang', 'PT Supplier Maju', '5000000', '1000000', '2026-12-31', 'NOTA-LAMA-001'], ';');
            fputcsv($out, ['piutang', 'Budi Santoso', '2500000', '0', '2026-12-15', ''], ';');
            fclose($out);
        }, 'template_saldo_awal_hutang_piutang.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * IMPORT CSV SALDO AWAL HUTANG/PIUTANG (migrasi dari sistem lama).
     * Kolom: tipe,pihak,total,terbayar,jatuh_tempo,ref
     * (alias: type/tipe, party_name/pihak, paid/terbayar/dibayar,
     * due_date/jatuh_tempo/tempo).
     * - HANYA mencatat baris Debt (scope toko aktif): TANPA mutasi kas —
     *   tidak menyentuh cash_accounts / cash_transactions. Nilai
     *   "terbayar" > 0 diterima sebagai info historis (sudah dibayar di
     *   sistem lama), bukan pembayaran baru.
     * - Status dihitung otomatis (belum/sebagian/lunas) via autoStatus.
     * - Duplikat (tipe + pihak + total sama, pihak case-insensitive,
     *   dalam toko aktif — termasuk sesama baris file) dilewati + dilapor.
     * - Kolom ref opsional: label nota lama, disimpan sebagai ref_type
     *   "migrasi:<ref>" (ref_id null) — TANPA menaut ke transaksi apapun.
     * - Tanpa migration. POST /debts/import (file maks 2 MB).
     */
    public function import(Request $request)
    {
        $storeId = $this->activeStoreId($request);
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:2048']);

        // Kunci Periode: baris migrasi tercatat bertanggal hari ini.
        \App\Support\PeriodLock::check($storeId, now()->toDateString(), 'Tanggal pencatatan');

        // Nominal: terima "5000000", "5.000.000" (ribuan), "1500,50" (desimal koma).
        $parseNominal = function (string $v): ?float {
            $v = trim(str_replace([' ', "'", '_'], '', $v));
            if ($v === '') return null;
            if (str_contains($v, ',')) {
                $v = str_replace('.', '', $v);
                $v = str_replace(',', '.', $v);
            }
            return is_numeric($v) ? (float) $v : null;
        };
        // Tanggal: null = kosong, false = tidak valid, string Y-m-d = valid.
        $parseDate = function (string $v): string|bool|null {
            if ($v === '') return null;
            foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $fmt) {
                try {
                    $dt = \Carbon\Carbon::createFromFormat($fmt, $v);
                    if ($dt && $dt->format($fmt) === $v) return $dt->format('Y-m-d');
                } catch (\Throwable $e) {
                    continue;
                }
            }
            return false;
        };

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        // Deteksi delimiter: template memakai ';' (Excel Indonesia), file manual umumnya ','.
        $firstLine = fgets($handle) ?: '';
        rewind($handle);
        $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';
        $readCsv = function () use ($handle, $delimiter) {
            $cols = fgetcsv($handle, 0, $delimiter);
            if (is_array($cols)) $cols = array_map(fn($c) => is_string($c) ? ltrim($c, "\xEF\xBB\xBF") : $c, $cols);
            return $cols;
        };
        $header = array_map(fn($h) => strtolower(trim((string) $h)), $readCsv() ?: []);
        $ok = 0; $skipped = 0; $fail = []; $line = 1;
        $seenInFile = [];
        while (($cols = $readCsv()) !== false) {
            $line++;
            if (count($cols) === 1 && trim((string) $cols[0]) === '') continue;
            $row = array_combine(array_slice(array_merge($header, range(0, count($cols) - 1)), 0, count($cols)), $cols);
            $g = fn($k) => trim((string) ($row[$k] ?? ''));
            $err = null;

            $tipe = strtolower($g('tipe') !== '' ? $g('tipe') : $g('type'));
            $pihak = $g('pihak') !== '' ? $g('pihak') : $g('party_name');
            $total = $parseNominal($g('total'));
            $paidRaw = $g('terbayar') !== '' ? $g('terbayar') : ($g('paid') !== '' ? $g('paid') : $g('dibayar'));
            $paid = $paidRaw === '' ? 0.0 : $parseNominal($paidRaw);
            $dueRaw = $g('jatuh_tempo') !== '' ? $g('jatuh_tempo') : ($g('due_date') !== '' ? $g('due_date') : $g('tempo'));
            $ref = $g('ref');

            if (! in_array($tipe, ['hutang', 'piutang'], true)) $err = 'tipe harus hutang/piutang';
            elseif ($pihak === '') $err = 'pihak kosong';
            elseif (mb_strlen($pihak) > 255) $err = 'pihak max 255 karakter';
            elseif ($total === null) $err = 'total bukan angka';
            elseif ($total < 1) $err = 'total minimal 1';
            elseif ($paid === null) $err = 'terbayar bukan angka';
            elseif ($paid < 0) $err = 'terbayar minimal 0';
            elseif (mb_strlen($ref) > 200) $err = 'ref max 200 karakter';
            else {
                $due = $parseDate($dueRaw);
                if ($due === false) $err = 'jatuh_tempo tidak valid (pakai yyyy-mm-dd atau dd/mm/yyyy)';
            }
            if ($err) { $fail[] = "Baris $line: $err"; continue; }

            if ($paid > $total) $paid = $total;

            // Duplikat = tipe + pihak (case-insensitive) + total sama, scope toko aktif.
            $dupKey = $tipe . '|' . mb_strtolower($pihak, 'UTF-8') . '|' . number_format($total, 2, '.', '');
            if (isset($seenInFile[$dupKey])) {
                $skipped++;
                $fail[] = "Baris $line: duplikat ($tipe/$pihak/$total — sama dengan baris lain di file) — dilewati";
                continue;
            }
            $exists = Debt::where('store_id', $storeId)
                ->where('type', $tipe)
                ->whereRaw('LOWER(party_name) = ?', [mb_strtolower($pihak, 'UTF-8')])
                ->where('total', $total)
                ->exists();
            if ($exists) {
                $skipped++;
                $seenInFile[$dupKey] = true;
                $fail[] = "Baris $line: duplikat ($tipe/$pihak/$total — sudah ada di toko ini) — dilewati";
                continue;
            }

            try {
                Debt::create([
                    'store_id' => $storeId,
                    'type' => $tipe,
                    'party_name' => $pihak,
                    'ref_type' => $ref !== '' ? ('migrasi:' . mb_substr($ref, 0, 200)) : 'migrasi',
                    'ref_id' => null,
                    'total' => $total,
                    'paid' => $paid,
                    'remaining' => $total - $paid,
                    'due_date' => $due,
                    'status' => $this->autoStatus($total, $paid),
                ]);
                $seenInFile[$dupKey] = true;
                $ok++;
            } catch (\Throwable $e) {
                $fail[] = "Baris $line: " . $e->getMessage();
            }
        }
        fclose($handle);

        return redirect()->route('debts.index')
            ->with('success', "Import saldo awal selesai: $ok dicatat, $skipped duplikat dilewati, " . (count($fail) - $skipped) . ' gagal. Tanpa mutasi kas — terbayar > 0 hanya info historis.')
            ->with('import_fail', $fail);
    }
}
