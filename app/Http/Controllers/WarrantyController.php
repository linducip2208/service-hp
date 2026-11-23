<?php

namespace App\Http\Controllers;

use App\Models\ServiceLog;
use App\Models\ServiceTicket;
use App\Models\Warranty;
use App\Services\WaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WarrantyController extends Controller
{
    private function storeId(): ?int
    {
        return session('active_store_id') ?? auth()->user()->active_store_id ?? null;
    }

    /** Scope toko untuk garansi satuan: via customer.store_id lalu productItem.store_id.
     *  Superadmin tanpa toko aktif (mode semua-toko) dilewati. */
    private function authorizeWarranty(Warranty $warranty): void
    {
        $storeId = $this->storeId();
        if (!$storeId) return;
        $warranty->loadMissing(['customer', 'productItem']);
        $owner = $warranty->customer->store_id ?? $warranty->productItem->store_id ?? null;
        if ($owner === null) return;
        abort_if((int) $owner !== (int) $storeId, 403, 'Bukan garansi toko aktif.');
    }

    public function index(Request $request)
    {
        $storeId = $this->storeId();
        $q = Warranty::with(['customer'])->latest();
        if ($request->filled('status')) $q->where('status', $request->status);
        if ($request->filled('type')) $q->where('type', $request->type);
        if ($request->filled('search')) {
            $s = $request->search;
            $q->where(function ($w) use ($s) {
                $w->where('claim_note', 'like', "%$s%")
                  ->orWhere('ref_id', $s)
                  ->orWhereHas('customer', fn($c) => $c->where('name', 'like', "%$s%")->orWhere('phone', 'like', "%$s%"));
            });
        }
        // Filter periode garansi (?from=YYYY-MM-DD & ?to=YYYY-MM-DD):
        // kolom tanggal yang dipakai start_date (tanggal mulai garansi).
        // Validasi format tanggal sederhana agar query aman.
        if (
            $request->filled('from')
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->from)
        ) {
            try {
                $from = \Carbon\Carbon::createFromFormat('Y-m-d', (string) $request->from)->toDateString();
                $q->whereDate('start_date', '>=', $from);
            } catch (\Throwable $e) {
            }
        }
        if (
            $request->filled('to')
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->to)
        ) {
            try {
                $to = \Carbon\Carbon::createFromFormat('Y-m-d', (string) $request->to)->toDateString();
                $q->whereDate('start_date', '<=', $to);
            } catch (\Throwable $e) {
            }
        }
        // Batasi ke toko aktif bila garansi berasal dari tiket service toko tsb
        if ($storeId) {
            $q->where(function ($w) use ($storeId) {
                $w->whereHas('customer', fn($c) => $c->where('store_id', $storeId))
                  ->orWhere(function ($x) use ($storeId) {
                      $x->where('ref_type', 'service')
                        ->whereIn('ref_id', ServiceTicket::where('store_id', $storeId)->select('id'));
                  })
                  ->orWhereNull('customer_id');
            });
        }
        $warranties = null;
        // Export CSV (?export=csv) — hormati filter status/type/search + scope toko,
        // pola BookingController@index (delimiter ; + BOM, streamDownload).
        if ($request->get('export') === 'csv') {
            $rows = (clone $q)->with(['customer'])->limit(5000)->get();
            $filename = 'garansi_toko_' . ($storeId ?: 'semua')
                . '_' . now()->format('Ymd_His') . '.csv';
            return response()->streamDownload(function () use ($rows) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv(
                    $out,
                    ['id', 'pelanggan', 'hp', 'ref_type', 'ref_id', 'tipe', 'mulai', 'berakhir', 'status', 'kedaluwarsa', 'catatan'],
                    ';'
                );
                foreach ($rows as $w) {
                    $expired = ($w->end_date && $w->end_date->isPast() && $w->status === 'aktif') ? 'ya' : 'tidak';
                    fputcsv(
                        $out,
                        [
                            $w->id,
                            $w->customer->name ?? '',
                            $w->customer->phone ?? '',
                            $w->ref_type ?? '',
                            $w->ref_id ?? '',
                            $w->type ?? '',
                            $w->start_date ? $w->start_date->format('Y-m-d') : '',
                            $w->end_date ? $w->end_date->format('Y-m-d') : '',
                            $w->status ?? '',
                            $expired,
                            $w->claim_note ?? '',
                        ],
                        ';'
                    );
                }
                fclose($out);
            }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }
        $warranties = $q->paginate(15)->withQueryString();
        // Tandai kedaluwarsa untuk badge
        foreach ($warranties as $wr) {
            $wr->is_expired = $wr->end_date && $wr->end_date->isPast() && $wr->status === 'aktif';
        }
        return view('warranties.index', compact('warranties'));
    }

    /**
     * CETAK KARTU GARANSI: ukuran kartu A6 landscape untuk diserahkan ke pelanggan.
     * Tanpa migration — hanya baca relasi yang ada (customer, productItem, tiket service).
     */
    public function card(Warranty $warranty)
    {
        $this->authorizeWarranty($warranty);
        $warranty->load(['customer.store', 'productItem.product', 'productItem.store']);

        $ticket = null;
        if ($warranty->ref_type === 'service' && $warranty->ref_id) {
            try {
                $ticket = ServiceTicket::with(['store', 'customer'])->find($warranty->ref_id);
            } catch (\Throwable $e) {
                $ticket = null;
            }
        }

        $store = $ticket->store ?? $warranty->customer->store ?? $warranty->productItem->store ?? null;
        if (!$store && $this->storeId()) {
            try {
                $store = \App\Models\Store::find($this->storeId());
            } catch (\Throwable $e) {
                $store = null;
            }
        }

        try {
            $trackUrl = route('track.form');
        } catch (\Throwable $e) {
            $trackUrl = url('/track');
        }
        $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=110x110&data=' . urlencode($trackUrl);

        $isExpired = $warranty->end_date && $warranty->end_date->isPast() && $warranty->status === 'aktif';

        return view('warranties.card', compact('warranty', 'ticket', 'store', 'trackUrl', 'qrUrl', 'isExpired'));
    }

    /**
     * CETAK KARTU GARANSI MASSAL: 1 halaman gabungan berisi N kartu (maks 50),
     * masing-masing page-break agar rapi saat print. Cermin pola debts bulkStatements.
     * GET /warranties/cards?ids[]=1&ids[]=2 — tanpa migration.
     */
    public function bulkCards(Request $request)
    {
        $ids = array_values(array_unique(array_map('intval', (array) $request->query('ids', $request->input('ids', [])))));
        $ids = array_values(array_filter($ids, fn ($v) => $v > 0));
        if (empty($ids)) {
            return back()->with('error', 'Pilih minimal 1 garansi untuk dicetak (centang baris tabel).');
        }
        if (count($ids) > 50) {
            return back()->with('error', 'Maksimal 50 kartu garansi per cetak massal.');
        }

        $storeId = $this->storeId();
        $q = Warranty::with(['customer.store', 'productItem.product', 'productItem.store'])
            ->whereIn('id', $ids)
            ->orderBy('id');
        if ($storeId) {
            $q->where(function ($w) use ($storeId) {
                $w->whereHas('customer', fn ($c) => $c->where('store_id', $storeId))
                    ->orWhere(function ($x) use ($storeId) {
                        $x->where('ref_type', 'service')
                            ->whereIn('ref_id', ServiceTicket::where('store_id', $storeId)->select('id'));
                    })
                    ->orWhereNull('customer_id');
            });
        }
        $warranties = $q->get();
        if ($warranties->isEmpty()) {
            return back()->with('error', 'Tidak ada garansi valid yang dipilih (mungkin beda toko).');
        }

        try {
            $trackUrl = route('track.form');
        } catch (\Throwable $e) {
            $trackUrl = url('/track');
        }
        $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=110x110&data=' . urlencode($trackUrl);

        // Susun paket per kartu: garansi + tiket terkait + toko + flag kedaluwarsa.
        $cards = [];
        foreach ($warranties as $wr) {
            $ticket = null;
            if ($wr->ref_type === 'service' && $wr->ref_id) {
                try {
                    $ticket = ServiceTicket::with(['store', 'customer'])->find($wr->ref_id);
                } catch (\Throwable $e) {
                    $ticket = null;
                }
            }
            $store = $ticket->store ?? $wr->customer->store ?? $wr->productItem->store ?? null;
            if (!$store && $storeId) {
                try {
                    $store = \App\Models\Store::find($storeId);
                } catch (\Throwable $e) {
                    $store = null;
                }
            }
            $cards[] = [
                'warranty' => $wr,
                'ticket' => $ticket,
                'store' => $store,
                'isExpired' => (bool) ($wr->end_date && $wr->end_date->isPast() && $wr->status === 'aktif'),
            ];
        }

        return view('warranties.cards', compact('cards', 'trackUrl', 'qrUrl'));
    }

    /**
     * INGATKAN MASSAL MANUAL: WA ke customer garansi AKTIF terpilih (maks 50).
     * Cermin pola bookings bulkRemind + pesan H-7 DailyDigestService.
     * POST /warranties/remind-selected — tanpa migration.
     */
    public function bulkRemind(Request $request)
    {
        $data = $request->validate([
            'warranty_ids' => 'required|array|min:1|max:50',
            'warranty_ids.*' => 'integer',
        ], [
            'warranty_ids.required' => 'Pilih minimal 1 garansi aktif untuk diingatkan.',
            'warranty_ids.min' => 'Pilih minimal 1 garansi aktif untuk diingatkan.',
            'warranty_ids.max' => 'Maksimal 50 garansi per pengingat massal.',
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['warranty_ids'])));
        $ids = array_values(array_filter($ids, fn ($v) => $v > 0));
        $ids = array_slice($ids, 0, 50);
        if (empty($ids)) {
            return back()->with('error', 'Pilih minimal 1 garansi aktif untuk diingatkan.');
        }

        $storeId = $this->storeId();
        $q = Warranty::with(['customer'])
            ->whereIn('id', $ids)
            ->orderBy('id');
        if ($storeId) {
            $q->where(function ($w) use ($storeId) {
                $w->whereHas('customer', fn ($c) => $c->where('store_id', $storeId))
                    ->orWhere(function ($x) use ($storeId) {
                        $x->where('ref_type', 'service')
                            ->whereIn('ref_id', ServiceTicket::where('store_id', $storeId)->select('id'));
                    })
                    ->orWhereNull('customer_id');
            });
        }
        $rows = $q->get()->keyBy('id');
        if ($rows->isEmpty()) {
            return back()->with('error', 'Tidak ada garansi valid yang dipilih (mungkin beda toko).');
        }

        // Batch: peta store tiket ref sekali (dulu resolveWarrantyStore query per baris).
        $bulkTicketStores = collect();
        try {
            $needIds = $rows->filter(fn ($wr) => empty($wr->customer?->store_id) && ($wr->ref_type ?? null) === 'service' && ! empty($wr->ref_id))
                ->map(fn ($wr) => (int) $wr->ref_id)->unique()->values()->all();
            if (! empty($needIds)) {
                $bulkTicketStores = ServiceTicket::whereIn('id', $needIds)->pluck('store_id', 'id');
            }
        } catch (\Throwable $e) {
        }

        $okLines = [];
        $skipLines = [];
        $failLines = [];
        foreach ($ids as $id) {
            $wr = $rows->get($id);
            if (!$wr) {
                $failLines[] = "#$id: bukan garansi toko aktif";

                continue;
            }
            if ($wr->status !== 'aktif') {
                $skipLines[] = "#$id: dilewati (status {$wr->status}, hanya aktif yang diingatkan)";

                continue;
            }
            $phone = trim((string) ($wr->customer->phone ?? ''));
            if ($phone === '') {
                $skipLines[] = "#$id: dilewati (tanpa nomor HP)";

                continue;
            }
            try {
                $msg = \App\Services\DailyDigestService::warrantyReminderMessage($wr);
                $storeForLog = $wr->customer?->store_id
                    ?? ($wr->ref_type === 'service' && $wr->ref_id ? ($bulkTicketStores->get((int) $wr->ref_id) ?? $storeId) : $storeId);
                $sent = WaService::send(
                    $phone,
                    $msg,
                    'warranty_expiring',
                    $storeForLog ? (int) $storeForLog : $storeId
                );
                if (!$sent) {
                    $failLines[] = "#$id: gagal terkirim ke $phone";

                    continue;
                }
                $okLines[] = "#$id → diingatkan ke $phone";
            } catch (\Throwable $e) {
                report($e);
                $failLines[] = "#$id: gagal ({$e->getMessage()})";
            }
        }

        $summary = 'Pengingat garansi massal: ' . count($okLines) . ' terkirim, '
            . count($skipLines) . ' dilewati (bukan aktif / tanpa nomor), '
            . count($failLines) . ' gagal.';
        $detail = array_merge($okLines, $skipLines, $failLines);
        if ($detail) {
            $summary .= ' | ' . implode('; ', $detail);
        }

        if (count($okLines) === 0 && count($failLines) > 0) {
            return back()->with('error', $summary);
        }

        return back()->with('success', $summary);
    }

    /**
     * PROSES KLAIM MASSAL: ubah garansi AKTIF terpilih → klaim (maks 50).
     * Cermin pola claim() tunggal + lapor per baris ala bulkRemind.
     * POST /warranties/claim-selected — tanpa migration.
     */
    public function bulkClaim(Request $request)
    {
        $data = $request->validate([
            'warranty_ids' => 'required|array|min:1|max:50',
            'warranty_ids.*' => 'integer',
        ], [
            'warranty_ids.required' => 'Pilih minimal 1 garansi aktif untuk diproses klaimnya.',
            'warranty_ids.min' => 'Pilih minimal 1 garansi aktif untuk diproses klaimnya.',
            'warranty_ids.max' => 'Maksimal 50 garansi per proses klaim massal.',
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['warranty_ids'])));
        $ids = array_values(array_filter($ids, fn ($v) => $v > 0));
        $ids = array_slice($ids, 0, 50);
        if (empty($ids)) {
            return back()->with('error', 'Pilih minimal 1 garansi aktif untuk diproses klaimnya.');
        }

        $storeId = $this->storeId();
        $q = Warranty::with(['customer'])
            ->whereIn('id', $ids)
            ->orderBy('id');
        if ($storeId) {
            $q->where(function ($w) use ($storeId) {
                $w->whereHas('customer', fn ($c) => $c->where('store_id', $storeId))
                    ->orWhere(function ($x) use ($storeId) {
                        $x->where('ref_type', 'service')
                            ->whereIn('ref_id', ServiceTicket::where('store_id', $storeId)->select('id'));
                    })
                    ->orWhereNull('customer_id');
            });
        }
        $rows = $q->get()->keyBy('id');
        if ($rows->isEmpty()) {
            return back()->with('error', 'Tidak ada garansi valid yang dipilih (mungkin beda toko).');
        }

        $okLines = [];
        $skipLines = [];
        $failLines = [];
        foreach ($ids as $id) {
            $wr = $rows->get($id);
            if (!$wr) {
                $failLines[] = "#$id: bukan garansi toko aktif";

                continue;
            }
            if ($wr->status !== 'aktif') {
                $skipLines[] = "#$id: dilewati (status {$wr->status}, hanya aktif yang diproses)";

                continue;
            }
            try {
                $wr->claim_note = $wr->claim_note ?: 'Klaim diterima di toko (massal)';
                $wr->status = 'klaim';
                $wr->save();

                try {
                    $phone = $wr->customer->phone ?? '';
                    $nama = $wr->customer->name ?? 'Pelanggan';
                    $msg = "Halo $nama, klaim garansi Anda (ID #{$wr->id}) status: *KLAIM*. Catatan: " . ($wr->claim_note ?? '-');
                    WaService::send($phone, $msg, 'warranty_claim', $storeId);
                } catch (\Throwable $e) {
                    report($e);
                }

                $okLines[] = "#$id → klaim diproses (status → klaim)";
            } catch (\Throwable $e) {
                report($e);
                $failLines[] = "#$id: gagal ({$e->getMessage()})";
            }
        }

        $summary = 'Proses klaim massal: ' . count($okLines) . ' diproses, '
            . count($skipLines) . ' dilewati (bukan aktif), '
            . count($failLines) . ' gagal.';
        $detail = array_merge($okLines, $skipLines, $failLines);
        if ($detail) {
            $summary .= ' | ' . implode('; ', $detail);
        }

        if (count($okLines) === 0 && count($failLines) > 0) {
            return back()->with('error', $summary);
        }

        return back()->with('success', $summary);
    }

    /**
     * PERPANJANG GARANSI MASSAL: tambah N hari ke end_date garansi AKTIF terpilih.
     * Cermin pola promos bulkExtend + lapor per baris ala bulkClaim.
     * Aturan: end baru = max(hari ini, end lama) + N hari (default 30, 1–365).
     * end_date NULL → dilewati; status bukan aktif → dilewati.
     * POST /warranties/extend-selected — tanpa migration.
     */
    public function bulkExtend(Request $request)
    {
        $data = $request->validate([
            'warranty_ids' => 'required|array|min:1|max:50',
            'warranty_ids.*' => 'integer',
            'extend_days' => 'nullable|integer|min:1|max:365',
        ], [
            'warranty_ids.required' => 'Pilih minimal 1 garansi aktif untuk diperpanjang.',
            'warranty_ids.min' => 'Pilih minimal 1 garansi aktif untuk diperpanjang.',
            'warranty_ids.max' => 'Maksimal 50 garansi per perpanjangan massal.',
            'extend_days.integer' => 'Jumlah hari perpanjangan tidak valid.',
            'extend_days.min' => 'Minimal perpanjangan 1 hari.',
            'extend_days.max' => 'Maksimal perpanjangan 365 hari.',
        ]);

        $days = (int) ($data['extend_days'] ?? 30);
        if ($days < 1) {
            $days = 30;
        }
        if ($days > 365) {
            $days = 365;
        }

        $ids = array_values(array_unique(array_map('intval', $data['warranty_ids'])));
        $ids = array_values(array_filter($ids, fn ($v) => $v > 0));
        $ids = array_slice($ids, 0, 50);
        if (empty($ids)) {
            return back()->with('error', 'Pilih minimal 1 garansi aktif untuk diperpanjang.');
        }

        $storeId = $this->storeId();
        $q = Warranty::with(['customer'])
            ->whereIn('id', $ids)
            ->orderBy('id');
        if ($storeId) {
            $q->where(function ($w) use ($storeId) {
                $w->whereHas('customer', fn ($c) => $c->where('store_id', $storeId))
                    ->orWhere(function ($x) use ($storeId) {
                        $x->where('ref_type', 'service')
                            ->whereIn('ref_id', ServiceTicket::where('store_id', $storeId)->select('id'));
                    })
                    ->orWhereNull('customer_id');
            });
        }
        $rows = $q->get()->keyBy('id');
        if ($rows->isEmpty()) {
            return back()->with('error', 'Tidak ada garansi valid yang dipilih (mungkin beda toko).');
        }

        $today = now()->startOfDay();
        $okLines = [];
        $skipLines = [];
        $failLines = [];
        foreach ($ids as $id) {
            $wr = $rows->get($id);
            if (!$wr) {
                $failLines[] = "#$id: bukan garansi toko aktif";

                continue;
            }
            if ($wr->status !== 'aktif') {
                $skipLines[] = "#$id: dilewati (status {$wr->status}, hanya aktif yang diperpanjang)";

                continue;
            }
            if (!$wr->end_date) {
                $skipLines[] = "#$id: dilewati (tanpa tanggal berakhir)";

                continue;
            }
            try {
                $old = $wr->end_date->copy()->startOfDay();
                $base = $old->greaterThan($today) ? $old->copy() : $today->copy();
                $new = $base->copy()->addDays($days);
                if ($wr->start_date && $wr->start_date->copy()->startOfDay()->greaterThan($new)) {
                    $new = $wr->start_date->copy()->startOfDay()->addDays($days);
                }
                $wr->end_date = $new->toDateString();
                $wr->save();

                $okLines[] = "#$id: " . $old->format('d/m/Y')
                    . " → " . $new->format('d/m/Y')
                    . " (+{$days} hari)";
            } catch (\Throwable $e) {
                report($e);
                $failLines[] = "#$id: gagal ({$e->getMessage()})";
            }
        }

        $summary = 'Perpanjang garansi massal (+' . $days . ' hari): ' . count($okLines) . ' diperpanjang, '
            . count($skipLines) . ' dilewati (bukan aktif / tanpa tanggal), '
            . count($failLines) . ' gagal.';
        $detail = array_merge($okLines, $skipLines, $failLines);
        if ($detail) {
            $summary .= ' | ' . implode('; ', $detail);
        }

        if (count($okLines) === 0 && count($failLines) > 0) {
            return back()->with('error', $summary);
        }

        return back()->with('success', $summary);
    }

    public function claim(Request $request, Warranty $warranty)
    {
        $this->authorizeWarranty($warranty);
        $request->validate([
            'claim_note' => 'nullable|string|max:500',
            'hasil' => 'nullable|in:klaim,selesai',
        ]);
        $warranty->claim_note = $request->claim_note ?: $warranty->claim_note;
        $warranty->status = $request->hasil ?: 'klaim';
        $warranty->save();

        // Buat tiket service baru bertipe garansi yang ter-link (ref warranty)
        $storeId = $this->storeId();
        $ticketNo = null;
        try {
            $linked = ServiceTicket::where('booking_id', null)->where('id', $warranty->ref_id)->first();
            $ticketNo = $linked?->ticket_no;
        } catch (\Throwable $e) {}

        try {
            $phone = $warranty->customer->phone ?? '';
            $nama = $warranty->customer->name ?? 'Pelanggan';
            $msg = "Halo $nama, klaim garansi Anda (ID #{$warranty->id}, tiket terkait $ticketNo) status: *" . strtoupper($warranty->status) . "*. Catatan: " . ($warranty->claim_note ?? '-');
            WaService::send($phone, $msg, 'warranty_claim', $storeId);
        } catch (\Throwable $e) {}

        return back()->with('success', "Garansi #{$warranty->id} diproses (status → klaim). Tiket terkait: $ticketNo. WA terkirim.");
    }

    /**
     * KLAIM → TIKET 1-KLIK: buat ServiceTicket dari data warranty tanpa migration.
     * Link balik via warranty claim_note + status klaim.
     */
    public function toTicket(Request $request, Warranty $warranty)
    {
        $this->authorizeWarranty($warranty);
        if (!in_array($warranty->status, ['aktif', 'klaim'])) {
            return back()->with('error', "Garansi #{$warranty->id} berstatus '{$warranty->status}' — hanya garansi aktif/klaim yang bisa dibuatkan tiket.");
        }
        if (!$warranty->customer_id) {
            return back()->with('error', "Garansi #{$warranty->id} tidak punya pelanggan — pilih pelanggan dulu sebelum buat tiket.");
        }

        // Cek sederhana: tolak bila sudah ada tiket TERBUKA untuk ref garansi yang sama.
        // Penanda: complaint mengandung "Klaim garansi #<id>".
        $marker = "Klaim garansi #{$warranty->id}";
        $open = ServiceTicket::where('complaint', 'like', "%{$marker}%")
            ->whereNotIn('status', ['diserahkan', 'batal'])
            ->first();
        if ($open) {
            return back()->with('error', "Garansi #{$warranty->id} sudah punya tiket terbuka {$open->ticket_no} (status: {$open->status}). Gunakan tiket itu.");
        }

        // Device info bila ada di relasi: tiket service asal (ref_type=service).
        $orig = null;
        if ($warranty->ref_type === 'service' && $warranty->ref_id) {
            try {
                $orig = ServiceTicket::find($warranty->ref_id);
            } catch (\Throwable $e) {
                $orig = null;
            }
        }

        $storeId = $orig->store_id ?? $this->storeId();
        if (!$storeId) {
            return back()->with('error', 'Toko aktif tidak ditemukan — pilih toko dulu sebelum buat tiket klaim.');
        }

        DB::beginTransaction();
        try {
            $prefix = 'SVC-' . now()->format('Ymd') . '-';
            $count = ServiceTicket::where('store_id', $storeId)->where('ticket_no', 'like', $prefix . '%')->count() + 1;
            do {
                $ticketNo = $prefix . str_pad($count, 4, '0', STR_PAD_LEFT);
                $count++;
            } while (ServiceTicket::where('ticket_no', $ticketNo)->exists());

            $refLabel = $warranty->ref_type && $warranty->ref_id
                ? " (ref {$warranty->ref_type} #{$warranty->ref_id})"
                : '';
            $complaint = $marker . $refLabel . ': ' . ($warranty->claim_note ?: 'pelanggan klaim garansi');

            $ticket = ServiceTicket::create([
                'ticket_no' => $ticketNo,
                'store_id' => $storeId,
                'customer_id' => $warranty->customer_id,
                'technician_id' => null,
                'product_brand' => $orig->product_brand ?? 'Garansi',
                'product_type' => $orig->product_type ?? ('Ref #' . ($warranty->ref_id ?? $warranty->id)),
                'imei' => $orig->imei ?? null,
                'complaint' => $complaint,
                'condition_note' => null,
                'photos' => null,
                'diagnosis' => null,
                'estimate_cost' => 0,
                'approval_status' => 'menunggu',
                'status' => 'diterima',
                'priority' => 'normal',
                'dp' => 0,
                'total_cost' => 0,
                'warranty_days' => 0,
                'estimated_done_at' => now()->addDays(2),
            ]);

            ServiceLog::create([
                'service_ticket_id' => $ticket->id,
                'user_id' => auth()->id(),
                'from_status' => null,
                'to_status' => 'diterima',
                'note' => "Tiket klaim garansi #{$warranty->id} dibuat 1-klik.",
            ]);

            // Link balik tanpa migration: status → klaim + catat nomor tiket di claim_note.
            $warranty->status = 'klaim';
            $linkNote = "Tiket klaim {$ticketNo}";
            $warranty->claim_note = trim(($warranty->claim_note ? $warranty->claim_note . ' → ' : '') . $linkNote);
            $warranty->save();

            DB::commit();
            return redirect()->route('services.show', $ticket)->with('success', "Tiket klaim {$ticketNo} dibuat dari garansi #{$warranty->id}.");
        } catch (\Throwable $e) {
            DB::rollBack();
            return back()->with('error', 'Gagal buat tiket klaim: ' . $e->getMessage());
        }
    }
}
