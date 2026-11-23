<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\ServiceTicket;
use App\Models\Store;
use App\Services\WaService;
use App\Support\LookupCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $storeId = session('active_store_id');
        $q = trim((string) $request->q);
        // Varian normalisasi query HP: 08… <-> 628… agar pencarian sebagian tetap ketemu
        $qAlt = null;
        if ($q !== '') {
            $digits = preg_replace('/[^0-9]/', '', $q);
            if ($digits !== '') {
                if (str_starts_with($digits, '0')) $qAlt = '62' . substr($digits, 1);
                elseif (str_starts_with($digits, '62')) $qAlt = '0' . substr($digits, 2);
            }
        }
        $applySearch = function ($query) use ($q, $qAlt) {
            if ($q === '') return;
            $query->where(function ($w) use ($q, $qAlt) {
                $w->where('name', 'like', '%' . $q . '%')->orWhere('phone', 'like', '%' . $q . '%');
                // CARI MEMBER by KODE: exact + partial (kompatibel nama/HP existing)
                $w->orWhere('member_code', $q)->orWhere('member_code', 'like', '%' . $q . '%');
                // pencarian by phone sebagian: coba juga varian normalisasi + versi digit-saja
                $digits = preg_replace('/[^0-9]/', '', $q);
                if ($digits !== '' && $digits !== $q) $w->orWhere('phone', 'like', '%' . $digits . '%');
                if ($qAlt) $w->orWhere('phone', 'like', '%' . $qAlt . '%');
            });
        };
        $customers = Customer::with('store')
            ->withCount(['sales', 'serviceTickets', 'tradeIns', 'warranties'])
            ->when($storeId, fn ($q2) => $q2->where('store_id', $storeId))
            ->when($request->filled('q'), fn ($query) => $applySearch($query))
            // Filter tier member berdasar total_belanja (tanpa migration)
            ->when($request->tier === 'Bronze', fn ($q) => $q->where('total_belanja', '<', 1000000))
            ->when($request->tier === 'Silver', fn ($q) => $q->where('total_belanja', '>=', 1000000)->where('total_belanja', '<=', 5000000))
            ->when($request->tier === 'Gold', fn ($q) => $q->where('total_belanja', '>', 5000000))
            ->orderByDesc('id')->paginate(20)->withQueryString();
        // Export CSV (?export=csv) — hormati filter aktif + scope toko
        if ($request->get('export') === 'csv') {
            $rows = Customer::with('store')
                ->when($storeId, fn ($q2) => $q2->where('store_id', $storeId))
                ->when($request->filled('q'), fn ($query) => $applySearch($query))
                ->when($request->tier === 'Bronze', fn ($q) => $q->where('total_belanja', '<', 1000000))
                ->when($request->tier === 'Silver', fn ($q) => $q->where('total_belanja', '>=', 1000000)->where('total_belanja', '<=', 5000000))
                ->when($request->tier === 'Gold', fn ($q) => $q->where('total_belanja', '>', 5000000))
                ->orderByDesc('id')->limit(5000)->get();
            $filename = 'pelanggan-' . now()->format('Ymd-His') . '.csv';
            return response()->streamDownload(function () use ($rows) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['id', 'nama', 'hp', 'alamat', 'member', 'toko', 'tier', 'total_belanja'], ';');
                foreach ($rows as $c) {
                    fputcsv($out, [$c->id, $c->name, $c->phone ?? '', $c->address ?? '', $c->member_code ?? '', $c->store->name ?? '', CustomerController::tierOf((float) $c->total_belanja), (float) $c->total_belanja], ';');
                }
                fclose($out);
            }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }
        // Section "Kemungkinan Duplikat" — hanya saat tidak sedang mencari (agar ringan)
        $duplicateGroups = $request->filled('q') ? [] : self::findDuplicateGroups($storeId ? (int) $storeId : null);
        // Kartu "Top 10 Pelanggan" by total_belanja, scope toko aktif (tanpa filter q/tier)
        $topCustomers = Customer::with('store')
            ->when($storeId, fn ($q2) => $q2->where('store_id', $storeId))
            ->orderByDesc('total_belanja')->orderByDesc('id')->limit(10)->get();
        // Kartu "Pelanggan Berulang (30 hari)": % pelanggan dengan ≥2 invoice
        // dalam 30 hari terakhir, scope toko aktif. Tanpa migration/route baru.
        $repeatSince = now()->subDays(30);
        try {
            $invoiceCounts = Sale::query()
                ->when($storeId, fn ($q2) => $q2->where('store_id', $storeId))
                ->where('created_at', '>=', $repeatSince)
                ->whereNotNull('customer_id')
                ->select('customer_id', DB::raw('COUNT(*) as invoice_count'))
                ->groupBy('customer_id')
                ->pluck('invoice_count');
        } catch (\Throwable $e) {
            report($e);
            $invoiceCounts = collect();
        }
        $repeatActiveCustomers = (int) $invoiceCounts->count();
        $repeatCustomers = (int) $invoiceCounts->filter(fn ($c) => (int) $c >= 2)->count();
        $repeatRate = $repeatActiveCustomers > 0
            ? round($repeatCustomers / $repeatActiveCustomers * 100, 1)
            : 0.0;
        // Kartu "Pertumbuhan Master (12 bulan)": pelanggan baru per bulan
        // (scope toko aktif) + produk baru per bulan. Catat: tabel products
        // TIDAK punya store_id (master GLOBAL) sehingga produk tanpa scope
        // toko. Tanpa migration/route baru. Portable: hitung per bulan
        // dengan whereBetween agar lolos MySQL maupun SQLite.
        $growthLabels = [];
        $growthCustomers = [];
        $growthProducts = [];
        try {
            $monthStarts = [];
            for ($i = 11; $i >= 0; $i--) {
                $m = now()->copy()->subMonths($i);
                $monthStarts[] = $m->copy()->startOfMonth();
                $growthLabels[] = $m->format('M Y');
            }
            foreach ($monthStarts as $start) {
                $end = $start->copy()->endOfMonth();
                $growthCustomers[] = (int) Customer::query()
                    ->when($storeId, fn ($q2) => $q2->where('store_id', $storeId))
                    ->whereBetween('created_at', [$start, $end])
                    ->count();
                $growthProducts[] = (int) Product::query()
                    ->whereBetween('created_at', [$start, $end])
                    ->count();
            }
        } catch (\Throwable $e) {
            report($e);
            $growthLabels = [];
            $growthCustomers = [];
            $growthProducts = [];
        }
        // Dropdown tujuan "Pindah Toko Terpilih" (tanpa migration).
        $stores = LookupCache::activeStores();
        // Cermin kosong: tandai pelanggan tanpa transaksi di halaman ini yang
        // juga punya riwayat beli-bekas (secondhand) agar tombol hapus cepat
        // tidak ditampilkan (guard @destroy menolak dengan alasan 422).
        $secondhandIds = [];
        try {
            if (Schema::hasTable('secondhand_purchases')) {
                $secondhandIds = DB::table('secondhand_purchases')
                    ->whereIn('customer_id', $customers->pluck('id')->all())
                    ->pluck('customer_id')
                    ->map(fn ($v) => (int) $v)
                    ->all();
            }
        } catch (\Throwable $e) {
            report($e);
            $secondhandIds = [];
        }
        return view('customers.index', compact('customers', 'duplicateGroups', 'topCustomers', 'repeatCustomers', 'repeatActiveCustomers', 'repeatRate', 'growthLabels', 'growthCustomers', 'growthProducts', 'stores', 'secondhandIds'));
    }

    /** Normalisasi HP 08… → 628… (digit saja). Null/kosong tetap null. */
    public static function normalizePhone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') return null;
        return WaService::normalize($phone);
    }

    /** Kunci nama mirip: lowercase tanpa spasi & non-alfanumerik. */
    public static function nameKey(string $name): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(trim($name)));
    }

    /**
     * Kelompok kemungkinan duplikat (max 20 grup): by phone ternormalisasi
     * (08… vs 628…) atau nama mirip, count > 1. Dihitung di PHP karena
     * normalisasi tidak tersimpan mentah di DB.
     */
    public static function findDuplicateGroups(?int $storeId, int $maxGroups = 20, int $scanLimit = 2000): array
    {
        $rows = Customer::query()
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->orderByDesc('id')->limit($scanLimit)->get(['id', 'name', 'phone', 'store_id', 'total_belanja']);
        $byPhone = [];
        $byName = [];
        foreach ($rows as $c) {
            $np = self::normalizePhone($c->phone);
            if ($np) $byPhone[$np][] = $c;
            $nk = self::nameKey((string) $c->name);
            if ($nk !== '') $byName[$nk][] = $c;
        }
        $groups = [];
        $seen = [];
        $push = function (string $kind, string $key, \Illuminate\Support\Collection $members) use (&$groups, &$seen, $maxGroups) {
            if (count($members) < 2 || count($groups) >= $maxGroups) return;
            $ids = $members->map(fn ($m) => $m->id)->sort()->values()->implode('-');
            if (isset($seen[$ids])) return;
            $seen[$ids] = true;
            $groups[] = ['kind' => $kind, 'key' => $key, 'members' => $members->sortByDesc('id')->values()];
        };
        foreach ($byPhone as $key => $list) $push('HP sama', (string) $key, collect($list));
        if (count($groups) < $maxGroups) {
            foreach ($byName as $key => $list) $push('Nama mirip', (string) $key, collect($list));
        }
        return array_slice($groups, 0, $maxGroups);
    }

    /** Tier member dari total belanja: Bronze <1jt, Silver 1-5jt, Gold >5jt. */
    public static function tierOf(float $total): string
    {
        if ($total > 5000000) return 'Gold';
        if ($total >= 1000000) return 'Silver';
        return 'Bronze';
    }

    public function create()
    {
        $stores = LookupCache::activeStores();
        return view('customers.create', compact('stores'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'store_id' => 'nullable|exists:stores,id',
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'member_code' => 'nullable|string|max:50|unique:customers,member_code',
        ]);
        $data['store_id'] = $data['store_id'] ?? session('active_store_id');
        if (array_key_exists('phone', $data)) $data['phone'] = self::normalizePhone($data['phone']);
        $customer = Customer::create($data);
        // Dari POS (?redirect=pos / hidden redirect=pos): kembali ke POS dengan pelanggan baru terpilih.
        if ($request->input('redirect') === 'pos') {
            return redirect()->route('pos.index', ['customer_id' => $customer->id])
                ->with('success', 'Pelanggan baru "' . $customer->name . '" ditambahkan & dipilih di POS.');
        }
        // Dari form Terima HP (?redirect=service / hidden redirect=service):
        // kembali ke services.create dengan pelanggan baru ter-preselect.
        if ($request->input('redirect') === 'service') {
            return redirect()->route('services.create', ['customer_id' => $customer->id])
                ->with('success', 'Pelanggan baru "' . $customer->name . '" ditambahkan & dipilih di form Terima HP.');
        }
        return redirect()->route('customers.show', $customer)->with('success', 'Pelanggan ditambahkan.');
    }

    /** CRM: riwayat belanja (sales + service) + update total_belanja + rekap rentang fleksibel. */
    public function show(Request $request, Customer $customer)
    {
        $customer->load('store');
        $sales = $customer->sales()->with('items')->orderByDesc('id')->limit(30)->get();
        $services = $customer->serviceTickets()->orderByDesc('id')->limit(30)->get();
        $totalBelanja = (float) $customer->sales()->sum('total');
        if (abs($totalBelanja - (float) $customer->total_belanja) > 0.01) {
            $customer->update(['total_belanja' => $totalBelanja]);
        }
        // Rekap fleksibel (?from&to Y-m-d, default bulan berjalan): semua invoice rentang inklusif.
        [$from, $to, $fromStr, $toStr] = self::statementRange($request);
        $monthlySales = $customer->sales()
            ->whereDate('created_at', '>=', $fromStr)
            ->whereDate('created_at', '<=', $toStr)
            ->orderBy('id')
            ->get();
        $monthTotal = (float) $monthlySales->sum('total');
        $monthPaid = (float) $monthlySales->sum('paid_amount');
        $monthRemain = (float) $monthlySales->sum('remaining');
        $monthLabel = $from->translatedFormat('d M Y') . ' – ' . $to->translatedFormat('d M Y');
        // Ringkasan RFM (tanpa migration/route baru): agregat SEMUA sales,
        // bukan hanya $sales limit 30 yang dipakai tabel riwayat.
        $rfmCount = (int) $customer->sales()->count();
        $rfmLastAt = $customer->sales()->max('created_at');
        $rfmLastDate = $rfmLastAt ? \Carbon\Carbon::parse($rfmLastAt) : null;
        $rfmDaysAgo = $rfmLastDate ? (int) $rfmLastDate->diffInDays(now()) : null;
        $rfmAvg = $rfmCount > 0 ? $totalBelanja / $rfmCount : 0.0;
        $rfmIsDormant = $rfmCount === 0 || ($rfmDaysAgo !== null && $rfmDaysAgo > 90);
        return view('customers.show', compact(
            'customer', 'sales', 'services', 'totalBelanja',
            'monthlySales', 'monthTotal', 'monthPaid', 'monthRemain', 'monthLabel',
            'fromStr', 'toStr',
            'rfmCount', 'rfmLastDate', 'rfmDaysAgo', 'rfmAvg', 'rfmIsDormant'
        ));
    }

    /**
     * KARTU MEMBER CETAK (cermin warranties/card): standalone HTML siap print.
     * Idempoten: bila member_code kosong, generate MBR-XXXX unik lalu simpan
     * (cek exists dalam loop; tanpa migration).
     */
    public function memberCard(Customer $customer)
    {
        $customer->load('store');
        if (!$customer->member_code) {
            do {
                $code = 'MBR-' . str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
            } while (Customer::where('member_code', $code)->exists());
            $customer->member_code = $code;
            $customer->save();
            $customer->refresh();
        }
        $totalBelanja = (float) $customer->sales()->sum('total');
        if (abs($totalBelanja - (float) $customer->total_belanja) > 0.01) {
            $customer->update(['total_belanja' => $totalBelanja]);
        }
        $tier = self::tierOf($totalBelanja);
        $salesCount = (int) $customer->sales()->count();
        $serviceCount = (int) $customer->serviceTickets()->count();
        $memberSince = $customer->created_at;

        return view('customers.member_card', compact(
            'customer', 'totalBelanja', 'tier', 'salesCount', 'serviceCount', 'memberSince'
        ));
    }

    /**
     * KARTU MEMBER MASSAL: 1 halaman gabungan berisi N kartu (maks 50),
     * masing-masing page-break agar rapi saat print. Cermin warranties bulkCards.
     * GET /customers/member-cards?ids[]=1&ids[]=2 — tanpa migration.
     * Route plural didaftarkan SEBELUM resource agar tak tertabrak customers.show.
     */
    public function bulkMemberCards(Request $request)
    {
        $ids = array_values(array_unique(array_map('intval', (array) $request->query('ids', $request->input('ids', [])))));
        $ids = array_values(array_filter($ids, fn ($v) => $v > 0));
        if (empty($ids)) {
            return back()->with('error', 'Pilih minimal 1 pelanggan untuk dicetak (centang baris tabel).');
        }
        if (count($ids) > 50) {
            return back()->with('error', 'Maksimal 50 kartu member per cetak massal.');
        }

        $storeId = session('active_store_id');
        $customers = Customer::with('store')
            ->whereIn('id', $ids)
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->orderBy('id')
            ->get();
        if ($customers->isEmpty()) {
            return back()->with('error', 'Tidak ada pelanggan valid yang dipilih (mungkin beda toko).');
        }

        // Susun paket per kartu: customer + tier + total belanja + counter.
        // Idempoten: generate member_code bila kosong (cek exists; tanpa migration).
        $cards = [];
        foreach ($customers as $customer) {
            if (!$customer->member_code) {
                do {
                    $code = 'MBR-' . str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
                } while (Customer::where('member_code', $code)->exists());
                $customer->member_code = $code;
                $customer->save();
                $customer->refresh();
            }
            $totalBelanja = (float) $customer->sales()->sum('total');
            if (abs($totalBelanja - (float) $customer->total_belanja) > 0.01) {
                $customer->update(['total_belanja' => $totalBelanja]);
            }
            $cards[] = [
                'customer' => $customer,
                'tier' => self::tierOf($totalBelanja),
                'totalBelanja' => $totalBelanja,
                'salesCount' => (int) $customer->sales()->count(),
                'serviceCount' => (int) $customer->serviceTickets()->count(),
                'memberSince' => $customer->created_at,
            ];
        }

        return view('customers.member_cards', compact('cards'));
    }

    /**
     * UNDUH KONTAK vCard (.vcf) per pelanggan: nama, HP, alamat, toko.
     * GET /customers/{customer}/vcard — didaftarkan SEBELUM resource
     * (konsisten dengan member-card), tanpa migration.
     * Escape vCard: \ ; , dan newline → \n; baris dilipat per 75 oktet.
     */
    public function vcard(Customer $customer)
    {
        $customer->load('store');
        $name = trim((string) $customer->name) !== '' ? (string) $customer->name : 'Pelanggan';
        $phone = trim((string) ($customer->phone ?? ''));
        $address = trim((string) ($customer->address ?? ''));
        $store = trim((string) ($customer->store->name ?? ''));
        $member = trim((string) ($customer->member_code ?? ''));

        $lines = [
            'BEGIN:VCARD',
            'VERSION:3.0',
            'FN:' . self::vcardEscape($name),
            'N:' . self::vcardEscape($name) . ';;;;',
        ];
        if ($phone !== '') {
            $lines[] = 'TEL;TYPE=CELL,VOICE:' . self::vcardEscape($phone);
        }
        if ($address !== '') {
            $lines[] = 'ADR;TYPE=HOME:;;' . self::vcardEscape($address) . ';;;;';
        }
        if ($store !== '') {
            $lines[] = 'ORG:' . self::vcardEscape($store);
        }
        if ($member !== '') {
            $lines[] = 'NOTE:' . self::vcardEscape('Member: ' . $member);
        }
        $lines[] = 'END:VCARD';

        // Lipat baris >75 oktet sesuai RFC 2426 (folding dengan spasi).
        $folded = [];
        foreach ($lines as $line) {
            $folded[] = self::vcardFold($line);
        }
        $vcf = implode("\r\n", $folded) . "\r\n";

        $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)));
        $slug = trim($slug, '-') !== '' ? trim($slug, '-') : 'pelanggan';
        $filename = 'kontak-' . $customer->id . '-' . substr($slug, 0, 40) . '.vcf';

        return response($vcf, 200, [
            'Content-Type' => 'text/vcard; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /** Escape nilai properti vCard 3.0: \ → \\, newline → \n, ; → \;, , → \,. */
    protected static function vcardEscape(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace(["\r\n", "\r", "\n"], '\\n', $value);
        $value = str_replace([';', ','], ['\\;', '\\,'], $value);

        return $value;
    }

    /** Lipat baris vCard per 75 oktet (lanjutkan dengan CRLF + spasi). */
    protected static function vcardFold(string $line): string
    {
        $out = '';
        $len = strlen($line);
        $pos = 0;
        $first = true;
        while ($pos < $len) {
            $chunkLen = $first ? 75 : 74;
            $out .= ($first ? '' : "\r\n ") . substr($line, $pos, $chunkLen);
            $pos += $chunkLen;
            $first = false;
        }

        return $out;
    }

    /**
     * Rentang rekap fleksibel (?from&to Y-m-d, default bulan berjalan s/d hari ini).
     * Mengembalikan [Carbon $from, Carbon $to, string $fromStr, string $toStr].
     * Invalid/parsial → fallback default; from > to → tukar.
     */
    protected static function statementRange(Request $request): array
    {
        $defaultFrom = now()->startOfMonth()->startOfDay();
        $defaultTo = now()->endOfDay();
        try {
            $from = $request->filled('from')
                ? \Carbon\Carbon::parse($request->input('from'))->startOfDay()
                : $defaultFrom->copy();
        } catch (\Throwable $e) {
            $from = $defaultFrom->copy();
        }
        try {
            $to = $request->filled('to')
                ? \Carbon\Carbon::parse($request->input('to'))->endOfDay()
                : $defaultTo->copy();
        } catch (\Throwable $e) {
            $to = $defaultTo->copy();
        }
        if ($from->gt($to)) [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        return [$from->copy()->startOfDay(), $to->copy()->endOfDay(), $from->format('Y-m-d'), $to->format('Y-m-d')];
    }

    /**
     * Kirim rekap tagihan rentang fleksibel via WA (POST, ?from&to atau hidden from/to).
     * Format ringkas: sapaan + daftar invoice + total tagihan.
     */
    public function statementWa(Request $request, Customer $customer)
    {
        $activeStoreId = session('active_store_id');
        if ($activeStoreId && $customer->store_id && (int) $customer->store_id !== (int) $activeStoreId) {
            abort(403, 'Bukan data pelanggan toko aktif.');
        }
        [$from, $to, $fromStr, $toStr] = self::statementRange($request);
        $monthlySales = $customer->sales()
            ->whereDate('created_at', '>=', $fromStr)
            ->whereDate('created_at', '<=', $toStr)
            ->orderBy('id')
            ->get();
        if ($monthlySales->isEmpty()) {
            return back()->with('error', 'Tidak ada invoice pada rentang ' . $fromStr . ' s/d ' . $toStr . '.');
        }
        $phone = self::validBroadcastPhone($customer->phone);
        if (!$phone) {
            return back()->with('error', 'Nomor HP pelanggan kosong/tidak valid, rekap tidak terkirim.');
        }
        $monthLabel = $from->translatedFormat('d M Y') . ' – ' . $to->translatedFormat('d M Y');
        $storeName = $customer->store->name ?? config('app.name', 'ServiceHP');
        $lines = [
            "Halo {$customer->name}, rekap tagihan {$monthLabel} dari {$storeName}:",
        ];
        foreach ($monthlySales as $i => $s) {
            $sisa = (float) $s->remaining;
            $tag = $sisa > 0
                ? 'sisa Rp ' . number_format($sisa, 0, ',', '.')
                : 'lunas';
            $lines[] = ($i + 1) . '. ' . $s->invoice_no
                . ' (' . $s->payment_status . ') Rp '
                . number_format((float) $s->total, 0, ',', '.')
                . ' — ' . $tag;
        }
        $lines[] = 'Total tagihan: Rp ' . number_format((float) $monthlySales->sum('total'), 0, ',', '.');
        $lines[] = 'Sudah dibayar: Rp ' . number_format((float) $monthlySales->sum('paid_amount'), 0, ',', '.');
        $lines[] = 'Sisa: Rp ' . number_format((float) $monthlySales->sum('remaining'), 0, ',', '.');
        // Ringkasan jadwal cicilan aktif dalam rentang (maks 5 baris + total):
        // hanya invoice cicilan bertenor dengan sisa > 0. Jatuh tempo berikutnya
        // dihitung seperti SaleController@show (bayar kumulatif vs kumulatif jadwal).
        $cicilanAktif = $monthlySales->filter(fn ($s) => $s->payment_method === 'cicilan'
            && (int) ($s->installment_tenor ?? 0) > 0
            && (float) $s->remaining > 0)->values();
        if ($cicilanAktif->isNotEmpty()) {
            $lines[] = 'Jadwal cicilan (' . $cicilanAktif->count() . ' inv):';
            $totalSisaCicilan = 0;
            foreach ($cicilanAktif->take(5) as $s) {
                $tenor = max(1, (int) $s->installment_tenor);
                $grand = (float) $s->total;
                $base = (int) round($grand / $tenor);
                $paid = (float) $s->paid_amount;
                $running = 0;
                $nextDue = null;
                $nextN = 0;
                for ($i = 1; $i <= $tenor; $i++) {
                    $nominal = $i < $tenor
                        ? $base
                        : max(0, (int) round($grand - $base * ($tenor - 1)));
                    $running += $nominal;
                    $due = (clone $s->created_at)->addMonthsNoOverflow($i);
                    $st = $paid >= $running ? 'lunas' : 'belum';
                    if ($nextDue === null && $st !== 'lunas') {
                        $nextDue = $due;
                        $nextN = $i;
                    }
                }
                $totalSisaCicilan += (float) $s->remaining;
                $jt = $nextDue
                    ? $nextDue->format('d/m/Y') . ' ke-' . $nextN . ' (BELUM)'
                    : 'lunas';
                $lines[] = '- ' . $s->invoice_no
                    . ' (' . $tenor . 'x @Rp ' . number_format($base, 0, ',', '.') . ')'
                    . ' sisa Rp ' . number_format((float) $s->remaining, 0, ',', '.')
                    . ', JT ' . $jt;
            }
            if ($cicilanAktif->count() > 5) {
                $lines[] = '... +' . ($cicilanAktif->count() - 5) . ' jadwal lain.';
            }
            $lines[] = 'Total sisa cicilan: Rp ' . number_format($totalSisaCicilan, 0, ',', '.');
        }
        $lines[] = 'Terima kasih.';
        try {
            $ok = WaService::send($phone, implode("\n", $lines), 'statement', $customer->store_id);
        } catch (\Throwable $e) {
            report($e);
            $ok = false;
        }
        return $ok
            ? back()->with('success', 'Rekap ' . $monthLabel . ' terkirim via WA ke ' . $phone . '.')
            : back()->with('error', 'Gagal mengirim rekap via WA (cek log WA).');
    }

    public function edit(Customer $customer)
    {
        $stores = LookupCache::activeStores();
        return view('customers.edit', compact('customer', 'stores'));
    }

    public function update(Request $request, Customer $customer)
    {
        $data = $request->validate([
            'store_id' => 'nullable|exists:stores,id',
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'member_code' => 'nullable|string|max:50|unique:customers,member_code,' . $customer->id,
        ]);
        if (array_key_exists('phone', $data)) $data['phone'] = self::normalizePhone($data['phone']);
        $customer->update($data);
        return redirect()->route('customers.show', $customer)->with('success', 'Pelanggan diperbarui.');
    }

    public function destroy(Customer $customer)
    {
        // FK service_tickets.customer_id = cascadeOnDelete: hapus pelanggan akan
        // ikut melenyapkan seluruh tiket servicenya. Blokir bila masih punya relasi —
        // gunakan merge (gabung duplikat) bila yang dimaksud duplikasi data.
        abort_if($customer->sales()->exists(), 422, 'Pelanggan punya riwayat penjualan, tidak bisa dihapus. Gunakan Gabung bila duplikat.');
        abort_if($customer->serviceTickets()->exists(), 422, 'Pelanggan punya tiket service (ikut terhapus bila dipaksa), tidak bisa dihapus. Gunakan Gabung bila duplikat.');
        abort_if($customer->tradeIns()->exists() || $customer->warranties()->exists(), 422, 'Pelanggan masih punya tukar-tambah/garansi aktif, tidak bisa dihapus.');
        try {
            if (\Schema::hasTable('secondhand_purchases') && \DB::table('secondhand_purchases')->where('customer_id', $customer->id)->exists()) {
                abort(422, 'Pelanggan punya riwayat beli-bekas (secondhand), tidak bisa dihapus.');
            }
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { throw $e; } catch (\Throwable $e) { report($e); }
        $customer->delete();
        return redirect()->route('customers.index')->with('success', 'Pelanggan dihapus.');
    }

    /**
     * Inti gabung: pindahkan relasi duplikat ke primary lalu hapus duplikat.
     * Dipakai merge() per-grup manual maupun mergeExact() otomatis.
     * Validasi lintas-toko: null-store = wildcard, >1 toko non-null = tolak.
     * @throws \InvalidArgumentException bila primary tak ada di ids / lintas toko.
     */
    protected static function mergeIdsIntoPrimary(int $primaryId, array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!in_array($primaryId, $ids, true)) {
            throw new \InvalidArgumentException('Primary harus salah satu dari grup duplikat.');
        }
        $members = Customer::whereIn('id', $ids)->get();
        if ($members->count() < 2) {
            throw new \InvalidArgumentException('Pilih minimal 2 pelanggan untuk digabung.');
        }
        $nonNullStores = $members->pluck('store_id')->filter(fn ($s) => $s !== null)->unique()->values();
        if ($nonNullStores->count() > 1) {
            throw new \InvalidArgumentException('Pelanggan berasal dari toko berbeda.');
        }
        $primary = $members->firstWhere('id', $primaryId);
        $dupes = $members->where('id', '!=', $primary->id);
        $dupeIds = $dupes->pluck('id')->all();

        DB::transaction(function () use ($primary, $dupes, $dupeIds, $nonNullStores) {
            // Lengkapi field kosong primary dari duplikat (tanpa menimpa yang sudah ada)
            foreach ($dupes as $d) {
                if (!$primary->phone && $d->phone) $primary->phone = $d->phone;
                if (!$primary->address && $d->address) $primary->address = $d->address;
                if (!$primary->member_code && $d->member_code) $primary->member_code = $d->member_code;
            }
            // Bila primary null-store tapi duplikat punya toko → adopsi toko tsb
            if ($primary->store_id === null && $nonNullStores->count() === 1) {
                $primary->store_id = $nonNullStores->first();
            }
            $primary->phone = self::normalizePhone($primary->phone);
            $primary->save();

            // Pindahkan relasi ber-FK customer_id (cek kolom agar aman bila schema berbeda)
            Sale::whereIn('customer_id', $dupeIds)->update(['customer_id' => $primary->id]);
            ServiceTicket::whereIn('customer_id', $dupeIds)->update(['customer_id' => $primary->id]);
            foreach (['trade_ins', 'warranties', 'secondhand_purchases'] as $table) {
                try {
                    if (Schema::hasColumn($table, 'customer_id')) {
                        DB::table($table)->whereIn('customer_id', $dupeIds)->update(['customer_id' => $primary->id]);
                    }
                } catch (\Throwable $e) {}
            }
            // Hutang (debts) tidak punya FK customer_id (hanya party_name) → tidak dipindah relasi,
            // tapi satukan party_name bila sama persis dengan nama duplikat agar riwayat tetap terbaca.
            // (Dilewati: tanpa mengubah makna data keuangan.)
            foreach ($dupes as $d) {
                $d->delete();
            }
            // total_belanja = sum sales primary (sama definisi dengan @show)
            $primary->update(['total_belanja' => (float) Sale::where('customer_id', $primary->id)->sum('total')]);
        });
    }

    /**
     * Gabung pelanggan duplikat ke satu primary.
     * Pindahkan sales, service_tickets (+ trade_ins & warranties bila ada FK customer_id),
     * lalu total_belanja primary = sum sales. Tanpa tabel baru.
     */
    public function merge(Request $request)
    {
        $data = $request->validate([
            'primary_id' => 'required|exists:customers,id',
            'ids' => 'required|array|min:2',
            'ids.*' => 'exists:customers,id',
        ]);
        try {
            self::mergeIdsIntoPrimary((int) $data['primary_id'], $data['ids']);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['ids' => 'Gagal gabung: ' . $e->getMessage()]);
        }
        $primary = Customer::find((int) $data['primary_id']);

        return redirect()->route('customers.show', $primary)->with('success', 'Pelanggan berhasil digabung.');
    }

    /**
     * Gabung OTOMATIS semua grup duplikat exact (no. HP sama persis ternormalisasi).
     * ATURAN primary: ID TERKECIL = master tertua (dokumentasi pilihan: data tertua
     * dipertahankan sebagai primary, total_belanja di-recalc dari sum sales pasca-merge).
     * - Hanya grup kind 'HP sama' (phone normalized exact). Grup 'Nama mirip' TIDAK disentuh
     *   (butuh keputusan manusia — lanjutkan via tombol Gabung per-grup manual).
     * - Guard lintas-toko: grup dengan >1 store_id non-null dilewati + dilaporkan.
     * - Tiap grup jalan dalam transaction sendiri (via mergeIdsIntoPrimary): 1 grup gagal
     *   tidak menggagalkan grup lain; hasil per grup dilaporkan via flash session.
     * POST /customers/merge-exact — tanpa migration, tanpa tabel baru.
     */
    public function mergeExact(Request $request)
    {
        $storeId = session('active_store_id');
        $storeId = $storeId ? (int) $storeId : null;
        $groups = array_values(array_filter(
            self::findDuplicateGroups($storeId),
            fn ($g) => ($g['kind'] ?? '') === 'HP sama'
        ));
        if (empty($groups)) {
            return back()->with('error', 'Tidak ada grup duplikat no. HP sama persis yang perlu digabung.');
        }
        $ok = [];
        $skipped = [];
        $failed = [];
        foreach ($groups as $g) {
            $ids = $g['members']->map(fn ($m) => (int) $m->id)->unique()->values()->all();
            sort($ids);
            $primaryId = $ids[0]; // ID terkecil = master tertua
            $label = 'HP ' . $g['key'] . ' (ID ' . implode(',', $ids) . ' → primary #' . $primaryId . ')';
            // Guard lintas-toko berdasar data members ter-load (kolom store_id ikut di-select).
            $stores = $g['members']->pluck('store_id')->filter(fn ($s) => $s !== null)->unique()->values();
            if ($stores->count() > 1) {
                $skipped[] = $label . ' — dilewati: lintas toko';
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
            . '(hanya no. HP sama persis; grup Nama mirip tidak disentuh).';
        if (count($failed) > 0) {
            return back()->with('merge_exact_summary', $summary)->with('error', $msg);
        }
        return back()->with('merge_exact_summary', $summary)->with('success', $msg);
    }

    /**
     * Pindah massal pelanggan antar toko (cermin ServiceController@bulkTransferStore):
     * update store_id saja, maks 100 per submit, hasil lapor per baris.
     * - Riwayat sales/service TIDAK dipindah: invoice & tiket lama tetap
     *   tercatat (relasi customer_id utuh), hanya kepemilikan master
     *   pelanggan yang berpindah toko.
     * - Guard merge-exact lintas-toko jadi tidak relevan pasca-pindah:
     *   dua baris ber-HP sama yang kini satu toko akan terdeteksi sebagai
     *   grup "HP sama" dan bisa digabung manual/otomatis bila memang duplikat.
     * POST /customers/move-store — tanpa migration, tanpa tabel baru.
     */
    public function moveStore(Request $request)
    {
        $storeId = session('active_store_id');
        $storeId = $storeId ? (int) $storeId : null;
        $data = $request->validate([
            'ids' => 'required|array|min:1|max:100',
            'ids.*' => 'integer|exists:customers,id',
            'target_store_id' => 'required|integer|exists:stores,id',
        ], [
            'ids.required' => 'Pilih minimal 1 pelanggan (centang baris tabel).',
            'ids.min' => 'Pilih minimal 1 pelanggan (centang baris tabel).',
            'ids.max' => 'Maksimal 100 pelanggan per pindah toko massal.',
            'target_store_id.required' => 'Pilih toko tujuan dulu.',
            'target_store_id.exists' => 'Toko tujuan tidak ditemukan.',
        ]);

        $dest = Store::whereKey((int) $data['target_store_id'])->where('is_active', true)->first();
        if (!$dest) {
            return back()->with('error', 'Toko tujuan tidak aktif.');
        }
        if ($storeId && (int) $storeId === (int) $dest->id) {
            return back()->with('error', 'Toko tujuan sama dengan toko asal (toko aktif).');
        }

        $ids = array_values(array_unique(array_map('intval', $data['ids'])));
        $rows = Customer::with('store')
            ->whereIn('id', $ids)
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->get()
            ->keyBy('id');

        $ok = [];
        $skipped = [];
        $failed = [];
        foreach ($ids as $id) {
            $c = $rows->get($id);
            if (!$c) {
                $failed[] = '#'
                    . $id
                    . ': bukan pelanggan toko aktif';
                continue;
            }
            if ((int) $c->store_id === (int) $dest->id) {
                $skipped[] = $c->name
                    . ' (#'
                    . $c->id
                    . '): dilewati — sudah di '
                    . $dest->name;
                continue;
            }
            try {
                $fromName = $c->store->name ?? ('Toko #' . $c->store_id);
                $c->update(['store_id' => (int) $dest->id]);
                $ok[] = $c->name
                    . ' (#'
                    . $c->id
                    . '): '
                    . $fromName
                    . ' → '
                    . $dest->name;
            } catch (\Throwable $e) {
                report($e);
                $failed[] = $c->name
                    . ' (#'
                    . $c->id
                    . '): gagal ('
                    . $e->getMessage()
                    . ')';
            }
        }

        $summary = [
            'tujuan' => $dest->name,
            'berhasil' => count($ok),
            'dilewati' => count($skipped),
            'gagal' => count($failed),
            'details_ok' => $ok,
            'details_skipped' => $skipped,
            'details_failed' => $failed,
        ];
        $msg = 'Pindah toko massal ke '
            . $dest->name
            . ': '
            . count($ok)
            . ' berhasil, '
            . count($skipped)
            . ' dilewati, '
            . count($failed)
            . ' gagal '
            . '(riwayat sales/service tetap, hanya master pelanggan yang pindah).';
        if (count($failed) > 0 && count($ok) === 0) {
            return back()->with('move_store_summary', $summary)->with('error', $msg);
        }
        return back()->with('move_store_summary', $summary)->with('success', $msg);
    }

    /** Hanya owner/admin/superadmin yang boleh broadcast promo WA. */
    protected function ensureBroadcastAllowed(): void
    {
        abort_unless(
            in_array(auth()->user()->role ?? '', ['superadmin', 'owner', 'admin'], true),
            403,
            'Hanya owner/admin/superadmin yang boleh broadcast promo.'
        );
    }

    /**
     * UNDUH TEMPLATE CSV pelanggan: header + 1 baris contoh, delimiter ;, BOM.
     * Kolom cocok dengan @import (nama,hp,alamat,member).
     * GET /customers/template — didaftarkan SEBELUM resource agar tak tertabrak {customer}.
     */
    public function template()
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv(
                $out,
                ['nama', 'hp', 'alamat', 'member'],
                ';'
            );
            fputcsv(
                $out,
                ['Budi Santoso', '081234567890', 'Jl. Melati No. 10', 'MBR-1001'],
                ';'
            );
            fclose($out);
        }, 'template_pelanggan.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Import CSV pelanggan (cermin ProductController@import):
     * delimiter auto `;`/`,`, header id (kolom `id` hasil export diabaikan).
     * Kolom: nama, hp, alamat, member (alias: name/phone/address/member_code).
     * - HP dinormalisasi via normalizePhone (08… → 628…); baris gagal bila
     *   nama kosong / HP tidak valid / kode member bentrok milik lain.
     * - Duplikat = UPDATE bila HP ternormalisasi sama dalam scope toko aktif
     *   (nama selalu ditimpa CSV; alamat/member ditimpa hanya bila CSV isi).
     * - Tanpa HP → selalu buat baru. Tanpa migration.
     * POST /customers/import — didaftarkan SEBELUM resource agar tak tertabrak {customer}.
     */
    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:2048']);
        $storeId = session('active_store_id') ? (int) session('active_store_id') : null;

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        // Deteksi delimiter: file hasil Export memakai ';' (Excel Indonesia), file manual umumnya ','.
        $firstLine = fgets($handle) ?: '';
        rewind($handle);
        $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';
        $readCsv = function () use ($handle, $delimiter) {
            $cols = fgetcsv($handle, 0, $delimiter);
            if (is_array($cols)) $cols = array_map(fn ($c) => is_string($c) ? ltrim($c, "\xEF\xBB\xBF") : $c, $cols);
            return $cols;
        };
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $readCsv() ?: []);
        $created = 0;
        $updated = 0;
        $fail = [];
        $line = 1;
        while (($cols = $readCsv()) !== false) {
            $line++;
            if (count($cols) === 1 && trim((string) $cols[0]) === '') continue;
            $row = array_combine(array_slice(array_merge($header, range(0, count($cols) - 1)), 0, count($cols)), $cols);
            $g = function (string ...$keys) use ($row) {
                foreach ($keys as $k) {
                    if (array_key_exists($k, $row) && trim((string) $row[$k]) !== '') {
                        return trim((string) $row[$k]);
                    }
                }
                return '';
            };
            $nama = $g('nama', 'name');
            $hpRaw = $g('hp', 'phone', 'no_hp', 'telp', 'telepon');
            $alamat = $g('alamat', 'address');
            $member = $g('member', 'member_code', 'kode_member');
            if ($nama === '') {
                $fail[] = "Baris $line: nama kosong";
                continue;
            }
            try {
                $phone = $hpRaw === '' ? null : self::normalizePhone($hpRaw);
            } catch (\Throwable $e) {
                $fail[] = "Baris $line: hp tidak valid ($hpRaw)";
                continue;
            }
            if ($hpRaw !== '' && ($phone === null || !preg_match('/^[0-9]{9,15}$/', (string) $phone))) {
                $fail[] = "Baris $line: hp tidak valid ($hpRaw)";
                continue;
            }

            try {
                // Cari duplikat by HP ternormalisasi dalam scope toko aktif.
                // Varian legacy: bila tersimpan mentah 08…, cocokkan juga bentuk 0…-nya.
                $existing = null;
                if ($phone !== null) {
                    $existing = Customer::query()
                        ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
                        ->where('phone', $phone)
                        ->first();
                    if (!$existing && str_starts_with((string) $phone, '62')) {
                        $legacy = '0' . substr((string) $phone, 2);
                        $existing = Customer::query()
                            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
                            ->where('phone', $legacy)
                            ->first();
                    }
                }
                if ($member !== '' && Customer::where('member_code', $member)
                        ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))
                        ->exists()) {
                    $fail[] = "Baris $line: kode member $member sudah dipakai pelanggan lain";
                    continue;
                }
                if ($existing) {
                    $existing->name = $nama;
                    if ($alamat !== '') $existing->address = $alamat;
                    if ($member !== '') $existing->member_code = $member;
                    $existing->phone = $phone;
                    $existing->save();
                    $updated++;
                } else {
                    Customer::create([
                        'store_id' => $storeId,
                        'name' => $nama,
                        'phone' => $phone,
                        'address' => $alamat !== '' ? $alamat : null,
                        'member_code' => $member !== '' ? $member : null,
                    ]);
                    $created++;
                }
            } catch (\Throwable $e) {
                report($e);
                $fail[] = "Baris $line: " . $e->getMessage();
            }
        }
        fclose($handle);
        $ok = $created + $updated;

        return back()
            ->with('success', "Import selesai: $ok berhasil ($created baru, $updated diperbarui), " . count($fail) . ' gagal (duplikat HP = update, tanpa HP = data baru).')
            ->with('import_fail', $fail);
    }

    /** Scope toko aktif (superadmin tanpa toko aktif = semua toko). */
    protected function broadcastStoreId(): ?int
    {
        $id = session('active_store_id') ?? auth()->user()->active_store_id ?? null;
        return $id ? (int) $id : null;
    }

    /** Terapkan filter tier yang sama dengan @index. */
    protected function broadcastTierScope($query, ?string $tier)
    {
        return match ($tier) {
            'Bronze' => $query->where('total_belanja', '<', 1000000),
            'Silver' => $query->where('total_belanja', '>=', 1000000)->where('total_belanja', '<=', 5000000),
            'Gold' => $query->where('total_belanja', '>', 5000000),
            default => $query,
        };
    }

    /**
     * Segmen "Pernah service" via EXISTS ke service_tickets (tanpa migration/relasi baru).
     * - ever: pelanggan punya >=1 tiket service (kapan pun).
     * - stale: punya >=1 tiket TAPI tidak ada tiket dalam 6 bulan terakhir
     *   (cocok untuk ajakan servis lagi / recall).
     */
    protected function broadcastServiceScope($query, ?string $segment)
    {
        return match ($segment) {
            'ever' => $query->whereExists(function ($q) {
                $q->select(DB::raw(1))->from('service_tickets')
                    ->whereColumn('service_tickets.customer_id', 'customers.id');
            }),
            'stale' => $query
                ->whereExists(function ($q) {
                    $q->select(DB::raw(1))->from('service_tickets')
                        ->whereColumn('service_tickets.customer_id', 'customers.id');
                })
                ->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))->from('service_tickets')
                        ->whereColumn('service_tickets.customer_id', 'customers.id')
                        ->where('service_tickets.created_at', '>=', now()->subMonths(6));
                }),
            default => $query,
        };
    }

    /** Label manusiawi untuk segmen service broadcast. */
    protected static function broadcastServiceLabel(?string $segment): string
    {
        return match ($segment) {
            'ever' => 'Pernah service',
            'stale' => 'Service >6 bulan lalu',
            default => 'Semua',
        };
    }

    /** Nomor valid = hasil normalisasi WaService diawali 62 & panjang 10–15 digit. */
    protected static function validBroadcastPhone(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') return null;
        try {
            $p = WaService::normalize($raw);
        } catch (\Throwable $e) {
            return null;
        }
        return preg_match('/^62[0-9]{8,13}$/', $p) ? $p : null;
    }

    /**
     * Form broadcast promo WA (GET).
     * Menampilkan filter tier + segmen pernah-service + textarea template
     * + hitungan calon penerima.
     */
    public function broadcast()
    {
        $this->ensureBroadcastAllowed();
        $storeId = $this->broadcastStoreId();
        $defaultTemplate = "Halo {nama}, ada promo spesial dari {toko}! Tunjukkan pesan ini di kasir untuk klaim diskon. Balas STOP bila tidak ingin menerima promo lagi.";
        $counts = ['semua' => 0, 'Bronze' => 0, 'Silver' => 0, 'Gold' => 0, 'service_ever' => 0, 'service_stale' => 0];
        try {
            foreach (['Bronze', 'Silver', 'Gold'] as $t) {
                $counts[$t] = (int) $this->broadcastTierScope(
                    Customer::when($storeId, fn ($q) => $q->where('store_id', $storeId)), $t
                )->count();
            }
            $counts['semua'] = (int) Customer::when($storeId, fn ($q) => $q->where('store_id', $storeId))->count();
            foreach (['ever' => 'service_ever', 'stale' => 'service_stale'] as $seg => $key) {
                $counts[$key] = (int) $this->broadcastServiceScope(
                    Customer::when($storeId, fn ($q) => $q->where('store_id', $storeId)), $seg
                )->count();
            }
        } catch (\Throwable $e) {
            report($e);
        }
        return view('customers.broadcast', [
            'counts' => $counts,
            'defaultTemplate' => $defaultTemplate,
            'result' => null,
        ]);
    }

    /**
     * Kirim broadcast promo WA (POST). Maks 100/pengiriman, hanya phone valid,
     * dedup nomor per batch, tiap kirim dicatat WaService (type promo_broadcast).
     * Mendukung kombinasi filter tier + segmen pernah-service.
     */
    public function broadcastSend(Request $request)
    {
        $this->ensureBroadcastAllowed();
        $data = $request->validate([
            'tier' => 'nullable|in:Bronze,Silver,Gold',
            'service' => 'nullable|in:ever,stale',
            'template' => 'required|string|max:1000',
        ], [
            'tier.in' => 'Tier tidak valid (pilih Bronze/Silver/Gold atau kosongkan untuk semua).',
            'service.in' => 'Segmen service tidak valid (pilih Pernah service / Service >6 bulan lalu atau kosongkan).',
            'template.required' => 'Teks template wajib diisi.',
            'template.max' => 'Teks template maksimal 1000 karakter.',
        ]);
        $tier = $data['tier'] ?? null;
        $service = $data['service'] ?? null;
        $template = trim($data['template']);
        $storeId = $this->broadcastStoreId();
        $storeName = $storeId
            ? (string) (\App\Models\Store::whereKey($storeId)->value('name') ?? '-')
            : (string) config('app.name', 'ServiceHP');

        $targets = $this->broadcastServiceScope(
            $this->broadcastTierScope(
                Customer::with('store')
                    ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
                    ->orderByDesc('id'),
                $tier
            ),
            $service
        )->limit(100)->get();

        $sent = 0; $failed = 0; $skipped = 0;
        $details = [];
        $seenPhones = [];
        foreach ($targets as $c) {
            $phone = self::validBroadcastPhone($c->phone);
            if (!$phone) {
                $skipped++;
                $details[] = ['nama' => $c->name, 'phone' => $c->phone ?? '-', 'status' => 'dilewati', 'ket' => 'Nomor HP kosong/tidak valid'];
                continue;
            }
            if (isset($seenPhones[$phone])) {
                $skipped++;
                $details[] = ['nama' => $c->name, 'phone' => $phone, 'status' => 'dilewati', 'ket' => 'Duplikat nomor (sudah dikirim batch ini)'];
                continue;
            }
            $seenPhones[$phone] = true;
            $msg = str_replace(
                ['{nama}', '{toko}'],
                [$c->name, $c->store->name ?? $storeName],
                $template
            );
            try {
                $ok = WaService::send($phone, $msg, 'promo_broadcast', $c->store_id ?? $storeId);
            } catch (\Throwable $e) {
                report($e);
                $ok = false;
            }
            if ($ok) {
                $sent++;
                $details[] = ['nama' => $c->name, 'phone' => $phone, 'status' => 'terkirim', 'ket' => '-'];
            } else {
                $failed++;
                $details[] = ['nama' => $c->name, 'phone' => $phone, 'status' => 'gagal', 'ket' => 'Gateway gagal (cek log WA)'];
            }
        }

        $result = [
            'tier' => $tier ?: 'Semua',
            'service' => self::broadcastServiceLabel($service),
            'target' => $targets->count(),
            'terkirim' => $sent,
            'gagal' => $failed,
            'dilewati' => $skipped,
            'details' => $details,
        ];
        return view('customers.broadcast', [
            'counts' => ['semua' => 0, 'Bronze' => 0, 'Silver' => 0, 'Gold' => 0, 'service_ever' => 0, 'service_stale' => 0],
            'defaultTemplate' => $template,
            'result' => $result,
        ])->with(
            $failed > 0 ? 'error' : 'success',
            "Broadcast selesai: {$sent} terkirim, {$failed} gagal, {$skipped} dilewati (dari {$targets->count()} target)."
        );
    }
}
