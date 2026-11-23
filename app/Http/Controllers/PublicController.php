<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PublicController extends Controller
{
    public function trackForm()
    {
        return view('public.track');
    }

    public function track(Request $request)
    {
        $request->validate(['ticket_no' => 'required|max:50']);
        $q = trim((string) $request->ticket_no);
        $ticket = null;
        $matches = collect();
        $queueAhead = null;
        // Definisi antrean: tiket AKTIF = status selain 'diserahkan'/'batal'
        // (konsisten dgn ServiceController@index + DailyDigestService::ESCALATION_CLOSED_STATUSES).
        // Posisi = jumlah tiket aktif yg LEBIH TUA (created_at, tiebreak id)
        // di TOKO YANG SAMA (store_id sama). Tiket final (diserahkan/batal)
        // tidak menampilkan info antrean.
        $closedStatuses = ['diserahkan', 'batal'];
        $queueCountFor = function ($storeId, $createdAt, $id) use ($closedStatuses) {
            if (empty($storeId)) return null;
            try {
                return (int) DB::table('service_tickets')
                    ->where('store_id', $storeId)
                    ->whereNotIn('status', $closedStatuses)
                    ->where(function ($w) use ($createdAt, $id) {
                        $w->where('created_at', '<', $createdAt)
                            ->orWhere(function ($w2) use ($createdAt, $id) {
                                $w2->where('created_at', '=', $createdAt)
                                    ->where('id', '<', $id);
                            });
                    })
                    ->count();
            } catch (\Throwable $e) { return null; }
        };
        try {
            $ticket = DB::table('service_tickets')->where('ticket_no', $q)->first();
            if ($ticket) {
                $logs = DB::table('service_logs')->where('service_ticket_id', $ticket->id)->orderBy('created_at')->get();
                if (!in_array($ticket->status ?? '', $closedStatuses, true)) {
                    $queueAhead = $queueCountFor($ticket->store_id ?? null, $ticket->created_at, $ticket->id);
                }
            } else {
                $logs = collect();
                $digits = preg_replace('/[^0-9]/', '', $q);
                $query = DB::table('service_tickets as t')
                    ->leftJoin('customers as c', 'c.id', '=', 't.customer_id')
                    ->select('t.id', 't.ticket_no', 't.store_id', 't.product_brand', 't.product_type', 't.status', 't.created_at', 't.updated_at')
                    ->where(function ($w) use ($q, $digits) {
                        $w->where('t.ticket_no', 'like', '%' . $q . '%')
                            ->orWhere('t.imei', 'like', '%' . $q . '%');
                        if (strlen($digits) >= 6) {
                            // Cocokkan no HP pelanggan (abaikan format 08/628/+62secukupnya via LIKE digit).
                            $w->orWhere('c.phone', 'like', '%' . $digits . '%')
                                ->orWhere('c.phone', 'like', '%' . ltrim($digits, '0') . '%');
                        }
                    })
                    ->orderByDesc('t.created_at')
                    ->limit(20);
                $matches = $query->get();
                // Antrean per baris: hanya utk tiket aktif (selain diserahkan/batal).
                foreach ($matches as $m) {
                    $m->queue_ahead = !in_array($m->status ?? '', $closedStatuses, true)
                        ? $queueCountFor($m->store_id ?? null, $m->created_at, $m->id)
                        : null;
                }
            }
        } catch (\Throwable $e) { $logs = collect(); $matches = collect(); $queueAhead = null; }
        return view('public.track', compact('ticket', 'logs', 'matches', 'queueAhead'));
    }

    public function bookingForm()
    {
        $stores = collect();
        try { if (\Schema::hasTable('stores')) $stores = DB::table('stores')->where('is_active', true)->get(); } catch (\Throwable $e) {}
        return view('public.booking', compact('stores'));
    }

    public function bookingStore(Request $request)
    {
        $request->validate([
            'store_id' => 'required',
            'customer_name' => 'required|max:100',
            'customer_phone' => 'required|max:20',
            'device' => 'required|max:100',
            'complaint' => 'required',
            'schedule_date' => 'nullable|date',
        ]);
        try {
            DB::table('bookings')->insert([
                // Kode booking: {KODETOKO-}BK-yymmdd-XXXX (prefix kode toko di
                // depan bila stores.code terisi, fallback format lama bila kosong).
                'code' => \App\Support\DocNumber::prefixForStore((int) $request->store_id) . 'BK-' . now()->format('ymd') . '-' . strtoupper(\Str::random(4)),
                'store_id' => $request->store_id,
                'customer_name' => $request->customer_name,
                'customer_phone' => $request->customer_phone,
                'device' => $request->device,
                'complaint' => $request->complaint,
                'schedule_date' => $request->schedule_date,
                'status' => 'menunggu',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            return back()->with('error', 'Gagal menyimpan booking: ' . $e->getMessage())->withInput();
        }
        return back()->with('success', 'Booking berhasil! Kami hubungi via WhatsApp untuk konfirmasi.');
    }
}
