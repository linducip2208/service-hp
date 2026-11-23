<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ServiceLog;
use App\Models\ServiceSparepart;
use App\Models\ServiceTicket;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\TechnicianCommission;
use App\Models\User;
use App\Models\Warranty;
use App\Services\WaService;
use App\Support\LookupCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ServiceController extends Controller
{
    protected array $flow = ['diterima','diagnosa','menunggu_persetujuan','disetujui','dikerjakan','qc','siap_diambil','diserahkan'];

    protected array $nextMap = [
        'diterima' => ['diagnosa','batal'],
        'diagnosa' => ['menunggu_persetujuan','batal'],
        'menunggu_persetujuan' => ['disetujui','batal'],
        'disetujui' => ['dikerjakan','batal'],
        'dikerjakan' => ['qc','batal'],
        'qc' => ['siap_diambil','batal'],
        'siap_diambil' => ['diserahkan','batal'],
        'diserahkan' => [],
        'batal' => [],
    ];

    private function storeId(): ?int
    {
        return session('active_store_id') ?? auth()->user()->active_store_id ?? null;
    }

    private function badge(string $status): string
    {
        return match ($status) {
            'diterima' => 'bg-blue-lt',
            'diagnosa' => 'bg-cyan-lt',
            'menunggu_persetujuan' => 'bg-yellow-lt',
            'disetujui' => 'bg-teal-lt',
            'dikerjakan' => 'bg-purple-lt',
            'qc' => 'bg-orange-lt',
            'siap_diambil' => 'bg-green-lt',
            'diserahkan' => 'bg-dark-lt',
            'batal' => 'bg-red-lt',
            default => 'bg-secondary-lt',
        };
    }

    private function recalcTotal(ServiceTicket $t): void
    {
        $sp = (float) $t->spareparts()->sum('subtotal');
        $t->total_cost = (float) $t->estimate_cost + $sp;
        $t->save();
    }

    private function recordCash(?int $storeId, string $type, string $category, float $amount, string $refType, int $refId, string $desc): void
    {
        if ($amount <= 0) return;
        $account = CashAccount::where('store_id', $storeId)->where('is_default', true)->first()
            ?? CashAccount::where('store_id', $storeId)->first();
        if (!$account) {
            $account = CashAccount::create(['store_id' => $storeId, 'name' => 'Kas Utama', 'balance' => 0, 'is_default' => true]);
        }
        CashTransaction::create([
            'store_id' => $storeId,
            'cash_account_id' => $account->id,
            'type' => $type,
            'category' => $category,
            'amount' => $amount,
            'ref_type' => $refType,
            'ref_id' => $refId,
            'description' => $desc,
            'user_id' => auth()->id(),
        ]);
        $account->balance = (float) $account->balance + ($type === 'masuk' ? $amount : -$amount);
        $account->save();
    }

    public function index(Request $request)
    {
        $storeId = $this->storeId();
        $q = ServiceTicket::with(['customer','technician'])->where('store_id', $storeId)->latest();
        if ($request->filled('status')) $q->where('status', $request->status);
        if ($request->filled('technician_id')) $q->where('technician_id', $request->technician_id);
        if ($request->filled('search')) {
            $s = trim((string) $request->search);
            // Varian normalisasi no. HP 08… <-> 628… (pola CustomerController@index)
            // agar CARI TIKET by HP ketemu walau format beda (08… vs 628…).
            $digits = preg_replace('/[^0-9]/', '', $s) ?? '';
            $sAlt = null;
            if ($digits !== '') {
                if (str_starts_with($digits, '0')) {
                    $sAlt = '62' . substr($digits, 1);
                } elseif (str_starts_with($digits, '62')) {
                    $sAlt = '0' . substr($digits, 2);
                }
            }
            $q->where(function ($w) use ($s, $digits, $sAlt) {
                $w->where('ticket_no', 'like', "%$s%")
                  ->orWhere('imei', 'like', "%$s%")
                  ->orWhere('product_brand', 'like', "%$s%")
                  ->orWhere('product_type', 'like', "%$s%")
                  ->orWhereHas('customer', function ($c) use ($s, $digits, $sAlt) {
                      $c->where('name', 'like', "%$s%")
                        ->orWhere('phone', 'like', "%$s%");
                      if ($digits !== '' && $digits !== $s) {
                          $c->orWhere('phone', 'like', "%$digits%");
                      }
                      if ($sAlt) {
                          $c->orWhere('phone', 'like', "%$sAlt%");
                      }
                  });
            });
        }

        // Filter prioritas (?priority=rendah|normal|mendesak) — pertahankan filter existing
        if ($request->filled('priority') && in_array($request->priority, ['rendah', 'normal', 'mendesak'])) {
            $q->where('priority', $request->priority);
        }

        // Filter rentang tanggal tiket (created_at): ?from=YYYY-MM-DD & ?to=YYYY-MM-DD.
        // Diterapkan sebelum sort/export agar Export CSV ikut rentang yang sama.
        if ($request->filled('from')) {
            $q->whereDate('created_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $q->whereDate('created_at', '<=', $request->to);
        }

        // Sorting prioritas (?sort=priority): mendesak dulu, lalu estimasi terdekat
        if ($request->get('sort') === 'priority') {
            $q->reorder()
                ->orderByRaw("CASE priority WHEN 'mendesak' THEN 0 WHEN 'normal' THEN 1 WHEN 'rendah' THEN 2 ELSE 3 END")
                ->orderByRaw('COALESCE(estimated_done_at, created_at) ASC')
                ->orderByDesc('id');
        }
        // Export CSV (?export=csv) — hormati filter aktif + scope toko
        if ($request->get('export') === 'csv') {
            $rows = (clone $q)->with(['customer', 'technician'])->limit(5000)->get();
            $filename = 'service-' . now()->format('Ymd-His') . '.csv';
            return $this->csvDownload($filename, ['id', 'tiket', 'tanggal', 'pelanggan', 'hp', 'device', 'imei', 'teknisi', 'status', 'estimasi_jasa', 'total'], array_map(fn($t) => [
                $t->id, $t->ticket_no, $t->created_at?->format('Y-m-d H:i'), $t->customer->name ?? '',
                $t->customer->phone ?? '', trim(($t->product_brand ?? '') . ' ' . ($t->product_type ?? '')),
                $t->imei ?? '', $t->technician->name ?? '', $t->status,
                (float) ($t->estimate_cost ?? 0), (float) ($t->total_cost ?? 0),
            ], $rows->all()));
        }

        $tickets = $q->paginate(15)->withQueryString();
        $technicians = LookupCache::technicians();
        $statuses = array_merge($this->flow, ['batal']);
        // Daftar toko aktif untuk dropdown pindah massal (tanpa migration/route baru di blade).
        $stores = Store::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        // Antrian mendesak aktif (tiket prioritas mendesak, belum selesai/batal) — untuk banner teratas
        $urgentTickets = ServiceTicket::with(['customer'])
            ->where('store_id', $storeId)
            ->where('priority', 'mendesak')
            ->whereNotIn('status', ['diserahkan', 'batal'])
            ->orderByRaw('COALESCE(estimated_done_at, created_at) ASC')
            ->limit(5)->get();
        $urgentCount = ServiceTicket::where('store_id', $storeId)
            ->where('priority', 'mendesak')
            ->whereNotIn('status', ['diserahkan', 'batal'])->count();
        return view('services.index', compact('tickets','technicians','statuses','urgentTickets','urgentCount','stores'));
    }

    public function create(Request $request)
    {
        $technicians = LookupCache::technicians();
        $customers = Customer::where('store_id', $this->storeId())->orderBy('name')->limit(200)->get();
        // Preselect pelanggan baru dari modal "Pelanggan Baru" inline
        // (?customer_id=ID via customers.store redirect=service) — pola PosController@create.
        $selectedCustomerId = $request->filled('customer_id') ? (int) $request->get('customer_id') : null;
        if ($selectedCustomerId && !$customers->contains('id', $selectedCustomerId)) {
            $extra = Customer::where('store_id', $this->storeId())->find($selectedCustomerId);
            if ($extra) {
                $customers->push($extra);
            } else {
                $selectedCustomerId = null;
            }
        }
        // Tier display per pelanggan (Bronze <1jt, Silver 1-5jt, Gold >5jt) — tanpa query baru.
        $customerTiers = [];
        try {
            foreach ($customers as $c) {
                $total = (float) ($c->total_belanja ?? 0);
                $customerTiers[(int) $c->id] = $total > 5000000 ? 'Gold' : ($total >= 1000000 ? 'Silver' : 'Bronze');
            }
        } catch (\Throwable $e) {
            $customerTiers = [];
        }
        // Beban teknisi: tiket aktif (belum diserahkan/batal), global lintas toko.
        $workload = collect();
        try {
            $workload = DB::table('service_tickets')
                ->select('technician_id', DB::raw('COUNT(*) as aktif'))
                ->whereNotNull('technician_id')
                ->whereNotIn('status', ['diserahkan', 'batal'])
                ->groupBy('technician_id')
                ->pluck('aktif', 'technician_id');
        } catch (\Throwable $e) {
            $workload = collect();
        }
        $suggestedTechnicianId = null;
        try {
            $min = null;
            foreach ($technicians as $t) {
                $load = (int) ($workload[$t->id] ?? 0);
                if ($min === null || $load < $min) {
                    $min = $load;
                    $suggestedTechnicianId = $t->id;
                }
            }
        } catch (\Throwable $e) {
            $suggestedTechnicianId = null;
        }
        // Cegah tiket ganda: pratinjau tiket AKTIF dengan IMEI mirip bila ?imei= tersedia.
        // Tanpa route baru — murni server-side, data existing tidak diubah.
        $imeiQuery = trim((string) $request->query('imei', ''));
        $imeiDuplicates = collect();
        if ($imeiQuery !== '') {
            $normQuery = preg_replace('/\D+/', '', $imeiQuery) ?? '';
            if ($normQuery !== '') {
                try {
                    $imeiDuplicates = ServiceTicket::with(['store', 'customer'])
                        ->whereNotIn('status', ['diserahkan', 'batal'])
                        ->whereNotNull('imei')->where('imei', '!=', '')
                        ->orderByDesc('id')->limit(500)->get()
                        ->filter(fn($t) => ($d = preg_replace('/\D+/', '', (string) $t->imei) ?? '') !== ''
                            && ($d === $normQuery || str_contains($d, $normQuery) || str_contains($normQuery, $d)))
                        ->values();
                } catch (\Throwable $e) {
                    $imeiDuplicates = collect();
                }
            }
        }
        // Riwayat device per customer (chip 1-klik di form): 50 tiket terbaru per customer,
        // tanpa route baru — di-embed sebagai JSON di halaman. Try/catch agar form tetap jalan.
        $deviceHistory = [];
        try {
            $cIds = $customers->pluck('id')->all();
            if (!empty($cIds)) {
                $rows = ServiceTicket::whereIn('customer_id', $cIds)
                    ->orderByDesc('id')->limit(2000)
                    ->get(['id', 'customer_id', 'ticket_no', 'product_brand', 'product_type', 'imei', 'status', 'created_at']);
                $grouped = [];
                foreach ($rows as $r) {
                    $cid = (int) $r->customer_id;
                    $grouped[$cid] ??= [];
                    if (count($grouped[$cid]) >= 50) continue;
                    $grouped[$cid][] = [
                        'brand' => (string) ($r->product_brand ?? ''),
                        'type' => (string) ($r->product_type ?? ''),
                        'imei' => (string) ($r->imei ?? ''),
                        'status' => (string) ($r->status ?? ''),
                        'tiket' => (string) ($r->ticket_no ?? ''),
                        'tgl' => $r->created_at ? $r->created_at->format('Y-m-d') : '',
                    ];
                }
                $deviceHistory = $grouped;
            }
        } catch (\Throwable $e) {
            $deviceHistory = [];
        }
        // Salin tiket (kunjungan ulang): ?copy_from=ID → prefill customer/brand/type/imei
        // dari tiket milik toko aktif. Tanpa foto/status lama — form create selalu
        // buat tiket baru status 'diterima', foto dikosongkan. Tanpa route baru.
        $copySource = null;
        if ($request->filled('copy_from')) {
            try {
                $copySource = ServiceTicket::where('store_id', $this->storeId())
                    ->with('customer')
                    ->find((int) $request->get('copy_from'));
            } catch (\Throwable $e) {
                $copySource = null;
            }
        }
        if ($copySource) {
            $copyCustomerId = (int) $copySource->customer_id;
            if ($copyCustomerId && !$customers->contains('id', $copyCustomerId)) {
                $extraCopy = Customer::where('store_id', $this->storeId())->find($copyCustomerId);
                if ($extraCopy) {
                    $customers->push($extraCopy);
                } else {
                    $copyCustomerId = 0;
                }
            }
            // copy_from menang atas ?customer_id= bila keduanya ada.
            $selectedCustomerId = $copyCustomerId ?: $selectedCustomerId;
            // copy_from menang atas ?imei= bila keduanya ada.
            $imeiQuery = trim((string) ($copySource->imei ?? '')) !== ''
                ? trim((string) $copySource->imei)
                : $imeiQuery;
        }
        return view('services.create', compact('technicians','customers','customerTiers','selectedCustomerId','workload','suggestedTechnicianId','imeiDuplicates','imeiQuery','deviceHistory','copySource'));
    }

    public function store(Request $request)
    {
        $storeId = $this->storeId();
        $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'customer_name' => 'required_without:customer_id|max:100',
            'customer_phone' => 'required_without:customer_id|max:20',
            'product_brand' => 'required|max:50',
            'product_type' => 'required|max:100',
            'imei' => 'nullable|max:30',
            'complaint' => 'required',
            'condition_note' => 'nullable',
            'priority' => 'nullable|in:rendah,normal,mendesak',
            'technician_id' => 'nullable|exists:users,id',
            'warranty_days' => 'nullable|integer|min:0|max:365',
            'estimated_done_at' => 'nullable|date',
            'photos.*' => 'required|image|max:2048',
            'photos' => 'required|array|min:1|max:4',
        ], [
            'photos.required' => 'Foto kondisi HP wajib diisi (minimal 1 foto).',
            'photos.min' => 'Foto kondisi HP wajib diisi (minimal 1 foto).',
            'customer_name.required_without' => 'Nama pelanggan wajib diisi bila pelanggan belum dipilih.',
            'customer_phone.required_without' => 'No. HP pelanggan wajib diisi bila pelanggan belum dipilih.',
            'product_brand.required' => 'Merek HP wajib diisi.',
            'product_type.required' => 'Tipe HP wajib diisi.',
            'complaint.required' => 'Keluhan wajib diisi.',
            'technician_id.exists' => 'Teknisi tidak ditemukan.',
            'photos.max' => 'Maksimal 4 foto.',
            'photos.*.required' => 'Foto kondisi HP wajib diisi (minimal 1 foto).',
            'photos.*.image' => 'Foto harus berupa gambar.',
            'photos.*.max' => 'Ukuran tiap foto maksimal 2 MB.',
        ]);

        DB::beginTransaction();
        try {
            // CEGAH TIKET GANDA: tolak bila IMEI (dinormalisasi ke digits saja) sudah
            // punya tiket AKTIF (status selain diserahkan/batal), lintas semua toko.
            // IMEI kosong tetap boleh. Data existing tidak diubah; IMEI baru disimpan apa adanya.
            $rawImei = trim((string) $request->input('imei', ''));
            if ($rawImei !== '') {
                $normImei = preg_replace('/\D+/', '', $rawImei) ?? '';
                if ($normImei !== '') {
                    $dup = ServiceTicket::with('store')
                        ->whereNotIn('status', ['diserahkan', 'batal'])
                        ->whereNotNull('imei')->where('imei', '!=', '')
                        ->orderByDesc('id')->limit(500)->get()
                        ->first(fn($t) => (preg_replace('/\D+/', '', (string) $t->imei) ?? '') === $normImei);
                    if ($dup) {
                        DB::rollBack();
                        $dupStore = $dup->store->name ?? ('Toko #' . $dup->store_id);
                        $dupUrl = route('services.show', $dup);
                        return back()->with('error', "HP dengan IMEI {$rawImei} sudah punya tiket AKTIF {$dup->ticket_no} (status: {$dup->status}, toko: {$dupStore}). Gunakan tiket existing — buka: {$dupUrl}")->withInput();
                    }
                }
            }
            if ($request->filled('customer_id')) {
                $customer = Customer::where('store_id', $storeId)->findOrFail($request->customer_id);
            } else {
                // Normalisasi HP 08… → 628… via WaService (sama seperti CustomerController@store),
                // agar pelanggan inline tidak duplikat hanya karena format nomor beda.
                $phone = WaService::normalize((string) $request->customer_phone);
                $customer = Customer::where('store_id', $storeId)->where('phone', $phone)->first();
                if (!$customer) {
                    $customer = Customer::create([
                        'store_id' => $storeId,
                        'name' => trim((string) $request->customer_name),
                        'phone' => $phone,
                    ]);
                }
            }

            // Tiket unik: {KODETOKO-}SVC-YYYYMMDD-NNNN (prefix kode toko di
            // depan bila stores.code terisi, fallback format lama bila kosong).
            $prefix = \App\Support\DocNumber::prefixForStore($storeId) . 'SVC-' . now()->format('Ymd') . '-';
            $count = ServiceTicket::where('store_id', $storeId)->where('ticket_no', 'like', $prefix . '%')->count() + 1;
            do {
                $ticketNo = $prefix . str_pad($count, 4, '0', STR_PAD_LEFT);
                $count++;
            } while (ServiceTicket::where('ticket_no', $ticketNo)->exists());

            $photos = [];
            if ($request->hasFile('photos')) {
                foreach ($request->file('photos') as $f) {
                    $photos[] = $f->store('service', 'public');
                }
            }

            $priorityForEta = (string) ($request->input('priority') ?: 'normal');
            $etaDays = match ($priorityForEta) {
                'mendesak' => 1,
                'rendah' => 4,
                default => 2,
            };
            $estimatedDoneAt = $request->estimated_done_at ?: now()->addDays($etaDays);

            $ticket = ServiceTicket::create([
                'ticket_no' => $ticketNo,
                'store_id' => $storeId,
                'customer_id' => $customer->id,
                'technician_id' => $request->technician_id,
                'product_brand' => $request->product_brand,
                'product_type' => $request->product_type,
                'imei' => $request->imei,
                'complaint' => $request->complaint,
                'condition_note' => $request->condition_note,
                'photos' => $photos ?: null,
                'diagnosis' => null,
                'estimate_cost' => 0,
                'approval_status' => 'menunggu',
                'status' => 'diterima',
                'priority' => $request->priority ?? 'normal',
                'dp' => 0,
                'total_cost' => 0,
                'warranty_days' => $request->warranty_days ?? 7,
                'estimated_done_at' => $estimatedDoneAt,
            ]);

            ServiceLog::create([
                'service_ticket_id' => $ticket->id,
                'user_id' => auth()->id(),
                'from_status' => null,
                'to_status' => 'diterima',
                'note' => 'Tiket dibuat / HP diterima.',
            ]);

            DB::commit();
            return redirect()->route('services.show', $ticket)->with('success', 'Tiket ' . $ticketNo . ' berhasil dibuat.');
        } catch (\Throwable $e) {
            DB::rollBack();
            return back()->with('error', 'Gagal menyimpan tiket: ' . $e->getMessage())->withInput();
        }
    }

    public function show(ServiceTicket $service)
    {
        $this->authorizeStore($service);
        $service->load(['customer','technician','spareparts.product','logs.user','commissions']);
        $technicians = LookupCache::technicians();
        $sparepartProducts = Product::where('is_active', true)
            ->where(function ($q) { $q->where('type', 'sparepart')->orWhere('type', 'like', '%spare%'); })
            ->orderBy('name')->limit(300)->get();
        if ($sparepartProducts->isEmpty()) {
            $sparepartProducts = Product::where('is_active', true)->orderBy('name')->limit(300)->get();
        }
        $cashAccounts = CashAccount::where('store_id', $service->store_id)->orderBy('name')->get();
        $allowedNext = $this->nextMap[$service->status] ?? [];
        $waPhone = WaService::normalize($service->customer->phone ?? '');
        $sisa = max(0, (float) $service->total_cost - (float) $service->dp);
        $waApproveText = urlencode("Halo {$service->customer->name}, estimasi service tiket *{$service->ticket_no}* ({$service->product_brand} {$service->product_type}) sebesar Rp " . number_format($service->total_cost, 0, ',', '.') . ". Balas SETUJU untuk lanjut pengerjaan.");
        // Riwayat HP ini (IMEI sama, tiket lain)
        $history = collect();
        if ($service->imei) {
            $history = ServiceTicket::with('technician')
                ->where('store_id', $service->store_id)
                ->where('id', '!=', $service->id)
                ->where('imei', $service->imei)
                ->latest()->limit(10)->get();
        }
        // QC checklist terakhir (disimpan sebagai service_logs note "QC-CHECK:{json}")
        $qcItems = ['Fungsi nyala', 'Sinyal', 'Kamera', 'Charging', 'Tombol', 'Speaker'];
        $qcChecked = [];
        $qcLog = ServiceLog::where('service_ticket_id', $service->id)
            ->where('note', 'like', 'QC-CHECK:%')->latest()->first();
        if ($qcLog && preg_match('/QC-CHECK:(.*)/s', (string) $qcLog->note, $m)) {
            $qcChecked = json_decode(trim($m[1]), true) ?: [];
        }
        // Checklist serah terima (disimpan sebagai service_logs note "HANDOVER-CHECK:{json}")
        // Pola sama dengan QC-CHECK, tanpa migration.
        $handoverItems = ['HP sudah dites nyala', 'Nota/struk diserahkan', 'Garansi dijelaskan'];
        $handoverChecked = [];
        $handoverCheckLog = ServiceLog::where('service_ticket_id', $service->id)
            ->where('note', 'like', 'HANDOVER-CHECK:%')->latest()->first();
        if ($handoverCheckLog && preg_match('/HANDOVER-CHECK:(.*)/s', (string) $handoverCheckLog->note, $hm)) {
            $handoverChecked = json_decode(trim($hm[1]), true) ?: [];
        }
        // Tanda tangan serah terima: parse suffix [SIG:path] dari log 'diserahkan' terakhir.
        $handoverLog = ServiceLog::where('service_ticket_id', $service->id)
            ->where('to_status', 'diserahkan')
            ->where('note', 'like', '%[SIG:%')
            ->latest()->first();
        $handoverSignature = $handoverLog && preg_match('/\[SIG:([^\]]+)\]/', (string) $handoverLog->note, $mm) ? trim($mm[1]) : null;
        // Daftar toko aktif untuk aksi pindah toko (tanpa migration/route baru).
        $stores = Store::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        return view('services.show', compact('service','technicians','sparepartProducts','cashAccounts','allowedNext','waPhone','sisa','waApproveText','history','qcItems','qcChecked','handoverLog','handoverSignature','handoverItems','handoverChecked','stores'));
    }

    public function edit(ServiceTicket $service)
    {
        $this->authorizeStore($service);
        $technicians = LookupCache::technicians();
        $customers = Customer::where('store_id', $service->store_id)->orderBy('name')->limit(200)->get();
        return view('services.edit', compact('service','technicians','customers'));
    }

    public function update(Request $request, ServiceTicket $service)
    {
        $this->authorizeStore($service);
        $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'technician_id' => 'nullable|exists:users,id',
            'product_brand' => 'required|max:50',
            'product_type' => 'required|max:100',
            'imei' => 'nullable|max:30',
            'complaint' => 'required',
            'condition_note' => 'nullable',
            'priority' => 'required|in:rendah,normal,mendesak',
            'diagnosis' => 'nullable',
            'estimate_cost' => 'nullable|numeric|min:0',
            'warranty_days' => 'nullable|integer|min:0|max:365',
            'estimated_done_at' => 'nullable|date',
            'photos.*' => 'nullable|image|max:2048',
        ]);
        $service->fill($request->only(['customer_id','technician_id','product_brand','product_type','imei','complaint','condition_note','priority','diagnosis','warranty_days','estimated_done_at']));
        if ($request->filled('estimate_cost')) $service->estimate_cost = $request->estimate_cost;
        // Validasi DP: total prospektif (jasa + sparepart) tidak boleh di bawah DP yang sudah masuk.
        $prospectiveTotal = (float) $service->estimate_cost + (float) $service->spareparts()->sum('subtotal');
        if ((float) $service->dp - $prospectiveTotal > 0.5) {
            return back()->with('error', 'Estimasi tidak bisa diturunkan: DP Rp ' . number_format((float) $service->dp, 0, ',', '.') . ' melebihi total baru Rp ' . number_format($prospectiveTotal, 0, ',', '.') . '.')->withInput();
        }
        if ($request->hasFile('photos')) {
            $photos = $service->photos ?? [];
            foreach ($request->file('photos') as $f) {
                if (count($photos) >= 4) break;
                $photos[] = $f->store('service', 'public');
            }
            $service->photos = $photos;
        }
        $service->save();
        $this->recalcTotal($service);
        return redirect()->route('services.show', $service)->with('success', 'Tiket berhasil diperbarui.');
    }

    public function destroy(ServiceTicket $service)
    {
        $this->authorizeStore($service);
        // Tiket selesai lama = pelunasan sudah tercatat di kas & garansi/komisi terbit.
        // Pola SaleController@destroy: blokir, jangan hapus diam-diam.
        abort_if($service->status === 'diserahkan' && $service->created_at && $service->created_at->lt(now()->subDay()), 422, 'Tiket yang sudah diserahkan lama tidak bisa dihapus (pelunasan tercatat di kas).');
        // Hapus = koreksi total: kembalikan stok sparepart, bersihkan kas & garansi,
        // lepas tautan booking dua arah, hapus file, baru hapus tiket (sparepart/log/
        // komisi ikut terhapus via cascadeOnDelete — seperti SaleController@destroy).
        \DB::transaction(function () use ($service) {
            $service->loadMissing('spareparts');
            foreach ($service->spareparts as $sp) {
                $st = ProductStock::where('product_id', $sp->product_id)
                    ->where('store_id', $service->store_id)->lockForUpdate()->first();
                if ($st) {
                    $st->increment('qty', (int) $sp->qty);
                } else {
                    ProductStock::create(['product_id' => $sp->product_id, 'store_id' => $service->store_id, 'qty' => (int) $sp->qty]);
                }
            }
            StockMovement::where('ref_type', 'service_ticket')->where('ref_id', $service->id)->delete();
            CashTransaction::where('ref_type', 'service_ticket')->where('ref_id', $service->id)->delete();
            Warranty::where('ref_type', 'service')->where('ref_id', $service->id)->delete();
            // Kolom booking_id / ticket_id tanpa FK: bersihkan dua arah agar tidak yatim.
            try {
                if ($service->booking_id) Booking::whereKey($service->booking_id)->update(['ticket_id' => null]);
                Booking::where('ticket_id', $service->id)->update(['ticket_id' => null]);
            } catch (\Throwable $e) { report($e); }
            // Hapus file foto + file tanda tangan serah terima ([SIG:path] di service_logs).
            foreach (($service->photos ?? []) as $p) {
                try { Storage::disk('public')->delete($p); } catch (\Throwable $e) {}
            }
            try {
                $handover = ServiceLog::where('service_ticket_id', $service->id)
                    ->where('to_status', 'diserahkan')
                    ->where('note', 'like', '%[SIG:%')->latest()->first();
                if ($handover && preg_match('/\[SIG:([^\]]+)\]/', (string) $handover->note, $mm)) {
                    Storage::disk('public')->delete(trim($mm[1]));
                }
            } catch (\Throwable $e) {}
            $service->delete();
        });
        // Sinkron saldo akun kas yang terkena (hitung ulang dari mutasi — pola SaleController@destroy).
        try {
            foreach (CashAccount::where('store_id', $service->store_id)->get() as $acc) {
                $masuk = CashTransaction::where('cash_account_id', $acc->id)->where('type', 'masuk')->sum('amount');
                $keluar = CashTransaction::where('cash_account_id', $acc->id)->where('type', 'keluar')->sum('amount');
                $acc->update(['balance' => $masuk - $keluar]);
            }
        } catch (\Throwable $e) { report($e); }
        return redirect()->route('services.index')->with('success', 'Tiket dihapus + stok sparepart/kas/garansi dikembalikan.');
    }

    public function updateStatus(Request $request, ServiceTicket $service)
    {
        $this->authorizeStore($service);
        $request->validate([
            'to_status' => 'required|string',
            'note' => 'nullable|string|max:500',
            'signature' => 'nullable|string|max:2000000',
            'diagnosis' => 'nullable|string',
            'estimate_cost' => 'nullable|numeric|min:0',
            'approval_status' => 'nullable|in:menunggu,disetujui,ditolak',
            'payment' => 'nullable|numeric|min:0',
            'technician_id' => 'nullable|exists:users,id',
            'estimated_done_at' => 'nullable|date',
            'qc' => 'nullable|array',
            'handover' => 'nullable|array',
            'notify_tech' => 'nullable|boolean',
            'after_photos' => 'nullable|array|max:2',
            'after_photos.*' => 'nullable|image|max:2048',
        ], [
            'after_photos.max' => 'Maksimal 2 foto sesudah.',
            'after_photos.*.image' => 'Foto sesudah harus berupa gambar.',
            'after_photos.*.max' => 'Ukuran tiap foto sesudah maksimal 2 MB.',
        ]);

        $from = $service->status;
        $to = $request->to_status;
        $allowed = $this->nextMap[$from] ?? [];
        if (!in_array($to, $allowed)) {
            return back()->with('error', "Transisi $from → $to tidak diizinkan.");
        }

        // Tanda tangan serah terima: wajib bila ke 'diserahkan' (batal dikecualikan).
        $sigPath = null;
        if ($to === 'diserahkan') {
            $sig = trim((string) $request->input('signature', ''));
            if ($sig === '' || !str_starts_with($sig, 'data:image/png;base64,')) {
                return back()->with('error', 'Serah terima wajib disertai tanda tangan pelanggan.')->withInput();
            }
            $b64 = substr($sig, strlen('data:image/png;base64,'));
            $bin = base64_decode($b64, true);
            if ($bin === false || strlen($bin) < 100 || strlen($bin) > 2097152 || substr($bin, 0, 8) !== "\x89PNG\r\n\x1a\n") {
                return back()->with('error', 'Tanda tangan tidak valid (PNG dataURL rusak).')->withInput();
            }
            $safe = preg_replace('/[^A-Za-z0-9-_]/', '_', (string) $service->ticket_no);
            if ($safe === '' || $safe === null) $safe = 'tiket-' . $service->id;
            $sigPath = 'signatures/' . $safe . '.png';
        }

        // QC wajib lengkap sebelum siap_diambil
        $qcItems = ['Fungsi nyala', 'Sinyal', 'Kamera', 'Charging', 'Tombol', 'Speaker'];
        if ($to === 'siap_diambil') {
            $qc = (array) $request->input('qc', []);
            $missing = array_diff($qcItems, $qc);
            if (!empty($missing)) {
                return back()->with('error', 'QC belum lengkap, centang semua: ' . implode(', ', $missing) . '.');
            }
            // Foto sesudah WAJIB (min 1) saat QC lolos → Siap Diambil.
            // Dilewati bila tiket sudah punya foto after dari QC sebelumnya (path service/after/*).
            $hasNewAfter = false;
            if ($request->hasFile('after_photos')) {
                foreach ((array) $request->file('after_photos') as $f) {
                    if ($f && $f->isValid()) { $hasNewAfter = true; break; }
                }
            }
            $hasExistingAfter = collect((array) ($service->photos ?? []))
                ->contains(fn($p) => is_string($p) && str_contains($p, 'after/'));
            if (!$hasNewAfter && !$hasExistingAfter) {
                return back()->with('error', 'Foto sesudah wajib diisi (minimal 1 foto) sebagai bukti hasil servis sebelum tiket Siap Diambil.')->withInput();
            }
        }

        // Checklist serah terima: wajib lengkap bila ke 'diserahkan' (batal dikecualikan).
        // Pola validasi sama dengan QC di atas; tanda tangan tetap wajib (blok di atas).
        $handoverItems = ['HP sudah dites nyala', 'Nota/struk diserahkan', 'Garansi dijelaskan'];
        if ($to === 'diserahkan') {
            $handover = (array) $request->input('handover', []);
            $handoverMissing = array_diff($handoverItems, $handover);
            if (!empty($handoverMissing)) {
                return back()
                    ->with('error', 'Serah terima belum lengkap, centang semua: ' . implode(', ', $handoverMissing) . '.')
                    ->withInput();
            }
        }

        DB::beginTransaction();
        try {
            if ($request->filled('technician_id')) $service->technician_id = $request->technician_id;
            if ($request->filled('diagnosis')) $service->diagnosis = $request->diagnosis;
            if ($request->filled('estimate_cost')) $service->estimate_cost = $request->estimate_cost;
            if ($request->filled('estimated_done_at')) $service->estimated_done_at = $request->estimated_done_at;
            if ($request->filled('approval_status')) {
                $service->approval_status = $request->approval_status;
            } else {
                if ($to === 'menunggu_persetujuan') $service->approval_status = 'menunggu';
                if ($to === 'disetujui') $service->approval_status = 'disetujui';
                if ($to === 'batal' && $from === 'menunggu_persetujuan') $service->approval_status = 'ditolak';
            }

            // DP / pelunasan parsial sebelum serah terima — total disegarkan dulu agar DP ≤ total.
            if ($request->filled('payment') && (float) $request->payment > 0 && $to !== 'diserahkan') {
                $service->total_cost = (float) $service->estimate_cost + (float) $service->spareparts()->sum('subtotal');
                $pay = (float) $request->payment;
                $sisaBayar = (float) $service->total_cost - (float) $service->dp;
                if ($pay - $sisaBayar > 0.5) {
                    DB::rollBack();
                    return back()->with('error', 'Pembayaran Rp ' . number_format($pay, 0, ',', '.') . ' melebihi sisa tagihan Rp ' . number_format(max(0, $sisaBayar), 0, ',', '.') . ' (total Rp ' . number_format((float) $service->total_cost, 0, ',', '.') . ', DP masuk Rp ' . number_format((float) $service->dp, 0, ',', '.') . ').');
                }
                $service->dp = (float) $service->dp + $pay;
                $this->recordCash($service->store_id, 'masuk', 'service', $pay, 'service_ticket', $service->id, "DP service {$service->ticket_no}");
            }

            $service->status = $to;

            // Foto sesudah QC (maks 2 per submit): append ke photos, path service/after/*.
            if ($request->hasFile('after_photos')) {
                $existing = $service->photos ?? [];
                if (!is_array($existing)) $existing = (array) $existing;
                foreach ((array) $request->file('after_photos') as $f) {
                    if (!$f || !$f->isValid()) continue;
                    $existing[] = $f->store('service/after', 'public');
                }
                $service->photos = array_values($existing);
            }

            if ($to === 'diserahkan') {
                $this->recalcTotal($service);
                $sisa = max(0, (float) $service->total_cost - (float) $service->dp);
                if ($sisa > 0) {
                    $this->recordCash($service->store_id, 'masuk', 'service', $sisa, 'service_ticket', $service->id, "Pelunasan service {$service->ticket_no}");
                    $service->dp = (float) $service->dp + $sisa;
                }
                $service->taken_at = now();
                // Komisi teknisi: % dari jasa (estimate_cost), default 10%
                if ($service->technician_id && !TechnicianCommission::where('service_ticket_id', $service->id)->exists()) {
                    $tech = User::find($service->technician_id);
                    $rate = (float) ($tech->commission_rate ?? 10);
                    $amount = (float) $service->estimate_cost * $rate / 100;
                    TechnicianCommission::create([
                        'service_ticket_id' => $service->id,
                        'technician_id' => $service->technician_id,
                        'amount' => $amount,
                        'status' => 'menunggu',
                    ]);
                }
                // Garansi otomatis
                $days = (int) ($service->warranty_days ?? 7);
                if ($days > 0 && !Warranty::where('ref_type', 'service')->where('ref_id', $service->id)->exists()) {
                    Warranty::create([
                        'ref_type' => 'service',
                        'ref_id' => $service->id,
                        'customer_id' => $service->customer_id,
                        'product_item_id' => null,
                        'start_date' => now()->toDateString(),
                        'end_date' => now()->addDays($days)->toDateString(),
                        'type' => 'service',
                        'status' => 'aktif',
                    ]);
                }
            }

            $service->save();
            $this->recalcTotal($service->fresh());

            // Simpan checklist QC sebagai log khusus (tanpa migration)
            if ($to === 'siap_diambil' && $request->has('qc')) {
                ServiceLog::create([
                    'service_ticket_id' => $service->id,
                    'user_id' => auth()->id(),
                    'from_status' => 'qc',
                    'to_status' => 'qc',
                    'note' => 'QC-CHECK:' . json_encode(array_values((array) $request->input('qc'))),
                ]);
            }

            // Simpan checklist serah terima sebagai log khusus (tanpa migration).
            // Pola sama dengan QC-CHECK di atas; dibaca kembali di show().
            if ($to === 'diserahkan') {
                ServiceLog::create([
                    'service_ticket_id' => $service->id,
                    'user_id' => auth()->id(),
                    'from_status' => $from,
                    'to_status' => $from,
                    'note' => 'HANDOVER-CHECK:' . json_encode(array_values((array) $request->input('handover', []))),
                ]);
            }

            // Simpan file tanda tangan (di dalam transaksi agar gagal total bila disk error).
            $handoverNote = $request->note;
            if ($to === 'diserahkan' && $sigPath) {
                try {
                    Storage::disk('public')->put($sigPath, base64_decode(substr(trim((string) $request->input('signature', '')), strlen('data:image/png;base64,'))));
                } catch (\Throwable $e) {
                    DB::rollBack();
                    return back()->with('error', 'Gagal menyimpan tanda tangan: ' . $e->getMessage())->withInput();
                }
                $base = trim((string) ($request->note ?? ''));
                if ($base === '') $base = 'HP diserahkan ke pelanggan.';
                $handoverNote = $base . ' [SIG:' . $sigPath . ']';
            }

            ServiceLog::create([
                'service_ticket_id' => $service->id,
                'user_id' => auth()->id(),
                'from_status' => $from,
                'to_status' => $to,
                'note' => $handoverNote,
            ]);

            // Auto-link: tiket dari booking yang diserahkan → booking selesai
            if ($to === 'diserahkan' && $service->booking_id) {
                try {
                    $bk = Booking::find($service->booking_id);
                    if ($bk && $bk->status !== 'selesai') {
                        $bk->status = 'selesai';
                        $bk->save();
                    }
                } catch (\Throwable $e) {}
            }

            // WA otomatis (pakai template bila ada, fallback ke string lama)
            try {
                $phone = $service->customer->phone ?? '';
                $nama = $service->customer->name ?? 'Pelanggan';
                if ($to === 'siap_diambil') {
                    $fresh = $service->fresh();
                    $sisa = max(0, (float) $fresh->total_cost - (float) $fresh->dp);
                    $total = number_format($fresh->total_cost, 0, ',', '.');
                    $sisaFmt = number_format($sisa, 0, ',', '.');
                    $msg = WaService::render('service_siap_diambil', [
                        'nama' => $nama, 'tiket' => $service->ticket_no, 'status' => $to,
                        'total' => $total, 'sisa' => $sisaFmt, 'track' => url('/track'),
                    ]);
                    if ($msg === '') {
                        $msg = "Halo $nama, HP Anda tiket *{$service->ticket_no}* sudah SIAP DIAMBIL. Total Rp $total, sisa bayar Rp $sisaFmt. " . url('/track');
                    }
                    WaService::send($phone, $msg, 'service_status', $service->store_id);
                } else {
                    WaService::serviceStatus($phone, $nama, $service->ticket_no, $to);
                }
            } catch (\Throwable $e) {}

            // Notif tambahan OPT-IN ke teknisi: hanya bila checkbox dicentang.
            // Guard: tanpa nomor HP teknisi = lewati diam-diam (tanpa error, tanpa migration).
            if ($request->boolean('notify_tech')) {
                try {
                    $freshTech = $service->fresh(['technician']);
                    $tech = $freshTech->technician;
                    $techPhone = WaService::normalize((string) ($tech->phone ?? ''));
                    if ($techPhone !== '') {
                        $techName = $tech->name ?? 'Teknisi';
                        $prio = (string) ($freshTech->priority ?? 'normal');
                        $device = trim(($freshTech->product_brand ?? '') . ' ' . ($freshTech->product_type ?? ''));
                        $msgTech = "Halo {$techName}, ringkasan service tiket *{$service->ticket_no}*"
                            . " ({$device}): status baru *{$to}*, prioritas *{$prio}*.";
                        WaService::send($techPhone, $msgTech, 'service_status_teknisi', $service->store_id);
                    }
                } catch (\Throwable $e) {}
            }

            DB::commit();
            return back()->with('success', "Status tiket → $to.");
        } catch (\Throwable $e) {
            DB::rollBack();
            return back()->with('error', 'Gagal update status: ' . $e->getMessage());
        }
    }

    public function updatePriority(Request $request, ServiceTicket $service)
    {
        $this->authorizeStore($service);
        if (in_array($service->status, ['diserahkan', 'batal'])) {
            return back()->with('error', 'Prioritas tiket yang sudah diserahkan/batal tidak bisa diubah.');
        }
        $request->validate([
            'priority' => 'required|in:rendah,normal,mendesak',
        ]);
        $old = (string) ($service->priority ?? 'normal');
        $service->priority = $request->priority;
        $service->save();
        if ($old !== $service->priority) {
            ServiceLog::create([
                'service_ticket_id' => $service->id,
                'user_id' => auth()->id(),
                'from_status' => $service->status,
                'to_status' => $service->status,
                'note' => "Prioritas diubah {$old} → {$service->priority}.",
            ]);
        }
        return back()->with('success', "Prioritas tiket {$service->ticket_no} → {$service->priority}.");
    }

    /**
     * Set prioritas massal untuk tiket terpilih (maks 50):
     * validasi priority + ids, hanya tiket AKTIF (final diserahkan/batal dilewati),
     * catat ServiceLog per tiket yang berubah (cermin updatePriority), lapor per baris.
     */
    public function bulkPriority(Request $request)
    {
        $storeId = $this->storeId();
        $data = $request->validate([
            'ticket_ids' => 'required|array|min:1|max:50',
            'ticket_ids.*' => 'integer|exists:service_tickets,id',
            'priority' => 'required|in:rendah,normal,mendesak',
        ], [
            'ticket_ids.required' => 'Pilih minimal 1 tiket aktif.',
            'ticket_ids.min' => 'Pilih minimal 1 tiket aktif.',
            'ticket_ids.max' => 'Maksimal 50 tiket per set prioritas massal.',
            'priority.required' => 'Pilih prioritas tujuan dulu.',
            'priority.in' => 'Prioritas tidak valid.',
        ]);

        $target = (string) $data['priority'];
        $ids = array_values(array_unique(array_map('intval', $data['ticket_ids'])));
        $rows = ServiceTicket::where('store_id', $storeId)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $okLines = [];
        $skipLines = [];
        $failLines = [];

        foreach ($ids as $id) {
            $t = $rows->get($id);
            if (!$t) {
                $failLines[] = "#$id: bukan tiket toko aktif";
                continue;
            }
            // Hanya tiket aktif (final diserahkan/batal dikunci — cermin updatePriority).
            if (in_array($t->status, ['diserahkan', 'batal'])) {
                $skipLines[] = "$t->ticket_no: dilewati (status final $t->status)";
                continue;
            }
            $old = (string) ($t->priority ?? 'normal');
            if ($old === $target) {
                $skipLines[] = "$t->ticket_no: dilewati (sudah $target)";
                continue;
            }
            try {
                $t->priority = $target;
                $t->save();
                ServiceLog::create([
                    'service_ticket_id' => $t->id,
                    'user_id' => auth()->id(),
                    'from_status' => $t->status,
                    'to_status' => $t->status,
                    'note' => "Prioritas diubah {$old} → {$target} (massal).",
                ]);
                $okLines[] = "$t->ticket_no: $old → $target";
            } catch (\Throwable $e) {
                report($e);
                $failLines[] = "$t->ticket_no: gagal ({$e->getMessage()})";
            }
        }

        $summary = 'Set prioritas massal → ' . $target . ': ' . count($okLines) . ' diubah, '
            . count($skipLines) . ' dilewati (final/sudah sama), '
            . count($failLines) . ' gagal.';
        $detail = array_merge($okLines, $skipLines, $failLines);
        if ($detail) $summary .= ' | ' . implode('; ', $detail);

        if (count($okLines) === 0 && count($failLines) > 0) {
            return back()->with('error', $summary);
        }
        return back()->with('success', $summary);
    }

    /**
     * Set teknisi massal untuk tiket terpilih (maks 50, rebalance beban):
     * validasi technician_id + ids, hanya tiket AKTIF (final diserahkan/batal dilewati),
     * yang teknisinya sudah sama dilewati, catat ServiceLog per tiket yang berubah,
     * lapor per baris. Tanpa migration.
     */
    public function bulkTechnician(Request $request)
    {
        $storeId = $this->storeId();
        $data = $request->validate([
            'ticket_ids' => 'required|array|min:1|max:50',
            'ticket_ids.*' => 'integer|exists:service_tickets,id',
            'technician_id' => 'required|integer|exists:users,id',
        ], [
            'ticket_ids.required' => 'Pilih minimal 1 tiket aktif.',
            'ticket_ids.min' => 'Pilih minimal 1 tiket aktif.',
            'ticket_ids.max' => 'Maksimal 50 tiket per set teknisi massal.',
            'technician_id.required' => 'Pilih teknisi tujuan dulu.',
            'technician_id.exists' => 'Teknisi tujuan tidak ditemukan.',
        ]);

        $tech = User::whereKey((int) $data['technician_id'])
            ->where('role', 'teknisi')
            ->where('is_active', true)
            ->first();
        if (!$tech) {
            return back()->with('error', 'Teknisi tujuan tidak aktif / bukan role teknisi.');
        }

        $ids = array_values(array_unique(array_map('intval', $data['ticket_ids'])));
        $rows = ServiceTicket::with('technician')
            ->where('store_id', $storeId)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $okLines = [];
        $skipLines = [];
        $failLines = [];

        foreach ($ids as $id) {
            $t = $rows->get($id);
            if (!$t) {
                $failLines[] = "#$id: bukan tiket toko aktif";
                continue;
            }
            // Hanya tiket aktif (final diserahkan/batal dikunci — cermin bulkPriority).
            if (in_array($t->status, ['diserahkan', 'batal'])) {
                $skipLines[] = "$t->ticket_no: dilewati (status final $t->status)";
                continue;
            }
            if ((int) ($t->technician_id ?? 0) === (int) $tech->id) {
                $skipLines[] = "$t->ticket_no: dilewati (sudah ditangani {$tech->name})";
                continue;
            }
            $old = $t->technician->name ?? '-';
            try {
                $t->technician_id = (int) $tech->id;
                $t->save();
                ServiceLog::create([
                    'service_ticket_id' => $t->id,
                    'user_id' => auth()->id(),
                    'from_status' => $t->status,
                    'to_status' => $t->status,
                    'note' => "Teknisi diubah {$old} → {$tech->name} (massal).",
                ]);
                $okLines[] = "$t->ticket_no: $old → {$tech->name}";
            } catch (\Throwable $e) {
                report($e);
                $failLines[] = "$t->ticket_no: gagal ({$e->getMessage()})";
            }
        }

        $summary = 'Set teknisi massal → ' . $tech->name . ': ' . count($okLines) . ' diubah, '
            . count($skipLines) . ' dilewati (final/sudah sama), '
            . count($failLines) . ' gagal.';
        $detail = array_merge($okLines, $skipLines, $failLines);
        if ($detail) $summary .= ' | ' . implode('; ', $detail);

        if (count($okLines) === 0 && count($failLines) > 0) {
            return back()->with('error', $summary);
        }
        return back()->with('success', $summary);
    }

    /**
     * Geser/set estimasi massal untuk tiket terpilih (maks 50):
     * mode shift = estimated_done_at +N/-N hari (basis: estimasi lama
     * atau created_at bila kosong), mode set = samakan ke tanggal
     * tertentu. Hanya tiket AKTIF (final diserahkan/batal dilewati),
     * catat ServiceLog per tiket yang berubah, lapor per baris.
     * Tanpa migration.
     */
    public function bulkEstimasi(Request $request)
    {
        $storeId = $this->storeId();
        $data = $request->validate([
            'ticket_ids' => 'required|array|min:1|max:50',
            'ticket_ids.*' => 'integer|exists:service_tickets,id',
            'mode' => 'required|in:shift,set',
            'shift_days' => 'required_if:mode,shift|nullable|integer|min:-30|max:30|not_in:0',
            'set_date' => 'required_if:mode,set|nullable|date',
        ], [
            'ticket_ids.required' => 'Pilih minimal 1 tiket aktif.',
            'ticket_ids.min' => 'Pilih minimal 1 tiket aktif.',
            'ticket_ids.max' => 'Maksimal 50 tiket per geser estimasi massal.',
            'mode.required' => 'Pilih mode geser (±N hari) atau set tanggal.',
            'mode.in' => 'Mode estimasi tidak valid.',
            'shift_days.required_if' => 'Isi jumlah hari geser (±1..30, 0 tidak boleh).',
            'shift_days.integer' => 'Jumlah hari geser harus bilangan bulat.',
            'shift_days.min' => 'Geser maksimal 30 hari ke belakang.',
            'shift_days.max' => 'Geser maksimal 30 hari ke depan.',
            'shift_days.not_in' => 'Geser 0 hari tidak mengubah apa-apa.',
            'set_date.required_if' => 'Isi tanggal estimasi tujuan dulu.',
            'set_date.date' => 'Tanggal estimasi tidak valid.',
        ]);

        $mode = (string) $data['mode'];
        $shift = $mode === 'shift' ? (int) $data['shift_days'] : 0;
        $setDate = $mode === 'set' ? \Carbon\Carbon::parse($data['set_date']) : null;

        $ids = array_values(array_unique(array_map('intval', $data['ticket_ids'])));
        $rows = ServiceTicket::where('store_id', $storeId)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $okLines = [];
        $skipLines = [];
        $failLines = [];

        foreach ($ids as $id) {
            $t = $rows->get($id);
            if (!$t) {
                $failLines[] = "#$id: bukan tiket toko aktif";
                continue;
            }
            // Hanya tiket aktif (final diserahkan/batal dikunci — cermin bulkPriority).
            if (in_array($t->status, ['diserahkan', 'batal'])) {
                $skipLines[] = "$t->ticket_no: dilewati (status final $t->status)";
                continue;
            }
            try {
                if ($mode === 'shift') {
                    $base = $t->estimated_done_at ?? $t->created_at ?? now();
                    $new = (clone $base)->addDays($shift);
                    $label = 'geser ' . ($shift > 0 ? '+' . $shift : (string) $shift) . ' hari';
                } else {
                    $new = (clone $setDate);
                    // Tanggal yang sama (per hari) = tidak ada perubahan.
                    if ($t->estimated_done_at && $t->estimated_done_at->toDateString() === $new->toDateString()) {
                        $skipLines[] = "$t->ticket_no: dilewati (sudah " . $new->format('d/m/Y') . ')';
                        continue;
                    }
                    $label = 'set tanggal ' . $new->format('d/m/Y');
                }
                $oldFmt = $t->estimated_done_at ? $t->estimated_done_at->format('d/m/Y H:i') : '-';
                $newFmt = $new->format('d/m/Y H:i');
                $t->estimated_done_at = $new;
                $t->save();
                ServiceLog::create([
                    'service_ticket_id' => $t->id,
                    'user_id' => auth()->id(),
                    'from_status' => $t->status,
                    'to_status' => $t->status,
                    'note' => "Estimasi diubah {$oldFmt} → {$newFmt} (massal, {$label}).",
                ]);
                $okLines[] = "$t->ticket_no: $oldFmt → $newFmt";
            } catch (\Throwable $e) {
                report($e);
                $failLines[] = "$t->ticket_no: gagal ({$e->getMessage()})";
            }
        }

        $summary = 'Geser estimasi massal (' . ($mode === 'shift'
                ? 'geser ' . ($shift > 0 ? '+' . $shift : (string) $shift) . ' hari'
                : 'set ' . $setDate->format('d/m/Y')) . '): '
            . count($okLines) . ' diubah, '
            . count($skipLines) . ' dilewati (final/sudah sama), '
            . count($failLines) . ' gagal.';
        $detail = array_merge($okLines, $skipLines, $failLines);
        if ($detail) $summary .= ' | ' . implode('; ', $detail);

        if (count($okLines) === 0 && count($failLines) > 0) {
            return back()->with('error', $summary);
        }
        return back()->with('success', $summary);
    }

    /**
     * Pindah tiket antar toko (cermin pindah unit di StockController@mutate):
     * update store_id + catat ServiceLog, tanpa WA, tanpa ubah status.
     * Hanya tiket AKTIF (tolak diserahkan/batal — kas/garansi/komisi final).
     */
    public function transferStore(Request $request, ServiceTicket $service)
    {
        $this->authorizeStore($service);
        if (in_array($service->status, ['diserahkan', 'batal'])) {
            return back()->with('error', 'Tiket yang sudah diserahkan/batal tidak bisa dipindah toko.');
        }
        $data = $request->validate([
            'to_store_id' => 'required|integer|exists:stores,id',
            'note' => 'nullable|string|max:500',
        ], [
            'to_store_id.required' => 'Pilih toko tujuan dulu.',
            'to_store_id.exists' => 'Toko tujuan tidak ditemukan.',
        ]);
        $to = (int) $data['to_store_id'];
        if ($to === (int) $service->store_id) {
            return back()->with('error', 'Toko tujuan sama dengan toko asal.');
        }
        $dest = Store::whereKey($to)->where('is_active', true)->first();
        if (!$dest) {
            return back()->with('error', 'Toko tujuan tidak aktif.');
        }

        DB::transaction(function () use ($service, $dest, $data) {
            $ticket = ServiceTicket::whereKey($service->id)->lockForUpdate()->firstOrFail();
            if (in_array($ticket->status, ['diserahkan', 'batal'])) {
                abort(422, 'Tiket sudah final, tidak bisa dipindah toko.');
            }
            if ((int) $ticket->store_id === (int) $dest->id) {
                abort(422, 'Toko tujuan sama dengan toko asal.');
            }
            $fromStore = $ticket->store ? $ticket->store->name : ('Toko #' . $ticket->store_id);
            $ticket->store_id = (int) $dest->id;
            $ticket->save();

            $extra = trim((string) ($data['note'] ?? ''));
            ServiceLog::create([
                'service_ticket_id' => $ticket->id,
                'user_id' => auth()->id(),
                'from_status' => $ticket->status,
                'to_status' => $ticket->status,
                'note' => 'Pindah toko ' . $fromStore . ' → ' . $dest->name . '.'
                    . ($extra !== '' ? ' ' . $extra : ''),
            ]);
        });

        // Tiket kini milik toko tujuan → kembali ke index toko asal (authorizeStore
        // akan 403 bila tetap membuka show dari sesi toko lama).
        return redirect()
            ->route('services.index')
            ->with('success', 'Tiket ' . $service->ticket_no . ' dipindah ke ' . $dest->name . '.');
    }

    /**
     * Pindah massal tiket antar toko (cermin transferStore satuan):
     * update store_id + catat ServiceLog per tiket, tanpa ubah status.
     * Hanya tiket AKTIF — baris diserahkan/batal dilewati, hasil lapor per baris.
     * Maks 50 per submit (validasi), tanpa migration.
     */
    public function bulkTransferStore(Request $request)
    {
        $storeId = $this->storeId();
        $data = $request->validate([
            'ticket_ids' => 'required|array|min:1|max:50',
            'ticket_ids.*' => 'integer|exists:service_tickets,id',
            'to_store_id' => 'required|integer|exists:stores,id',
        ], [
            'ticket_ids.required' => 'Pilih minimal 1 tiket aktif.',
            'ticket_ids.min' => 'Pilih minimal 1 tiket aktif.',
            'ticket_ids.max' => 'Maksimal 50 tiket per pindah massal.',
            'to_store_id.required' => 'Pilih toko tujuan dulu.',
            'to_store_id.exists' => 'Toko tujuan tidak ditemukan.',
        ]);

        $dest = Store::whereKey((int) $data['to_store_id'])->where('is_active', true)->first();
        if (!$dest) {
            return back()->with('error', 'Toko tujuan tidak aktif.');
        }
        if ($storeId && (int) $storeId === (int) $dest->id) {
            return back()->with('error', 'Toko tujuan sama dengan toko asal.');
        }

        $ids = array_values(array_unique(array_map('intval', $data['ticket_ids'])));
        $rows = ServiceTicket::with('store')
            ->where('store_id', $storeId)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $okLines = [];
        $skipLines = [];
        $failLines = [];

        foreach ($ids as $id) {
            $t = $rows->get($id);
            if (!$t) {
                $failLines[] = "#$id: bukan tiket toko aktif";
                continue;
            }
            // Hanya tiket aktif yang dipindah (final diserahkan/batal dilewati).
            if (in_array($t->status, ['diserahkan', 'batal'])) {
                $skipLines[] = "$t->ticket_no: dilewati (status final $t->status)";
                continue;
            }
            try {
                DB::transaction(function () use ($t, $dest) {
                    $ticket = ServiceTicket::with('store')->whereKey($t->id)->lockForUpdate()->firstOrFail();
                    if (in_array($ticket->status, ['diserahkan', 'batal'])) {
                        abort(422, 'Tiket sudah final, tidak bisa dipindah toko.');
                    }
                    if ((int) $ticket->store_id === (int) $dest->id) {
                        abort(422, 'Toko tujuan sama dengan toko asal.');
                    }
                    // Pakai relasi store yang sudah di-eager-load ($t->store) bila
                    // tersedia agar tidak lazy-load per baris (N+1, maks 50/blok).
                    $fromStore = ($t->relationLoaded('store') && $t->store)
                        ? $t->store->name
                        : ($ticket->relationLoaded('store') && $ticket->store ? $ticket->store->name : ('Toko #' . $ticket->store_id));
                    $ticket->store_id = (int) $dest->id;
                    $ticket->save();

                    ServiceLog::create([
                        'service_ticket_id' => $ticket->id,
                        'user_id' => auth()->id(),
                        'from_status' => $ticket->status,
                        'to_status' => $ticket->status,
                        'note' => 'Pindah toko ' . $fromStore . ' → ' . $dest->name . '. (massal)',
                    ]);
                });
                $okLines[] = "$t->ticket_no → {$dest->name}";
            } catch (\Throwable $e) {
                report($e);
                $failLines[] = "$t->ticket_no: gagal ({$e->getMessage()})";
            }
        }

        $summary = 'Pindah toko massal ke ' . $dest->name . ': ' . count($okLines) . ' berhasil, '
            . count($skipLines) . ' dilewati (final diserahkan/batal), '
            . count($failLines) . ' gagal.';
        $detail = array_merge($okLines, $skipLines, $failLines);
        if ($detail) $summary .= ' | ' . implode('; ', $detail);

        if (count($okLines) === 0 && count($failLines) > 0) {
            return back()->with('error', $summary);
        }
        return back()->with('success', $summary);
    }

    public function addSparepart(Request $request, ServiceTicket $service)
    {
        $this->authorizeStore($service);
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'qty' => 'required|integer|min:1|max:100',
            'price' => 'nullable|numeric|min:0|max:100000000',
        ], [
            'product_id.required' => 'Pilih sparepart dulu.',
            'product_id.exists' => 'Sparepart tidak ditemukan.',
            'qty.required' => 'Qty wajib diisi.',
            'qty.min' => 'Qty minimal 1.',
            'qty.max' => 'Qty maksimal 100 per penambahan.',
            'price.numeric' => 'Harga harus berupa angka.',
            'price.min' => 'Harga tidak boleh negatif.',
            'price.max' => 'Harga terlalu besar.',
        ]);
        if (in_array($service->status, ['diserahkan','batal'])) {
            return back()->with('error', 'Tiket sudah selesai, sparepart tidak bisa ditambah.');
        }
        DB::beginTransaction();
        try {
            $product = Product::findOrFail($request->product_id);
            $stock = ProductStock::where('store_id', $service->store_id)->where('product_id', $product->id)->lockForUpdate()->first();
            $available = $stock ? (int) $stock->qty : 0;
            if ($available < $request->qty) {
                return back()->with('error', "Stok {$product->name} kurang (sisa $available).");
            }
            $stock->qty = $available - $request->qty;
            $stock->save();
            StockMovement::create([
                'store_id' => $service->store_id,
                'product_id' => $product->id,
                'type' => 'pakai_service',
                'qty' => -$request->qty,
                'ref_type' => 'service_ticket',
                'ref_id' => $service->id,
                'note' => "Pakai service {$service->ticket_no}",
                'user_id' => auth()->id(),
            ]);
            // Harga default = harga jual master produk; boleh override manual via input.
            // Riwayat lama TIDAK auto-ubah bila master berubah — selisih hanya
            // ditampilkan sebagai peringatan di list (blade).
            $masterPrice = (float) $product->selling_price;
            $price = $request->filled('price') ? (float) $request->input('price') : $masterPrice;
            ServiceSparepart::create([
                'service_ticket_id' => $service->id,
                'product_id' => $product->id,
                'qty' => $request->qty,
                'price' => $price,
                'subtotal' => $price * $request->qty,
            ]);
            $this->recalcTotal($service->fresh());
            DB::commit();
            if (abs($price - $masterPrice) > 0.5) {
                return back()->with('success', 'Sparepart ditambahkan dengan harga override Rp ' . number_format($price, 0, ',', '.') . ' (master saat ini Rp ' . number_format($masterPrice, 0, ',', '.') . ').');
            }
            return back()->with('success', 'Sparepart ditambahkan (harga master Rp ' . number_format($masterPrice, 0, ',', '.') . ').');
        } catch (\Throwable $e) {
            DB::rollBack();
            return back()->with('error', 'Gagal tambah sparepart: ' . $e->getMessage());
        }
    }

    public function removeSparepart(ServiceSparepart $sparepart)
    {
        $sparepart->loadMissing('ticket');
        $service = $sparepart->ticket;
        if (!$service) {
            return back()->with('error', 'Tiket sparepart tidak ditemukan.');
        }
        $this->authorizeStore($service);
        if (in_array($service->status, ['diserahkan', 'batal'])) {
            return back()->with('error', 'Tiket sudah selesai, sparepart tidak bisa dilepas.');
        }
        DB::beginTransaction();
        try {
            $ticket = ServiceTicket::whereKey($service->id)->lockForUpdate()->firstOrFail();
            $row = ServiceSparepart::whereKey($sparepart->id)->lockForUpdate()->firstOrFail();
            if ((int) $row->service_ticket_id !== (int) $ticket->id) {
                DB::rollBack();
                return back()->with('error', 'Sparepart bukan milik tiket ini.');
            }
            // Jaga konsistensi DP: total baru tidak boleh di bawah DP yang sudah masuk.
            $sumSpare = (float) ServiceSparepart::where('service_ticket_id', $ticket->id)->sum('subtotal');
            $prospective = (float) $ticket->estimate_cost + $sumSpare - (float) $row->subtotal;
            if ((float) $ticket->dp - $prospective > 0.5) {
                DB::rollBack();
                return back()->with('error', 'Sparepart tidak bisa dilepas: DP Rp ' . number_format((float) $ticket->dp, 0, ',', '.') . ' melebihi total baru Rp ' . number_format($prospective, 0, ',', '.') . '.');
            }
            $productName = $row->product->name ?? ('Produk #' . $row->product_id);
            $qty = (int) $row->qty;
            $stock = ProductStock::where('store_id', $ticket->store_id)->where('product_id', $row->product_id)->lockForUpdate()->first();
            if (!$stock) {
                $stock = ProductStock::create(['store_id' => $ticket->store_id, 'product_id' => $row->product_id, 'qty' => 0]);
            }
            $stock->qty = (int) $stock->qty + $qty;
            $stock->save();
            StockMovement::create([
                'store_id' => $ticket->store_id,
                'product_id' => $row->product_id,
                'type' => 'masuk',
                'qty' => $qty,
                'ref_type' => 'service_ticket',
                'ref_id' => $ticket->id,
                'note' => "Koreksi lepas sparepart {$productName} x{$qty} dari service {$ticket->ticket_no} (salah pasang)",
                'user_id' => auth()->id(),
            ]);
            $row->delete();
            $this->recalcTotal($ticket->fresh());
            DB::commit();
            return back()->with('success', 'Sparepart dilepas, stok dikembalikan.');
        } catch (\Throwable $e) {
            DB::rollBack();
            return back()->with('error', 'Gagal lepas sparepart: ' . $e->getMessage());
        }
    }

    public function printBulkTickets(Request $request)
    {
        $storeId = $this->storeId();
        $ids = array_values(array_unique(array_map('intval', (array) $request->query('ids', $request->input('ids', [])))));
        $ids = array_values(array_filter($ids, fn($v) => $v > 0));
        if (empty($ids)) {
            return back()->with('error', 'Pilih minimal 1 tiket untuk dicetak massal.');
        }
        if (count($ids) > 50) {
            return back()->with('error', 'Maksimal 50 tiket per cetak massal.');
        }
        $tickets = ServiceTicket::with(['customer', 'technician', 'store'])
            ->where('store_id', $storeId)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();
        if ($tickets->isEmpty()) {
            return back()->with('error', 'Tidak ada tiket valid yang dipilih.');
        }
        return view('services.print_bulk', compact('tickets'));
    }

    public function bulkPickupRemind(Request $request)
    {
        $storeId = $this->storeId();
        $data = $request->validate([
            'ticket_ids' => 'required|array|min:1|max:50',
            'ticket_ids.*' => 'integer|exists:service_tickets,id',
        ], [
            'ticket_ids.required' => 'Pilih minimal 1 tiket siap diambil.',
            'ticket_ids.min' => 'Pilih minimal 1 tiket siap diambil.',
            'ticket_ids.max' => 'Maksimal 50 tiket per pengingat massal.',
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['ticket_ids'])));
        $rows = ServiceTicket::with('customer')
            ->where('store_id', $storeId)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $okLines = [];
        $skipLines = [];
        $failLines = [];

        foreach ($ids as $id) {
            $t = $rows->get($id);
            if (!$t) {
                $failLines[] = "#$id: bukan tiket toko aktif";
                continue;
            }
            // Hanya status siap_diambil yang diingatkan massal.
            if ($t->status !== 'siap_diambil') {
                $skipLines[] = "$t->ticket_no: dilewati (status $t->status, hanya siap_diambil yang diingatkan)";
                continue;
            }
            // Lewati tanpa nomor HP.
            if (trim((string) ($t->customer->phone ?? '')) === '') {
                $skipLines[] = "$t->ticket_no: dilewati (tanpa nomor HP)";
                continue;
            }
            try {
                $nama = $t->customer->name ?? 'Pelanggan';
                $total = number_format((float) ($t->total_cost ?? 0), 0, ',', '.');
                $sisa = number_format(max(0, (float) ($t->total_cost ?? 0) - (float) ($t->dp ?? 0)), 0, ',', '.');
                $msg = WaService::render('service_siap_diambil', [
                    'nama' => $nama,
                    'tiket' => $t->ticket_no,
                    'status' => $t->status,
                    'total' => $total,
                    'sisa' => $sisa,
                    'track' => url('/track'),
                ]);
                if ($msg === '') {
                    $msg = "Halo $nama, HP Anda tiket *{$t->ticket_no}* sudah SIAP DIAMBIL. "
                        . "Total Rp $total, sisa bayar Rp $sisa. "
                        . url('/track');
                }
                $sent = WaService::send(
                    $t->customer->phone ?? '',
                    $msg,
                    'pickup_reminder',
                    $t->store_id
                );
                if (!$sent) {
                    $failLines[] = "$t->ticket_no: gagal terkirim ke {$t->customer->phone}";
                    continue;
                }
                $okLines[] = "$t->ticket_no → diingatkan ke {$t->customer->phone}";
            } catch (\Throwable $e) {
                report($e);
                $failLines[] = "$t->ticket_no: gagal ({$e->getMessage()})";
            }
        }

        $summary = 'Pengingat ambil massal: ' . count($okLines) . ' terkirim, '
            . count($skipLines) . ' dilewati (tanpa nomor / bukan siap_diambil), '
            . count($failLines) . ' gagal.';
        $detail = array_merge($okLines, $skipLines, $failLines);
        if ($detail) $summary .= ' | ' . implode('; ', $detail);

        if (count($okLines) === 0 && count($failLines) > 0) {
            return back()->with('error', $summary);
        }
        return back()->with('success', $summary);
    }

    public function bulkTechnicianRemind(Request $request)
    {
        $storeId = $this->storeId();
        $data = $request->validate([
            'ticket_ids' => 'required|array|min:1|max:50',
            'ticket_ids.*' => 'integer|exists:service_tickets,id',
        ], [
            'ticket_ids.required' => 'Pilih minimal 1 tiket aktif.',
            'ticket_ids.min' => 'Pilih minimal 1 tiket aktif.',
            'ticket_ids.max' => 'Maksimal 50 tiket per pengingat teknisi.',
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['ticket_ids'])));
        $rows = ServiceTicket::with(['customer', 'technician'])
            ->where('store_id', $storeId)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $okLines = [];
        $skipLines = [];
        $failLines = [];
        $grouped = [];

        foreach ($ids as $id) {
            $t = $rows->get($id);
            if (!$t) {
                $failLines[] = "#$id: bukan tiket toko aktif";
                continue;
            }
            // Hanya tiket aktif yang diingatkan (final diserahkan/batal dilewati).
            if (in_array($t->status, ['diserahkan', 'batal'])) {
                $skipLines[] = "$t->ticket_no: dilewati (status final $t->status)";
                continue;
            }
            // Tanpa teknisi penanggung jawab → lewati.
            if (!$t->technician_id || !$t->technician) {
                $skipLines[] = "$t->ticket_no: dilewati (tanpa teknisi penanggung jawab)";
                continue;
            }
            $grouped[(int) $t->technician_id][] = $t;
        }

        foreach ($grouped as $techId => $list) {
            $tech = $list[0]->technician;
            $phone = trim((string) ($tech->phone ?? ''));
            if ($phone === '') {
                foreach ($list as $t) {
                    $skipLines[] = "$t->ticket_no: dilewati (teknisi {$tech->name} tanpa nomor HP)";
                }
                continue;
            }
            try {
                $lines = [];
                $lines[] = "Halo {$tech->name}, daftar tiket aktif Anda (" . count($list) . "):";
                foreach ($list as $i => $t) {
                    $no = $i + 1;
                    $device = trim(($t->product_brand ?? '') . ' ' . ($t->product_type ?? ''));
                    $device = $device !== '' ? $device : '-';
                    $cust = $t->customer->name ?? '-';
                    $eta = $t->estimated_done_at
                        ? $t->estimated_done_at->format('d/m H:i')
                        : '-';
                    $lines[] = "$no. {$t->ticket_no} — $device [$t->status] — $cust (prioritas: " . ($t->priority ?? 'normal') . ", estimasi: $eta)";
                }
                $lines[] = 'Mohon segera ditindaklanjuti. Terima kasih.';
                $msg = implode("\n", $lines);
                $sent = WaService::send($phone, $msg, 'technician_reminder', $storeId);
                if (!$sent) {
                    foreach ($list as $t) {
                        $failLines[] = "$t->ticket_no: gagal terkirim ke $phone ({$tech->name})";
                    }
                    continue;
                }
                $tiketNos = implode(', ', array_map(fn($t) => $t->ticket_no, $list));
                $okLines[] = "{$tech->name} ($phone): " . count($list) . " tiket [$tiketNos]";
            } catch (\Throwable $e) {
                report($e);
                foreach ($list as $t) {
                    $failLines[] = "$t->ticket_no: gagal ({$e->getMessage()})";
                }
            }
        }

        $summary = 'Pengingat teknisi massal: ' . count($okLines) . ' teknisi diingatkan, '
            . count($skipLines) . ' tiket dilewati (final/tanpa teknisi/tanpa nomor), '
            . count($failLines) . ' gagal.';
        $detail = array_merge($okLines, $skipLines, $failLines);
        if ($detail) $summary .= ' | ' . implode('; ', $detail);

        if (count($okLines) === 0 && count($failLines) > 0) {
            return back()->with('error', $summary);
        }
        return back()->with('success', $summary);
    }

    public function printTicket(ServiceTicket $service)
    {
        $this->authorizeStore($service);
        $service->load(['customer','technician','spareparts.product','store']);
        $handoverLog = ServiceLog::where('service_ticket_id', $service->id)
            ->where('to_status', 'diserahkan')
            ->where('note', 'like', '%[SIG:%')
            ->latest()->first();
        $handoverSignature = $handoverLog && preg_match('/\[SIG:([^\]]+)\]/', (string) $handoverLog->note, $mm) ? trim($mm[1]) : null;
        $lanPrinters = \App\Services\EscPosService::all();
        $branding = \App\Services\EscPosService::branding($service->store_id);
        $brandingLogo = \App\Services\EscPosService::logoUrl($service->store_id);
        return view('services.print_ticket', compact('service', 'lanPrinters', 'branding', 'brandingLogo', 'handoverLog', 'handoverSignature'));
    }

    private function authorizeStore(ServiceTicket $service): void
    {
        $storeId = $this->storeId();
        if ($storeId && (int) $service->store_id !== (int) $storeId) {
            abort(403, 'Tiket bukan milik toko aktif.');
        }
    }

    /** Stream CSV ala Excel Indonesia: delimiter ; + BOM. */
    private function csvDownload(string $filename, array $header, array $rows)
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header, ';');
            foreach ($rows as $r) fputcsv($out, $r, ';');
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Laporan service per periode: filter tanggal + teknisi + status. */
    public function report(Request $request)
    {
        $storeId = $this->storeId();
        $from = $request->get('from', now()->startOfMonth()->toDateString());
        $to = $request->get('to', now()->toDateString());

        $base = ServiceTicket::with(['technician', 'spareparts'])
            ->where('store_id', $storeId)
            ->when($from, fn($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn($q) => $q->whereDate('created_at', '<=', $to))
            ->when($request->filled('technician_id'), fn($q) => $q->where('technician_id', $request->technician_id))
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->status));

        $tickets = (clone $base)->orderByDesc('id')->limit(5000)->get();

        $jumlah = $tickets->count();
        $omzetJasa = (float) $tickets->sum('estimate_cost');
        $omzetSparepart = (float) $tickets->sum(fn($t) => (float) $t->spareparts->sum('subtotal'));
        $omzetTotal = (float) $tickets->sum('total_cost');

        // Rata-rata nilai per tiket (total_cost / jumlah) — mengikuti periode + filter aktif.
        $avgTiket = $jumlah ? round($omzetTotal / $jumlah, 0) : null;

        // Varians estimasi vs aktual: selisih = total_cost - estimate_cost per tiket.
        // >= 0 umumnya (sparepart), <= 0 berarti hemat/under budget.
        $avgSelisih = null;
        if ($jumlah) {
            $avgSelisih = round((float) $tickets->avg(fn($t) => (float) ($t->total_cost ?? 0) - (float) ($t->estimate_cost ?? 0)), 0);
        }

        // Rata-rata hari pengerjaan: taken_at (atau updated_at) minus created_at, hanya tiket selesai.
        // SLA: sekalian petakan hari per tiket untuk bucket distribusi + rata-rata per teknisi.
        $done = $tickets->filter(fn($t) => in_array($t->status, ['diserahkan', 'siap_diambil']));
        $avgHari = null;
        $hariMap = [];
        $buckets = ['lt1' => 0, 'd1_3' => 0, 'gt3' => 0];
        if ($done->count()) {
            $sum = 0;
            $n = 0;
            foreach ($done as $t) {
                $end = $t->taken_at ?? $t->updated_at;
                if ($end && $t->created_at) {
                    $hari = max(0, ($end->getTimestamp() - $t->created_at->getTimestamp()) / 86400);
                    $hariMap[$t->id] = $hari;
                    $sum += $hari;
                    $n++;
                    if ($hari < 1) $buckets['lt1']++;
                    elseif ($hari <= 3) $buckets['d1_3']++;
                    else $buckets['gt3']++;
                }
            }
            if ($n) $avgHari = round($sum / $n, 1);
        }

        // Rata-rata waktu tunggu diterima → mulai dikerjakan: created_at → service_logs
        // dengan to_status 'dikerjakan' pertama. Tiket tanpa log tsb di-skip (tanpa fallback).
        $avgTungguJam = null;
        $tungguMap = [];
        $tungguCount = 0;
        if ($tickets->count()) {
            $firstWorked = ServiceLog::whereIn('service_ticket_id', $tickets->pluck('id')->all())
                ->where('to_status', 'dikerjakan')
                ->orderBy('created_at')
                ->get(['service_ticket_id', 'created_at'])
                ->groupBy('service_ticket_id')
                ->map(fn($g) => $g->first()->created_at);
            $sumJam = 0;
            $n = 0;
            foreach ($tickets as $t) {
                $mulai = $firstWorked->get($t->id);
                if ($mulai && $t->created_at) {
                    $jam = max(0, ($mulai->getTimestamp() - $t->created_at->getTimestamp()) / 3600);
                    $tungguMap[$t->id] = $jam;
                    $sumJam += $jam;
                    $n++;
                }
            }
            if ($n) {
                $avgTungguJam = round($sumJam / $n, 1);
                $tungguCount = $n;
            }
        }

        // Tiket melewati estimasi (terlambat): selesai yang end > estimasi,
        // atau masih aktif dan sekarang sudah lewat estimasi. Batal dikecualikan.
        $now = now();
        $overdue = $tickets->filter(function ($t) use ($now) {
            if (!$t->estimated_done_at) return false;
            if (in_array($t->status, ['batal'])) return false;
            $end = in_array($t->status, ['diserahkan', 'siap_diambil'])
                ? ($t->taken_at ?? $t->updated_at)
                : $now;
            return $end && $end->gt($t->estimated_done_at);
        })->map(function ($t) use ($now) {
            $end = in_array($t->status, ['diserahkan', 'siap_diambil'])
                ? ($t->taken_at ?? $t->updated_at)
                : $now;
            $t->sla_lambat_hari = ($end && $t->estimated_done_at)
                ? round(max(0, ($end->getTimestamp() - $t->estimated_done_at->getTimestamp()) / 86400), 1)
                : null;
            return $t;
        })->sortByDesc('sla_lambat_hari')->values();

        $perStatus = $tickets->groupBy('status')->map->count()->sortKeys();
        $perTeknisi = $tickets->groupBy(fn($t) => $t->technician->name ?? 'Tanpa teknisi')
            ->map(fn($g) => ['tiket' => $g->count(), 'jasa' => (float) $g->sum('estimate_cost'), 'total' => (float) $g->sum('total_cost'),
                'avg_total' => $g->count() ? round((float) $g->avg(fn($t) => (float) ($t->total_cost ?? 0)), 0) : null,
                'selesai' => 0, 'avg_hari' => null, 'lt1' => 0, 'd1_3' => 0, 'gt3' => 0,
                'tunggu_n' => 0, 'avg_tunggu_jam' => null, 'avg_selisih' => null])
            ->sortDesc();

        // Rata-rata + bucket pengerjaan per teknisi (dari tiket selesai saja).
        foreach ($done->groupBy(fn($t) => $t->technician->name ?? 'Tanpa teknisi') as $nama => $g) {
            $hs = $g->map(fn($t) => $hariMap[$t->id] ?? null)->filter(fn($v) => $v !== null)->values();
            $row = $perTeknisi->get($nama, ['tiket' => $g->count(), 'jasa' => 0.0, 'total' => 0.0,
                'avg_total' => null,
                'selesai' => 0, 'avg_hari' => null, 'lt1' => 0, 'd1_3' => 0, 'gt3' => 0,
                'tunggu_n' => 0, 'avg_tunggu_jam' => null, 'avg_selisih' => null]);
            $row['selesai'] = $hs->count();
            $row['avg_hari'] = $hs->count() ? round($hs->avg(), 1) : null;
            foreach ($hs as $h) {
                if ($h < 1) $row['lt1']++;
                elseif ($h <= 3) $row['d1_3']++;
                else $row['gt3']++;
            }
            $perTeknisi->put($nama, $row);
        }

        // Rata-rata waktu tunggu per teknisi (dari semua tiket yang punya log 'dikerjakan').
        foreach ($tickets->groupBy(fn($t) => $t->technician->name ?? 'Tanpa teknisi') as $nama => $g) {
            $ws = $g->map(fn($t) => $tungguMap[$t->id] ?? null)->filter(fn($v) => $v !== null)->values();
            if (!$ws->count() || !$perTeknisi->has($nama)) continue;
            $row = $perTeknisi->get($nama);
            $row['tunggu_n'] = $ws->count();
            $row['avg_tunggu_jam'] = round($ws->avg(), 1);
            $perTeknisi->put($nama, $row);
        }

        // Rata-rata selisih (aktual − estimasi) per teknisi: avg(total_cost − estimate_cost).
        foreach ($tickets->groupBy(fn($t) => $t->technician->name ?? 'Tanpa teknisi') as $nama => $g) {
            if (!$perTeknisi->has($nama)) continue;
            $row = $perTeknisi->get($nama);
            $row['avg_selisih'] = $g->count()
                ? round((float) $g->avg(fn($t) => (float) ($t->total_cost ?? 0) - (float) ($t->estimate_cost ?? 0)), 0)
                : null;
            $perTeknisi->put($nama, $row);
        }

        // Export CSV (?export=csv) — rekap per tiket periode ini
        if ($request->get('export') === 'csv') {
            $filename = "laporan-service-{$from}-sd-{$to}.csv";
            return $this->csvDownload($filename,
                ['id', 'tiket', 'tanggal', 'teknisi', 'status', 'jasa', 'sparepart', 'total', 'selisih', 'hari_pengerjaan', 'tunggu_jam', 'estimasi', 'lambat_hari'],
                $tickets->map(function ($t) use ($now, $tungguMap) {
                    $end = $t->taken_at ?? $t->updated_at;
                    $hari = ($end && $t->created_at) ? round(max(0, ($end->getTimestamp() - $t->created_at->getTimestamp()) / 86400), 1) : '';
                    $lambat = '';
                    if ($t->estimated_done_at && !in_array($t->status, ['batal'])) {
                        $cmp = in_array($t->status, ['diserahkan', 'siap_diambil']) ? $end : $now;
                        if ($cmp && $cmp->gt($t->estimated_done_at)) {
                            $lambat = round(max(0, ($cmp->getTimestamp() - $t->estimated_done_at->getTimestamp()) / 86400), 1);
                        }
                    }
                    return [$t->id, $t->ticket_no, $t->created_at?->format('Y-m-d H:i'), $t->technician->name ?? '',
                        $t->status, (float) ($t->estimate_cost ?? 0), (float) $t->spareparts->sum('subtotal'), (float) ($t->total_cost ?? 0),
                        (float) ($t->total_cost ?? 0) - (float) ($t->estimate_cost ?? 0), $hari,
                        isset($tungguMap[$t->id]) ? round($tungguMap[$t->id], 1) : '',
                        $t->estimated_done_at?->format('Y-m-d H:i') ?? '', $lambat];
                })->all());
        }

        $technicians = LookupCache::technicians();
        $statuses = array_merge($this->flow, ['batal']);
        return view('services.report', compact('from', 'to', 'technicians', 'statuses', 'tickets', 'jumlah', 'omzetJasa', 'omzetSparepart', 'omzetTotal', 'avgTiket', 'avgSelisih', 'avgHari', 'perStatus', 'perTeknisi', 'done', 'buckets', 'overdue', 'hariMap', 'avgTungguJam', 'tungguCount', 'tungguMap'));
    }
}
