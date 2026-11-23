<?php

namespace App\Http\Controllers;

use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\TechnicianCommission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TechnicianController extends Controller
{
    private function storeId(): ?int
    {
        return session('active_store_id') ?? auth()->user()->active_store_id ?? null;
    }

    public function index(Request $request)
    {
        $technicians = User::where('role', 'teknisi')
            ->when(
                $request->filled('q'),
                fn ($q) => $q->where(function ($w) use ($request) {
                    $w->where('name', 'like', '%' . $request->q . '%')
                        ->orWhere('email', 'like', '%' . $request->q . '%');
                })
            )
            ->orderBy('name')->get();
        $workload = DB::table('service_tickets')
            ->select('technician_id', DB::raw("COUNT(*) as aktif"))
            ->whereNotIn('status', ['diserahkan','batal'])
            ->groupBy('technician_id')->pluck('aktif', 'technician_id');
        $komisiMenunggu = TechnicianCommission::select('technician_id', DB::raw('SUM(amount) as total'))
            ->where('status', 'menunggu')->groupBy('technician_id')->pluck('total', 'technician_id');
        $komisiDibayar = TechnicianCommission::select('technician_id', DB::raw('SUM(amount) as total'))
            ->where('status', 'dibayar')->groupBy('technician_id')->pluck('total', 'technician_id');
        $commissions = TechnicianCommission::with(['ticket','technician'])->latest()->limit(50)->get();
        // Leaderboard produktivitas: tiket selesai (bulan ini + total) & rata-rata lama pengerjaan
        $doneMonth = DB::table('service_tickets')
            ->select('technician_id', DB::raw('COUNT(*) as jml'))
            ->where('status', 'diserahkan')
            ->where('technician_id', '!=', null)
            ->whereBetween('updated_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->groupBy('technician_id')->pluck('jml', 'technician_id');
        $doneTotal = DB::table('service_tickets')
            ->select('technician_id', DB::raw('COUNT(*) as jml'))
            ->where('status', 'diserahkan')
            ->where('technician_id', '!=', null)
            ->groupBy('technician_id')->pluck('jml', 'technician_id');
        $avgDays = [];
        $speed = [];
        foreach ($technicians as $t) {
            $rows = DB::table('service_tickets')->where('technician_id', $t->id)
                ->where('status', 'diserahkan')->whereNotNull('taken_at')
                ->select('created_at', 'taken_at')->limit(200)->get();
            if ($rows->count()) {
                $sum = 0;
                foreach ($rows as $r) {
                    $sum += max(0, strtotime($r->taken_at) - strtotime($r->created_at)) / 86400;
                }
                $avg = round($sum / $rows->count(), 1);
                $avgDays[$t->id] = $avg;
                // rating kecepatan: <=1 hari ★5, <=2 ★4, <=3 ★3, <=5 ★2, selebihnya ★1
                $speed[$t->id] = $avg <= 1 ? 5 : ($avg <= 2 ? 4 : ($avg <= 3 ? 3 : ($avg <= 5 ? 2 : 1)));
            }
        }
        // Target tiket selesai/bulan per teknisi (storage/app/tech_targets.json).
        $monthKey = now()->format('Y-m');
        $techTargets = $this->techTargetsFor($monthKey, $technicians);
        $canSetTarget = in_array(auth()->user()->role ?? '', ['superadmin', 'owner', 'admin']);
        $canPayCommission = in_array(auth()->user()->role ?? '', ['superadmin', 'owner', 'admin']);
        $cashAccounts = CashAccount::where('store_id', $this->storeId())->orderByDesc('is_default')->orderBy('name')->get();
        $totalMenunggu = (float) TechnicianCommission::where('status', 'menunggu')->sum('amount');
        // Peta bukti bayar komisi terakhir per teknisi dari marker [BUKTI:path]
        // pada kas keluar ref_type=technician_commission. Tanpa migration.
        $buktiKomisiByTech = [];
        $kasBukti = CashTransaction::where('ref_type', 'technician_commission')
            ->where('description', 'like', '%[BUKTI:%')
            ->orderByDesc('id')
            ->limit(500)
            ->get(['ref_id', 'description']);
        foreach ($kasBukti as $kb) {
            $tid = (int) $kb->ref_id;
            if (isset($buktiKomisiByTech[$tid])) continue;
            if (preg_match('/\[BUKTI:(.*?)\]/', (string) $kb->description, $mBukti)) {
                $buktiKomisiByTech[$tid] = trim($mBukti[1]);
            }
        }
        return view('technicians.index', compact('technicians','workload','komisiMenunggu','komisiDibayar','commissions','doneMonth','doneTotal','avgDays','speed','techTargets','monthKey','canSetTarget','canPayCommission','cashAccounts','totalMenunggu','buktiKomisiByTech'));
    }

    /** Target tiket selesai/bulan per teknisi — file storage/app/tech_targets.json key "tech:{user_id}:{YYYY-MM}". Tanpa tabel baru. */
    public static function readTechTargets(): array
    {
        try {
            if (!Storage::disk('local')->exists('tech_targets.json')) return [];
            $arr = json_decode((string) Storage::disk('local')->get('tech_targets.json'), true);
            return is_array($arr) ? $arr : [];
        } catch (\Throwable $e) { return []; }
    }

    public static function writeTechTargets(array $targets): void
    {
        Storage::disk('local')->put('tech_targets.json', json_encode($targets, JSON_PRETTY_PRINT));
    }

    private function techTargetsFor(string $monthKey, $technicians): array
    {
        $raw = self::readTechTargets();
        $out = [];
        foreach ($technicians as $t) {
            $out[$t->id] = (int) ($raw['tech:' . $t->id . ':' . $monthKey] ?? 0);
        }
        return $out;
    }

    /** Form set target tiket selesai/bulan per teknisi (owner/admin/superadmin). */
    public function setTarget(Request $request, $id)
    {
        abort_unless(in_array(auth()->user()->role ?? '', ['superadmin', 'owner', 'admin']), 403, 'Hanya owner/admin.');
        $tech = User::where('role', 'teknisi')->findOrFail($id);
        $data = $request->validate(['target' => 'required|integer|min:0|max:10000']);
        $targets = self::readTechTargets();
        $targets['tech:' . $tech->id . ':' . now()->format('Y-m')] = (int) $data['target'];
        self::writeTechTargets($targets);
        return back()->with('success', 'Target ' . $tech->name . ' bulan ini disimpan: ' . (int) $data['target'] . ' tiket.');
    }

    /** Laporan komisi teknisi per periode (filter bulan) + mode tahun (?tahun=YYYY agregat Jan-Des). */
    public function report(Request $request)
    {
        $tahunParam = (string) ($request->get('tahun', ''));
        $isYearly = (bool) preg_match('/^\d{4}$/', $tahunParam);
        // MODE TAHUN: agregat per bulan Jan–Des (tiket selesai + komisi menunggu/dibayar).
        if ($isYearly) {
            $tahun = $tahunParam;
            $start = $tahun . '-01-01';
            $end = $tahun . '-12-31';
            $bulan = $tahun . '-01';
            $storeId = $this->storeId();

            $doneRows = DB::table('service_tickets')
                ->select(
                    DB::raw('MONTH(DATE(COALESCE(taken_at, updated_at))) as bln'),
                    DB::raw('COUNT(*) as jml')
                )
                ->where('status', 'diserahkan')
                ->whereNotNull('technician_id')
                ->whereBetween(DB::raw('DATE(COALESCE(taken_at, updated_at))'), [$start, $end])
                ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
                ->groupBy(DB::raw('MONTH(DATE(COALESCE(taken_at, updated_at)))'))
                ->pluck('jml', 'bln');

            $comBase = TechnicianCommission::query()
                ->whereBetween(DB::raw('DATE(created_at)'), [$start, $end]);
            if ($storeId) {
                $comBase->whereHas('ticket', fn ($q) => $q->where('store_id', $storeId));
            }
            $comRows = (clone $comBase)
                ->select(
                    DB::raw('MONTH(DATE(created_at)) as bln'),
                    'status',
                    DB::raw('COUNT(*) as jml'),
                    DB::raw('SUM(amount) as total')
                )
                ->groupBy(DB::raw('MONTH(DATE(created_at))'), 'status')
                ->get();

            $perMonth = [];
            for ($m = 1; $m <= 12; $m++) {
                $perMonth[$m] = [
                    'selesai' => (int) ($doneRows[$m] ?? 0),
                    'tiket' => 0,
                    'menunggu' => 0.0,
                    'dibayar' => 0.0,
                ];
            }
            foreach ($comRows as $cr) {
                $b = (int) $cr->bln;
                if (! isset($perMonth[$b])) continue;
                $perMonth[$b]['tiket'] += (int) $cr->jml;
                if ($cr->status === 'dibayar') {
                    $perMonth[$b]['dibayar'] += (float) $cr->total;
                } else {
                    $perMonth[$b]['menunggu'] += (float) $cr->total;
                }
            }

            // Ringkas per teknisi setahun (untuk konteks) — tanpa query tambahan berat.
            $technicians = User::where('role', 'teknisi')->orderBy('name')->get();
            $donePerTech = DB::table('service_tickets')
                ->select('technician_id', DB::raw('COUNT(*) as jml'))
                ->where('status', 'diserahkan')
                ->whereNotNull('technician_id')
                ->whereBetween(DB::raw('DATE(COALESCE(taken_at, updated_at))'), [$start, $end])
                ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
                ->groupBy('technician_id')
                ->pluck('jml', 'technician_id');
            $rows = (clone $comBase)->with(['technician', 'ticket'])->orderByDesc('id')->limit(2000)->get();
            $perTech = [];
            $techTargets = $this->techTargetsFor($tahun . '-' . now()->format('m'), $technicians);
            foreach ($technicians as $t) {
                $mine = $rows->where('technician_id', $t->id);
                $perTech[$t->id] = [
                    'tech' => $t,
                    'selesai' => (int) ($donePerTech[$t->id] ?? 0),
                    'menunggu' => (float) $mine->where('status', 'menunggu')->sum('amount'),
                    'dibayar' => (float) $mine->where('status', 'dibayar')->sum('amount'),
                    'tiket' => $mine->count(),
                    'target' => (int) ($techTargets[$t->id] ?? 0),
                    'target_pct' => 0,
                ];
            }

            $buktiKomisiByTech = [];
            $kasBuktiQ = CashTransaction::where('ref_type', 'technician_commission')
                ->where('description', 'like', '%[BUKTI:%')
                ->whereBetween(DB::raw('DATE(created_at)'), [$start, $end]);
            if ($storeId) {
                $kasBuktiQ->where('store_id', $storeId);
            }
            foreach ($kasBuktiQ->orderByDesc('id')->limit(2000)->get(['ref_id', 'description']) as $kb) {
                $tid = (int) $kb->ref_id;
                if (isset($buktiKomisiByTech[$tid])) continue;
                if (preg_match('/\[BUKTI:(.*?)\]/', (string) $kb->description, $mBukti)) {
                    $buktiKomisiByTech[$tid] = trim($mBukti[1]);
                }
            }

            if ($request->get('export') === 'csv') {
                $filename = "komisi-teknisi-{$tahun}.csv";
                return response()->streamDownload(function () use ($perMonth, $tahun) {
                    $out = fopen('php://output', 'w');
                    fwrite($out, "\xEF\xBB\xBF");
                    fputcsv($out, ['tahun', 'bulan', 'tiket_selesai', 'jml_komisi', 'menunggu', 'dibayar', 'total'], ';');
                    for ($m = 1; $m <= 12; $m++) {
                        $r = $perMonth[$m];
                        fputcsv($out, [
                            $tahun,
                            sprintf('%04d-%02d', $tahun, $m),
                            $r['selesai'],
                            $r['tiket'],
                            $r['menunggu'],
                            $r['dibayar'],
                            $r['menunggu'] + $r['dibayar'],
                        ], ';');
                    }
                    fclose($out);
                }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
            }

            $mode = 'tahun';
            return view('technicians.report', compact(
                'bulan',
                'start',
                'end',
                'perTech',
                'rows',
                'buktiKomisiByTech',
                'mode',
                'tahun',
                'perMonth'
            ));
        }

        $bulan = $request->get('bulan', now()->format('Y-m'));
        [$y, $m] = array_pad(explode('-', $bulan), 2, [now()->year, now()->month]);
        $start = sprintf('%04d-%02d-01', $y, $m);
        $end = date('Y-m-t', strtotime($start));

        $technicians = User::where('role', 'teknisi')->orderBy('name')->get();

        $doneQuery = DB::table('service_tickets')
            ->where('status', 'diserahkan')
            ->whereNotNull('technician_id')
            ->whereBetween(DB::raw('DATE(COALESCE(taken_at, updated_at))'), [$start, $end]);
        if ($storeId = $this->storeId()) $doneQuery->where('store_id', $storeId);
        $donePerTech = (clone $doneQuery)->select('technician_id', DB::raw('COUNT(*) as jml'))->groupBy('technician_id')->pluck('jml', 'technician_id');

        $comQuery = TechnicianCommission::query()->whereBetween(DB::raw('DATE(created_at)'), [$start, $end]);
        if ($storeId ?? null) {
            $comQuery->whereHas('ticket', fn($q) => $q->where('store_id', $storeId));
        }
        $rows = (clone $comQuery)->with(['technician', 'ticket'])->orderByDesc('id')->limit(2000)->get();

        $perTech = [];
        $techTargets = $this->techTargetsFor($bulan, $technicians);
        foreach ($technicians as $t) {
            $mine = $rows->where('technician_id', $t->id);
            $target = (int) ($techTargets[$t->id] ?? 0);
            $selesai = (int) ($donePerTech[$t->id] ?? 0);
            $perTech[$t->id] = [
                'tech' => $t,
                'selesai' => $selesai,
                'menunggu' => (float) $mine->where('status', 'menunggu')->sum('amount'),
                'dibayar' => (float) $mine->where('status', 'dibayar')->sum('amount'),
                'tiket' => $mine->count(),
                'target' => $target,
                'target_pct' => $target > 0 ? min(100, round($selesai / $target * 100, 1)) : 0,
            ];
        }

        // Peta bukti bayar komisi per teknisi pada periode ini (marker [BUKTI:path]
        // pada kas keluar ref_type=technician_commission). Tanpa migration.
        $buktiKomisiByTech = [];
        $kasBuktiQ = CashTransaction::where('ref_type', 'technician_commission')
            ->where('description', 'like', '%[BUKTI:%')
            ->whereBetween(DB::raw('DATE(created_at)'), [$start, $end]);
        if ($storeId ?? null) {
            $kasBuktiQ->where('store_id', $storeId);
        }
        foreach ($kasBuktiQ->orderByDesc('id')->limit(2000)->get(['ref_id', 'description']) as $kb) {
            $tid = (int) $kb->ref_id;
            if (isset($buktiKomisiByTech[$tid])) continue;
            if (preg_match('/\[BUKTI:(.*?)\]/', (string) $kb->description, $mBukti)) {
                $buktiKomisiByTech[$tid] = trim($mBukti[1]);
            }
        }

        if ($request->get('export') === 'csv') {
            $filename = "komisi-teknisi-{$bulan}.csv";
            return response()->streamDownload(function () use ($perTech) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['id', 'teknisi', 'tiket_selesai', 'jml_komisi', 'menunggu', 'dibayar', 'total'], ';');
                foreach ($perTech as $id => $r) {
                    fputcsv($out, [$id, $r['tech']->name, $r['selesai'], $r['tiket'], $r['menunggu'], $r['dibayar'], $r['menunggu'] + $r['dibayar']], ';');
                }
                fclose($out);
            }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $mode = 'bulan';
        $tahun = substr((string) $bulan, 0, 4);
        $perMonth = [];
        return view('technicians.report', compact('bulan', 'start', 'end', 'perTech', 'rows', 'buktiKomisiByTech', 'mode', 'tahun', 'perMonth'));
    }

    /** Slip komisi per teknisi per periode (cetak): daftar komisi + total + tanda tangan. Tanpa migration. */
    public function slip(Request $request, $id)
    {
        $tech = User::where('role', 'teknisi')->findOrFail($id);
        $bulan = (string) ($request->get('bulan', now()->format('Y-m')));
        if (!preg_match('/^\d{4}-\d{2}$/', $bulan)) {
            $bulan = now()->format('Y-m');
        }
        [$y, $m] = array_pad(explode('-', $bulan), 2, [now()->year, now()->month]);
        $start = sprintf('%04d-%02d-01', (int) $y, (int) $m);
        $end = date('Y-m-t', strtotime($start));
        $storeId = $this->storeId();

        $q = TechnicianCommission::with(['ticket'])
            ->where('technician_id', $tech->id)
            ->whereBetween(DB::raw('DATE(created_at)'), [$start, $end])
            ->orderBy('id');
        if ($storeId) {
            $q->whereHas('ticket', fn ($t) => $t->where('store_id', $storeId));
        }
        $rows = $q->get();
        $menunggu = (float) $rows->where('status', 'menunggu')->sum('amount');
        $dibayar = (float) $rows->where('status', 'dibayar')->sum('amount');
        $total = $menunggu + $dibayar;
        $store = $storeId ? \App\Models\Store::find($storeId) : null;

        return view('technicians.slip', compact('tech', 'bulan', 'start', 'end', 'rows', 'menunggu', 'dibayar', 'total', 'store'));
    }

    /** Slip komisi MASSAL: gabungan N slip (maks 20) dalam 1 halaman cetak + page-break per slip. Tanpa migration. */
    public function bulkSlips(Request $request)
    {
        $bulan = (string) ($request->get('bulan', now()->format('Y-m')));
        if (!preg_match('/^\d{4}-\d{2}$/', $bulan)) {
            $bulan = now()->format('Y-m');
        }
        [$y, $m] = array_pad(explode('-', $bulan), 2, [now()->year, now()->month]);
        $start = sprintf('%04d-%02d-01', (int) $y, (int) $m);
        $end = date('Y-m-t', strtotime($start));
        $storeId = $this->storeId();

        $ids = array_values(array_unique(array_map('intval', (array) $request->query('ids', $request->input('ids', [])))));
        $ids = array_values(array_filter($ids, fn ($v) => $v > 0));
        if (empty($ids)) {
            return back()->with('error', 'Pilih minimal 1 teknisi untuk cetak slip massal.');
        }
        if (count($ids) > 20) {
            return back()->with('error', 'Maksimal 20 slip per cetak massal.');
        }

        $technicians = User::where('role', 'teknisi')
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get();
        if ($technicians->isEmpty()) {
            return back()->with('error', 'Tidak ada teknisi valid yang dipilih.');
        }

        $slips = [];
        foreach ($technicians as $tech) {
            $q = TechnicianCommission::with(['ticket'])
                ->where('technician_id', $tech->id)
                ->whereBetween(DB::raw('DATE(created_at)'), [$start, $end])
                ->orderBy('id');
            if ($storeId) {
                $q->whereHas('ticket', fn ($t) => $t->where('store_id', $storeId));
            }
            $rows = $q->get();
            $menunggu = (float) $rows->where('status', 'menunggu')->sum('amount');
            $dibayar = (float) $rows->where('status', 'dibayar')->sum('amount');
            $slips[] = [
                'tech' => $tech,
                'rows' => $rows,
                'menunggu' => $menunggu,
                'dibayar' => $dibayar,
                'total' => $menunggu + $dibayar,
            ];
        }
        $store = $storeId ? \App\Models\Store::find($storeId) : null;

        return view('technicians.bulk-slips', compact('slips', 'bulan', 'start', 'end', 'store'));
    }

    /**
     * UNDUH KONTAK vCard (.vcf) per teknisi: nama, HP, email.
     * GET /technicians/{id}/vcard — didaftarkan sebelum index (cermin suppliers.vcard),
     * tanpa migration. Teknisi = users.role teknisi; hanya kolom existing (name/phone/email).
     */
    public function vcard($id)
    {
        $tech = User::where('role', 'teknisi')->findOrFail($id);
        $tech->loadMissing('activeStore');
        $esc = function (?string $v): string {
            $v = (string) ($v ?? '');
            $v = str_replace('\\', '\\\\', $v);
            $v = str_replace(["\r\n", "\r", "\n"], '\\n', $v);
            $v = str_replace([',', ';'], ['\\,', '\\;'], $v);
            return trim($v);
        };
        $name = trim((string) $tech->name) !== '' ? (string) $tech->name : 'Teknisi #' . $tech->id;
        $phone = trim((string) ($tech->phone ?? ''));
        $email = trim((string) ($tech->email ?? ''));
        $lines = [
            'BEGIN:VCARD',
            'VERSION:3.0',
            'FN:' . $esc($name),
            'N:' . $esc($name) . ';;;;',
        ];
        if ($phone !== '') {
            $lines[] = 'TEL;TYPE=CELL,VOICE:' . $esc($phone);
        }
        if ($email !== '') {
            $lines[] = 'EMAIL;TYPE=WORK,INTERNET:' . $esc($email);
        }
        $lines[] = 'NOTE:' . $esc('Teknisi — rate ' . ($tech->commission_rate ?? 10) . '%' . ($tech->activeStore->name ?? '' ? ' — ' . $tech->activeStore->name : ''));
        $lines[] = 'END:VCARD';
        $vcf = implode("\r\n", $lines) . "\r\n";
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
        $slug = trim($slug, '-') !== '' ? trim($slug, '-') : ('teknisi-' . $tech->id);
        $filename = $slug . '-' . $tech->id . '.vcf';

        return response($vcf, 200, [
            'Content-Type' => 'text/vcard; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    public function payCommission(Request $request, $id)
    {
        abort_unless(
            in_array(auth()->user()->role ?? '', ['superadmin', 'owner', 'admin']),
            403,
            'Hanya owner/admin yang boleh membayar komisi teknisi (teknisi dilarang membayar komisi sendiri).'
        );
        $request->validate(['commission_ids' => 'nullable|array', 'commission_ids.*' => 'exists:technician_commissions,id', 'bukti' => 'nullable|image|max:2048']);
        $tech = User::where('role', 'teknisi')->findOrFail($id);
        $storeId = $this->storeId();

        $ids = $request->commission_ids;
        $query = TechnicianCommission::where('technician_id', $tech->id)->where('status', 'menunggu');
        if ($ids) $query->whereIn('id', $ids);
        $rows = $query->get();
        if ($rows->isEmpty()) return back()->with('error', 'Tidak ada komisi menunggu untuk dibayar.');

        DB::beginTransaction();
        try {
            $total = (float) $rows->sum('amount');
            $account = CashAccount::where('store_id', $storeId)->where('is_default', true)->lockForUpdate()->first()
                ?? CashAccount::where('store_id', $storeId)->lockForUpdate()->first();
            if (!$account) {
                $account = CashAccount::create(['store_id' => $storeId, 'name' => 'Kas Utama', 'balance' => 0, 'is_default' => true]);
            }
            if ((float) $account->balance < $total) {
                DB::rollBack();
                return back()->with('error', 'Saldo akun kas tidak mencukupi untuk membayar komisi Rp ' . number_format($total, 0, ',', '.') . '.');
            }
            // GATE BUKTI (Continuous 170): samakan CashController@store — kas
            // keluar komisi > Rp10jt wajib foto bukti (role sudah ketat:
            // hanya owner/admin/superadmin untuk nominal berapa pun).
            if ($total > 10000000 && ! $request->hasFile('bukti')) {
                DB::rollBack();
                return back()->with('error', 'Komisi di atas Rp10.000.000 wajib melampirkan foto bukti (maks 2 MB).');
            }
            // Bukti bayar opsional: foto disimpan di storage + path dicatat [BUKTI:path]
            // di description (pola CashController@store), tanpa migration.
            $desc = 'Komisi teknisi ' . $tech->name . ' (' . $rows->count() . ' tiket)';
            if ($request->hasFile('bukti')) {
                $buktiPath = $request->file('bukti')->store('bukti-komisi', 'public');
                $desc .= ' [BUKTI:' . $buktiPath . ']';
            }
            CashTransaction::create([
                'store_id' => $storeId,
                'cash_account_id' => $account->id,
                'type' => 'keluar',
                'category' => 'operasional',
                'amount' => $total,
                'ref_type' => 'technician_commission',
                'ref_id' => $tech->id,
                'description' => $desc,
                'user_id' => auth()->id(),
            ]);
            $account->balance = (float) $account->balance - $total;
            $account->save();
            TechnicianCommission::whereIn('id', $rows->pluck('id'))->update(['status' => 'dibayar', 'updated_at' => now()]);
            DB::commit();
            return back()->with('success', 'Komisi ' . $tech->name . ' Rp ' . number_format($total, 0, ',', '.') . ' dibayar.');
        } catch (\Throwable $e) {
            DB::rollBack();
            return back()->with('error', 'Gagal bayar komisi: ' . $e->getMessage());
        }
    }

    /**
     * Bayar SEMUA komisi menunggu sekaligus.
     * Konsisten dengan payCommission: 1 baris kas keluar (CashTransaction)
     * per teknisi, semuanya dalam 1 transaksi DB. Satu akun kas dipakai
     * untuk semua teknisi (dipilih di form, fallback akun default toko).
     * Tanpa migration.
     */
    public function payAllCommissions(Request $request)
    {
        abort_unless(
            in_array(auth()->user()->role ?? '', ['superadmin', 'owner', 'admin']),
            403,
            'Hanya owner/admin yang boleh membayar komisi teknisi (teknisi dilarang membayar komisi sendiri).'
        );
        $request->validate([
            'cash_account_id' => 'nullable|exists:cash_accounts,id',
            'bukti' => 'nullable|image|max:2048',
        ]);
        $storeId = $this->storeId();

        $pendingIds = TechnicianCommission::where('status', 'menunggu')->pluck('id');
        if ($pendingIds->isEmpty()) {
            return back()->with('error', 'Tidak ada komisi menunggu untuk dibayar.');
        }

        $account = null;
        if ($request->filled('cash_account_id')) {
            $account = CashAccount::where('id', $request->cash_account_id)
                ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
                ->first();
        }
        if (!$account) {
            $account = CashAccount::where('store_id', $storeId)->where('is_default', true)->first()
                ?? CashAccount::where('store_id', $storeId)->first();
        }
        if (!$account) {
            $account = CashAccount::create(['store_id' => $storeId, 'name' => 'Kas Utama', 'balance' => 0, 'is_default' => true]);
        }

        $grouped = TechnicianCommission::where('status', 'menunggu')
            ->select('technician_id', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as jml'))
            ->groupBy('technician_id')
            ->with('technician')
            ->get();

        DB::beginTransaction();
        try {
            $need = (float) $grouped->sum(fn ($g) => (float) $g->total > 0 ? (float) $g->total : 0);
            if ($need <= 0) {
                DB::rollBack();
                return back()->with('error', 'Tidak ada komisi menunggu untuk dibayar.');
            }
            // Kunci akun di dalam transaksi + guard saldo (pola CashController@store).
            $account = CashAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            if ((float) $account->balance < $need) {
                DB::rollBack();
                return back()->with('error', 'Saldo akun kas tidak mencukupi untuk membayar semua komisi (butuh Rp ' . number_format($need, 0, ',', '.') . ').');
            }
            // GATE BUKTI (Continuous 170): samakan CashController@store — total
            // kas keluar komisi > Rp10jt wajib foto bukti (role sudah ketat:
            // hanya owner/admin/superadmin untuk nominal berapa pun).
            if ($need > 10000000 && ! $request->hasFile('bukti')) {
                DB::rollBack();
                return back()->with('error', 'Total komisi di atas Rp10.000.000 wajib melampirkan foto bukti (maks 2 MB).');
            }
            $grandTotal = 0.0;
            $techCount = 0;
            // Satu foto bukti massal dipakai untuk semua baris kas keluar batch ini.
            $buktiMassal = null;
            if ($request->hasFile('bukti')) {
                $buktiMassal = $request->file('bukti')->store('bukti-komisi', 'public');
            }
            foreach ($grouped as $g) {
                $tech = $g->technician ?? User::find($g->technician_id);
                if (!$tech) continue;
                $total = (float) $g->total;
                if ($total <= 0) continue;
                $descAll = 'Komisi teknisi ' . $tech->name . ' (' . (int) $g->jml . ' tiket) — bayar semua';
                if ($buktiMassal) {
                    $descAll .= ' [BUKTI:' . $buktiMassal . ']';
                }
                CashTransaction::create([
                    'store_id' => $storeId,
                    'cash_account_id' => $account->id,
                    'type' => 'keluar',
                    'category' => 'operasional',
                    'amount' => $total,
                    'ref_type' => 'technician_commission',
                    'ref_id' => $tech->id,
                    'description' => $descAll,
                    'user_id' => auth()->id(),
                ]);
                $grandTotal += $total;
                $techCount++;
            }
            if ($techCount === 0 || $grandTotal <= 0) {
                DB::rollBack();
                return back()->with('error', 'Tidak ada komisi menunggu untuk dibayar.');
            }
            $account->balance = (float) $account->balance - $grandTotal;
            $account->save();
            TechnicianCommission::whereIn('id', $pendingIds)->update(['status' => 'dibayar', 'updated_at' => now()]);
            DB::commit();
            return back()->with('success', 'Semua komisi dibayar: ' . $techCount . ' teknisi, Rp ' . number_format($grandTotal, 0, ',', '.') . ' via ' . $account->name . '.');
        } catch (\Throwable $e) {
            DB::rollBack();
            return back()->with('error', 'Gagal bayar semua komisi: ' . $e->getMessage());
        }
    }
}
