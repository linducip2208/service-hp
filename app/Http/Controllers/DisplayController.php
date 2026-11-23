<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DisplayController extends Controller
{
    /**
     * Layar antrean TV — publik, tanpa auth.
     * Hanya info non-sensitif: nomor tiket + status + waktu + label toko.
     * Tanpa: nama, HP, biaya, keluhan, IMEI, teknisi.
     */
    public function index(Request $request)
    {
        $storeId = $request->query('store');
        $storeId = is_numeric($storeId) ? (int) $storeId : null;

        $stores = collect();
        $activeStore = null;

        try {
            if (\Schema::hasTable('stores')) {
                $stores = DB::table('stores')
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'name']);
                if ($storeId) {
                    $activeStore = $stores->firstWhere('id', $storeId);
                    if (! $activeStore) {
                        $storeId = null; // ID tak valid → fallback semua toko
                    }
                }
            }
        } catch (\Throwable $e) {
            $stores = collect();
            $storeId = null;
        }

        $ready = collect();
        $process = collect();

        try {
            $q = DB::table('service_tickets as t')
                ->join('stores as s', 's.id', '=', 't.store_id')
                ->select(
                    't.ticket_no',
                    't.status',
                    't.priority',
                    't.created_at',
                    't.updated_at',
                    't.estimated_done_at',
                    's.name as store_name'
                )
                ->where('s.is_active', true)
                ->whereNotIn('t.status', ['diserahkan', 'batal']);

            if ($storeId) {
                $q->where('t.store_id', $storeId);
            }

            $tickets = $q
                ->orderByRaw("CASE WHEN t.status = 'siap_diambil' THEN 0 ELSE 1 END")
                ->orderByDesc('t.updated_at')
                ->limit(60)
                ->get();

            $ready = $tickets->where('status', 'siap_diambil')->values();
            $process = $tickets->where('status', '!=', 'siap_diambil')->values();
        } catch (\Throwable $e) {
            // tabel belum ada → tampilkan layar kosong yang aman
        }

        $promos = collect();

        try {
            if (\Schema::hasTable('promos')) {
                $today = \Carbon\Carbon::today()->toDateString();
                $pq = DB::table('promos')
                    ->where('is_active', true)
                    ->where(function ($w) use ($today) {
                        $w->whereNull('start_date')->orWhere('start_date', '<=', $today);
                    })
                    ->where(function ($w) use ($today) {
                        $w->whereNull('end_date')->orWhere('end_date', '>=', $today);
                    })
                    ->where(function ($w) {
                        $w->where('quota', '<=', 0)->orWhereRaw('`used` < `quota`');
                    });

                if ($storeId) {
                    $pq->where(function ($w) use ($storeId) {
                        $w->whereNull('store_id')->orWhere('store_id', $storeId);
                    });
                }

                $promos = $pq
                    ->orderByRaw('CASE WHEN end_date IS NULL THEN 1 ELSE 0 END')
                    ->orderBy('end_date')
                    ->limit(20)
                    ->get(['code', 'name', 'type', 'value', 'end_date', 'quota', 'used']);
            }
        } catch (\Throwable $e) {
            $promos = collect();
        }

        return view('display.index', compact('stores', 'activeStore', 'storeId', 'ready', 'process', 'promos'));
    }
}
