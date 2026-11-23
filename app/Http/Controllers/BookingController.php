<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\ServiceLog;
use App\Models\ServiceTicket;
use App\Models\Store;
use App\Services\WaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BookingController extends Controller
{
    private function storeId(): ?int
    {
        return session('active_store_id') ?? auth()->user()->active_store_id ?? null;
    }

    public function index(Request $request)
    {
        $storeId = $this->storeId();
        $q = Booking::where('store_id', $storeId)->latest();
        if ($request->filled('status')) $q->where('status', $request->status);
        if ($request->filled('search')) {
            $s = $request->search;
            $q->where(function ($w) use ($s) {
                $w->where('code', 'like', "%$s%")
                  ->orWhere('customer_name', 'like', "%$s%")
                  ->orWhere('customer_phone', 'like', "%$s%")
                  ->orWhere('device', 'like', "%$s%");
            });
        }
        // Filter bulan jadwal (?bulan=YYYY-MM): samakan pola calendar,
        // kolom tanggal yang dipakai schedule_date (jadwal booking).
        if (
            $request->filled('bulan')
            && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $request->bulan)
        ) {
            [$by, $bm] = explode('-', (string) $request->bulan);
            $q->whereYear('schedule_date', (int) $by)
              ->whereMonth('schedule_date', (int) $bm);
        }
        $bookings = null;
        // Export CSV (?export=csv) — hormati filter status/search + scope toko,
        // pola UnitController@index (delimiter ; + BOM, streamDownload).
        if ($request->get('export') === 'csv') {
            $rows = (clone $q)->limit(5000)->get();
            $filename = 'bookings_toko_' . ($storeId ?: 'semua')
                . '_' . now()->format('Ymd_His') . '.csv';
            return response()->streamDownload(function () use ($rows) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv(
                    $out,
                    ['kode', 'nama', 'hp', 'device', 'keluhan', 'jadwal', 'status', 'tiket'],
                    ';'
                );
                foreach ($rows as $b) {
                    fputcsv(
                        $out,
                        [
                            $b->code ?? '',
                            $b->customer_name ?? '',
                            $b->customer_phone ?? '',
                            $b->device ?? '',
                            $b->complaint ?? '',
                            $b->schedule_date
                                ? \Carbon\Carbon::parse($b->schedule_date)->format('Y-m-d H:i')
                                : '',
                            $b->status ?? '',
                            $b->ticket_id ?? '',
                        ],
                        ';'
                    );
                }
                fclose($out);
            }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }
        $bookings = $q->paginate(15)->withQueryString();
        $stores = Store::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        // Kartu konversi booking → tiket (store-wide, tanpa filter).
        // Definisi: converted = ticket_id NOT NULL (dibuat otomatis saat
        // status → dikonfirmasi). Rate = converted / total * 100.
        $statTotal = Booking::where('store_id', $storeId)->count();
        $statConverted = Booking::where('store_id', $storeId)->whereNotNull('ticket_id')->count();
        $convRate = $statTotal > 0 ? round($statConverted / $statTotal * 100, 1) : 0;
        return view('bookings.index', compact('bookings', 'statTotal', 'statConverted', 'convRate', 'stores'));
    }

    public function calendar(Request $request)
    {
        $storeId = $this->storeId();
        $bulan = $request->get('bulan', now()->format('Y-m'));
        try {
            $month = \Carbon\Carbon::createFromFormat('Y-m', $bulan)->startOfMonth();
        } catch (\Throwable $e) {
            $month = now()->startOfMonth();
        }
        $start = $month->copy()->startOfMonth()->startOfDay();
        $end = $month->copy()->endOfMonth()->endOfDay();

        $bookings = Booking::where('store_id', $storeId)
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('schedule_date', [$start, $end])
                  ->orWhere(function ($w) use ($start, $end) {
                      $w->whereNull('schedule_date')
                        ->whereBetween('created_at', [$start, $end]);
                  });
            })
            ->orderBy('schedule_date')->orderBy('created_at')
            ->get();

        $grouped = [];
        foreach ($bookings as $b) {
            $key = $b->schedule_date
                ? \Carbon\Carbon::parse($b->schedule_date)->format('Y-m-d')
                : ($b->created_at ? \Carbon\Carbon::parse($b->created_at)->format('Y-m-d') : $month->format('Y-m-d'));
            $grouped[$key][] = $b;
        }

        $prevMonth = $month->copy()->subMonth()->format('Y-m');
        $nextMonth = $month->copy()->addMonth()->format('Y-m');

        return view('bookings.calendar', compact('month', 'prevMonth', 'nextMonth', 'grouped', 'bookings'));
    }

    public function update(Request $request, Booking $booking)
    {
        if ((int) $booking->store_id !== (int) $this->storeId()) abort(403);
        $request->validate([
            'status' => 'required|in:dikonfirmasi,dibatalkan,selesai',
            'schedule_date' => 'nullable|date',
        ]);

        DB::beginTransaction();
        try {
            if ($request->status === 'dikonfirmasi') {
                // Buat customer bila belum ada (cocokkan phone)
                $phone = preg_replace('/[^0-9+]/', '', (string) $booking->customer_phone);
                $customer = Customer::where('store_id', $booking->store_id)
                    ->when($phone, fn($q) => $q->where('phone', $phone))->first();
                if (!$customer) {
                    $customer = Customer::create([
                        'store_id' => $booking->store_id,
                        'name' => $booking->customer_name,
                        'phone' => $booking->customer_phone,
                    ]);
                }
                // Buat tiket otomatis bila belum ada
                // Tiket otomatis: {KODETOKO-}SVC-YYYYMMDD-NNNN (prefix kode toko
                // di depan bila stores.code terisi, fallback format lama).
                if (!$booking->ticket_id) {
                    $prefix = \App\Support\DocNumber::prefixForStore((int) $booking->store_id) . 'SVC-' . now()->format('Ymd') . '-';
                    $count = ServiceTicket::where('store_id', $booking->store_id)->where('ticket_no', 'like', $prefix . '%')->count() + 1;
                    do {
                        $ticketNo = $prefix . str_pad($count, 4, '0', STR_PAD_LEFT);
                        $count++;
                    } while (ServiceTicket::where('ticket_no', $ticketNo)->exists());

                    $ticket = ServiceTicket::create([
                        'ticket_no' => $ticketNo,
                        'store_id' => $booking->store_id,
                        'customer_id' => $customer->id,
                        'product_brand' => '-',
                        'product_type' => $booking->device ?? '-',
                        'complaint' => $booking->complaint ?? '-',
                        'status' => 'diterima',
                        'approval_status' => 'menunggu',
                        'priority' => 'normal',
                        'booking_id' => $booking->id,
                        'estimated_done_at' => now()->addDays(2),
                    ]);
                    ServiceLog::create([
                        'service_ticket_id' => $ticket->id,
                        'user_id' => auth()->id(),
                        'from_status' => null,
                        'to_status' => 'diterima',
                        'note' => 'Dibuat otomatis dari booking ' . $booking->code,
                    ]);
                    $booking->ticket_id = $ticket->id;
                }
            }
            $booking->status = $request->status;
            if ($request->filled('schedule_date')) $booking->schedule_date = $request->schedule_date;
            $booking->save();
            DB::commit();
            return back()->with('success', 'Booking ' . $booking->code . ' → ' . $request->status);
        } catch (\Throwable $e) {
            DB::rollBack();
            return back()->with('error', 'Gagal update booking: ' . $e->getMessage());
        }
    }

    public function bulkConfirm(Request $request)
    {
        $storeId = $this->storeId();
        $data = $request->validate([
            'booking_ids' => 'required|array|min:1|max:100',
            'booking_ids.*' => 'integer|exists:bookings,id',
        ], [
            'booking_ids.required' => 'Pilih minimal 1 booking yang belum ada tiketnya.',
            'booking_ids.min' => 'Pilih minimal 1 booking yang belum ada tiketnya.',
            'booking_ids.max' => 'Maksimal 100 booking per konfirmasi massal.',
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['booking_ids'])));
        $rows = Booking::where('store_id', $storeId)->whereIn('id', $ids)->get()->keyBy('id');

        $okLines = [];
        $skipLines = [];
        $failLines = [];

        foreach ($ids as $id) {
            $b = $rows->get($id);
            if (!$b) {
                $failLines[] = "#$id: bukan booking toko aktif";
                continue;
            }
            // Lewati yang sudah ada tiketnya (tidak dibuatkan tiket ganda).
            if ($b->ticket_id) {
                $skipLines[] = "$b->code: dilewati (sudah ada tiket #$b->ticket_id)";
                continue;
            }
            try {
                $ticketNo = DB::transaction(function () use ($b) {
                    $phone = preg_replace('/[^0-9+]/', '', (string) $b->customer_phone);
                    $customer = Customer::where('store_id', $b->store_id)
                        ->when($phone, fn ($q) => $q->where('phone', $phone))->first();
                    if (!$customer) {
                        $customer = Customer::create([
                            'store_id' => $b->store_id,
                            'name' => $b->customer_name,
                            'phone' => $b->customer_phone,
                        ]);
                    }
                    // Tiket otomatis (massal): prefix kode toko di depan bila terisi.
                    $prefix = \App\Support\DocNumber::prefixForStore((int) $b->store_id) . 'SVC-' . now()->format('Ymd') . '-';
                    $count = ServiceTicket::where('store_id', $b->store_id)
                        ->where('ticket_no', 'like', $prefix . '%')->count() + 1;
                    do {
                        $no = $prefix . str_pad($count, 4, '0', STR_PAD_LEFT);
                        $count++;
                    } while (ServiceTicket::where('ticket_no', $no)->exists());

                    $ticket = ServiceTicket::create([
                        'ticket_no' => $no,
                        'store_id' => $b->store_id,
                        'customer_id' => $customer->id,
                        'product_brand' => '-',
                        'product_type' => $b->device ?? '-',
                        'complaint' => $b->complaint ?? '-',
                        'status' => 'diterima',
                        'approval_status' => 'menunggu',
                        'priority' => 'normal',
                        'booking_id' => $b->id,
                        'estimated_done_at' => now()->addDays(2),
                    ]);
                    ServiceLog::create([
                        'service_ticket_id' => $ticket->id,
                        'user_id' => auth()->id(),
                        'from_status' => null,
                        'to_status' => 'diterima',
                        'note' => 'Dibuat otomatis dari booking ' . $b->code . ' (konfirmasi massal)',
                    ]);
                    $b->ticket_id = $ticket->id;
                    $b->status = 'dikonfirmasi';
                    $b->save();

                    return $no;
                });
                $okLines[] = "$b->code → tiket $ticketNo";
            } catch (\Throwable $e) {
                report($e);
                $failLines[] = "$b->code: gagal ({$e->getMessage()})";
            }
        }

        $summary = 'Konfirmasi massal: ' . count($okLines) . ' tiket dibuat, '
            . count($skipLines) . ' dilewati (sudah ada tiket), '
            . count($failLines) . ' gagal.';
        $detail = array_merge($okLines, $skipLines, $failLines);
        if ($detail) $summary .= ' | ' . implode('; ', $detail);

        if (count($okLines) === 0 && count($failLines) > 0) {
            return back()->with('error', $summary);
        }
        return back()->with('success', $summary);
    }

    public function bulkCancel(Request $request)
    {
        $storeId = $this->storeId();
        $data = $request->validate([
            'booking_ids' => 'required|array|min:1|max:100',
            'booking_ids.*' => 'integer|exists:bookings,id',
        ], [
            'booking_ids.required' => 'Pilih minimal 1 booking menunggu yang belum ada tiketnya.',
            'booking_ids.min' => 'Pilih minimal 1 booking menunggu yang belum ada tiketnya.',
            'booking_ids.max' => 'Maksimal 100 booking per pembatalan massal.',
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['booking_ids'])));
        $rows = Booking::where('store_id', $storeId)->whereIn('id', $ids)->get()->keyBy('id');

        $okLines = [];
        $skipLines = [];
        $failLines = [];

        foreach ($ids as $id) {
            $b = $rows->get($id);
            if (!$b) {
                $failLines[] = "#$id: bukan booking toko aktif";
                continue;
            }
            // Lewati yang sudah ada tiketnya (tiket tetap dipertahankan).
            if ($b->ticket_id) {
                $skipLines[] = "$b->code: dilewati (sudah ada tiket #$b->ticket_id)";
                continue;
            }
            // Hanya status menunggu yang boleh dibatalkan massal.
            if ($b->status !== 'menunggu') {
                $skipLines[] = "$b->code: dilewati (status $b->status, hanya menunggu yang bisa dibatalkan)";
                continue;
            }
            try {
                $b->status = 'dibatalkan';
                $b->save();
                $okLines[] = "$b->code → dibatalkan";
            } catch (\Throwable $e) {
                report($e);
                $failLines[] = "$b->code: gagal ({$e->getMessage()})";
            }
        }

        $summary = 'Pembatalan massal: ' . count($okLines) . ' dibatalkan, '
            . count($skipLines) . ' dilewati (sudah bertiket / bukan menunggu), '
            . count($failLines) . ' gagal.';
        $detail = array_merge($okLines, $skipLines, $failLines);
        if ($detail) $summary .= ' | ' . implode('; ', $detail);

        if (count($okLines) === 0 && count($failLines) > 0) {
            return back()->with('error', $summary);
        }
        return back()->with('success', $summary);
    }

    public function bulkRemind(Request $request)
    {
        $storeId = $this->storeId();
        $data = $request->validate([
            'booking_ids' => 'required|array|min:1|max:50',
            'booking_ids.*' => 'integer|exists:bookings,id',
        ], [
            'booking_ids.required' => 'Pilih minimal 1 booking menunggu yang belum ada tiketnya.',
            'booking_ids.min' => 'Pilih minimal 1 booking menunggu yang belum ada tiketnya.',
            'booking_ids.max' => 'Maksimal 50 booking per pengingat massal.',
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['booking_ids'])));
        $rows = Booking::where('store_id', $storeId)->whereIn('id', $ids)->get()->keyBy('id');

        $okLines = [];
        $skipLines = [];
        $failLines = [];

        foreach ($ids as $id) {
            $b = $rows->get($id);
            if (!$b) {
                $failLines[] = "#$id: bukan booking toko aktif";
                continue;
            }
            // Lewati yang sudah ada tiketnya (pengingat hanya untuk booking menunggu tanpa tiket).
            if ($b->ticket_id) {
                $skipLines[] = "$b->code: dilewati (sudah ada tiket #$b->ticket_id)";
                continue;
            }
            // Hanya status menunggu yang diingatkan massal.
            if ($b->status !== 'menunggu') {
                $skipLines[] = "$b->code: dilewati (status $b->status, hanya menunggu yang diingatkan)";
                continue;
            }
            // Lewati tanpa nomor HP.
            if (trim((string) ($b->customer_phone ?? '')) === '') {
                $skipLines[] = "$b->code: dilewati (tanpa nomor HP)";
                continue;
            }
            try {
                $jadwal = $b->schedule_date
                    ? \Carbon\Carbon::parse($b->schedule_date)->format('d/m/Y H:i')
                    : '-';
                $msg = WaService::render('booking_konfirmasi', [
                    'nama' => $b->customer_name,
                    'tiket' => $b->code,
                    'status' => $b->status,
                    'device' => $b->device ?? '-',
                    'jadwal' => $jadwal,
                    'total' => '',
                    'track' => url('/track'),
                ]);
                if ($msg === '') {
                    $msg = "Halo {$b->customer_name}, pengingat booking service *{$b->code}* "
                        . "({$b->device}) jadwal {$jadwal}. "
                        . "Mohon datang tepat waktu. Balas OK untuk konfirmasi.";
                }
                $sent = WaService::send(
                    $b->customer_phone ?? '',
                    $msg,
                    'booking_reminder',
                    $b->store_id
                );
                if (!$sent) {
                    $failLines[] = "$b->code: gagal terkirim ke {$b->customer_phone}";
                    continue;
                }
                $okLines[] = "$b->code → diingatkan ke {$b->customer_phone}";
            } catch (\Throwable $e) {
                report($e);
                $failLines[] = "$b->code: gagal ({$e->getMessage()})";
            }
        }

        $summary = 'Pengingat massal: ' . count($okLines) . ' terkirim, '
            . count($skipLines) . ' dilewati (tanpa nomor / sudah bertiket / bukan menunggu), '
            . count($failLines) . ' gagal.';
        $detail = array_merge($okLines, $skipLines, $failLines);
        if ($detail) $summary .= ' | ' . implode('; ', $detail);

        if (count($okLines) === 0 && count($failLines) > 0) {
            return back()->with('error', $summary);
        }
        return back()->with('success', $summary);
    }

    /**
     * Pindah massal booking antar toko (cermin bulkTransferStore tiket):
     * update store_id tanpa ubah status/tiket.
     * Hanya menunggu/dikonfirmasi — baris selesai/dibatalkan dilewati,
     * baris bertiket (ticket_id NOT NULL) dilewati (kompleks: tiket +
     * customer milik toko asal). Maks 50 per submit, tanpa migration.
     */
    public function bulkTransfer(Request $request)
    {
        $storeId = $this->storeId();
        $data = $request->validate([
            'booking_ids' => 'required|array|min:1|max:50',
            'booking_ids.*' => 'integer|exists:bookings,id',
            'to_store_id' => 'required|integer|exists:stores,id',
        ], [
            'booking_ids.required' => 'Pilih minimal 1 booking menunggu/dikonfirmasi.',
            'booking_ids.min' => 'Pilih minimal 1 booking menunggu/dikonfirmasi.',
            'booking_ids.max' => 'Maksimal 50 booking per pindah massal.',
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

        $ids = array_values(array_unique(array_map('intval', $data['booking_ids'])));
        $rows = Booking::where('store_id', $storeId)->whereIn('id', $ids)->get()->keyBy('id');

        $okLines = [];
        $skipLines = [];
        $failLines = [];

        foreach ($ids as $id) {
            $b = $rows->get($id);
            if (!$b) {
                $failLines[] = "#$id: bukan booking toko aktif";
                continue;
            }
            // Tiket linked jangan dipindah (kompleks: tiket + customer milik toko asal).
            if ($b->ticket_id) {
                $skipLines[] = "$b->code: dilewati (sudah ada tiket #$b->ticket_id)";
                continue;
            }
            // Hanya menunggu/dikonfirmasi yang boleh dipindah massal.
            if (!in_array($b->status, ['menunggu', 'dikonfirmasi'], true)) {
                $skipLines[] = "$b->code: dilewati (status $b->status, hanya menunggu/dikonfirmasi yang bisa dipindah)";
                continue;
            }
            try {
                DB::transaction(function () use ($b, $dest) {
                    $row = Booking::whereKey($b->id)->lockForUpdate()->firstOrFail();
                    if ($row->ticket_id) {
                        abort(422, 'Sudah ada tiket, tidak bisa dipindah toko.');
                    }
                    if (!in_array($row->status, ['menunggu', 'dikonfirmasi'], true)) {
                        abort(422, 'Status ' . $row->status . ' tidak bisa dipindah toko.');
                    }
                    if ((int) $row->store_id === (int) $dest->id) {
                        abort(422, 'Toko tujuan sama dengan toko asal.');
                    }
                    $row->store_id = (int) $dest->id;
                    $row->save();
                });
                $okLines[] = "$b->code → {$dest->name}";
            } catch (\Throwable $e) {
                report($e);
                $failLines[] = "$b->code: gagal ({$e->getMessage()})";
            }
        }

        $summary = 'Pindah toko massal ke ' . $dest->name . ': ' . count($okLines) . ' berhasil, '
            . count($skipLines) . ' dilewati (sudah bertiket / bukan menunggu-dikonfirmasi), '
            . count($failLines) . ' gagal.';
        $detail = array_merge($okLines, $skipLines, $failLines);
        if ($detail) $summary .= ' | ' . implode('; ', $detail);

        if (count($okLines) === 0 && count($failLines) > 0) {
            return back()->with('error', $summary);
        }
        return back()->with('success', $summary);
    }

    /**
     * Geser jadwal booking massal (cermin bulkTransfer/bulkCancel):
     * update schedule_date massal tanpa migration.
     * Hanya menunggu/dikonfirmasi TANPA tiket — baris bertiket
     * (ticket_id NOT NULL) dilewati + dilaporkan, baris selesai/
     * dibatalkan dilewati. Target: tanggal baru ATAU geser +N hari
     * (bila keduanya diisi, tanggal baru menang). Maks 50 per submit.
     */
    public function bulkReschedule(Request $request)
    {
        $storeId = $this->storeId();
        $data = $request->validate([
            'booking_ids' => 'required|array|min:1|max:50',
            'booking_ids.*' => 'integer|exists:bookings,id',
            'target_date' => 'nullable|date',
            'shift_days' => 'nullable|integer|min:1|max:90',
        ], [
            'booking_ids.required' => 'Pilih minimal 1 booking menunggu/dikonfirmasi.',
            'booking_ids.min' => 'Pilih minimal 1 booking menunggu/dikonfirmasi.',
            'booking_ids.max' => 'Maksimal 50 booking per geser jadwal massal.',
            'target_date.date' => 'Tanggal baru tidak valid (format: YYYY-MM-DD HH:MM).',
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
            $targetCarbon = $target !== '' ? \Carbon\Carbon::parse($target) : null;
        } catch (\Throwable $e) {
            return back()->with('error', 'Tanggal baru tidak valid (format: YYYY-MM-DD HH:MM).');
        }

        $ids = array_values(array_unique(array_map('intval', $data['booking_ids'])));
        $rows = Booking::where('store_id', $storeId)->whereIn('id', $ids)->get()->keyBy('id');

        $okLines = [];
        $skipLines = [];
        $failLines = [];

        foreach ($ids as $id) {
            $b = $rows->get($id);
            if (!$b) {
                $failLines[] = "#$id: bukan booking toko aktif";
                continue;
            }
            // Lewati yang sudah ada tiketnya (jadwal ikut tiket service).
            if ($b->ticket_id) {
                $skipLines[] = "$b->code: dilewati (sudah ada tiket #$b->ticket_id)";
                continue;
            }
            // Hanya menunggu/dikonfirmasi yang boleh digeser massal.
            if (!in_array($b->status, ['menunggu', 'dikonfirmasi'], true)) {
                $skipLines[] = "$b->code: dilewati (status $b->status, hanya menunggu/dikonfirmasi yang bisa digeser)";
                continue;
            }
            try {
                $old = $b->schedule_date
                    ? \Carbon\Carbon::parse($b->schedule_date)->format('d/m/Y H:i')
                    : '-';
                $newDate = $targetCarbon
                    ? $targetCarbon->copy()
                    : \Carbon\Carbon::parse($b->schedule_date ?? now())->addDays($shift);
                DB::transaction(function () use ($b, $newDate) {
                    $row = Booking::whereKey($b->id)->lockForUpdate()->firstOrFail();
                    if ($row->ticket_id) {
                        abort(422, 'Sudah ada tiket, jadwal ikut tiket service.');
                    }
                    if (!in_array($row->status, ['menunggu', 'dikonfirmasi'], true)) {
                        abort(422, 'Status ' . $row->status . ' tidak bisa digeser.');
                    }
                    $row->schedule_date = $newDate;
                    $row->save();
                });
                $okLines[] = "$b->code: $old → " . $newDate->format('d/m/Y H:i');
            } catch (\Throwable $e) {
                report($e);
                $failLines[] = "$b->code: gagal ({$e->getMessage()})";
            }
        }

        $mode = $targetCarbon
            ? 'ke ' . $targetCarbon->format('d/m/Y H:i')
            : "+$shift hari";
        $summary = 'Geser jadwal massal ' . $mode . ': ' . count($okLines) . ' digeser, '
            . count($skipLines) . ' dilewati (sudah bertiket / bukan menunggu-dikonfirmasi), '
            . count($failLines) . ' gagal.';
        $detail = array_merge($okLines, $skipLines, $failLines);
        if ($detail) $summary .= ' | ' . implode('; ', $detail);

        if (count($okLines) === 0 && count($failLines) > 0) {
            return back()->with('error', $summary);
        }
        return back()->with('success', $summary);
    }

    public function remind(Booking $booking)
    {
        if ((int) $booking->store_id !== (int) $this->storeId()) abort(403);
        $jadwal = $booking->schedule_date ? \Carbon\Carbon::parse($booking->schedule_date)->format('d/m/Y H:i') : '-';
        $msg = WaService::render('booking_konfirmasi', [
            'nama' => $booking->customer_name, 'tiket' => $booking->code, 'status' => $booking->status,
            'device' => $booking->device ?? '-', 'jadwal' => $jadwal,
            'total' => '', 'track' => url('/track'),
        ]);
        if ($msg === '') {
            $msg = "Halo {$booking->customer_name}, pengingat booking service *{$booking->code}* ({$booking->device}) jadwal {$jadwal}. Mohon datang tepat waktu. Balas OK untuk konfirmasi.";
        }
        try {
            WaService::send($booking->customer_phone ?? '', $msg, 'booking_reminder', $booking->store_id);
        } catch (\Throwable $e) {}
        return back()->with('success', 'Pengingat WA dikirim ke ' . $booking->customer_phone . '.');
    }

    public function destroy(Booking $booking)
    {
        if ((int) $booking->store_id !== (int) $this->storeId()) abort(403);
        // Kolom booking.ticket_id & service_tickets.booking_id tanpa FK: bersihkan
        // dua arah agar tiket (yang mungkin sudah dikerjakan) tidak menunjuk ke
        // booking yang sudah tidak ada. Tiketnya sendiri tetap dipertahankan.
        DB::transaction(function () use ($booking) {
            try {
                if ($booking->ticket_id) {
                    $t = ServiceTicket::find($booking->ticket_id);
                    if ($t && (int) ($t->booking_id ?? 0) === (int) $booking->id) $t->update(['booking_id' => null]);
                }
                ServiceTicket::where('booking_id', $booking->id)->update(['booking_id' => null]);
            } catch (\Throwable $e) { report($e); }
            $booking->delete();
        });
        return back()->with('success', 'Booking dihapus.');
    }
}
