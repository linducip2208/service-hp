<?php

namespace App\Http\Controllers;

use App\Services\DailyDigestService;
use App\Support\PeriodLock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * PUSAT PENGINGAT — agregasi read-only semua item pengingat.
 *
 * Sumber tiap seksi (tanpa logika baru, hanya baca ulang):
 * - Booking H-1          : DailyDigestService::tomorrowBookings()
 * - Piutang H-1          : DailyDigestService::tomorrowPiutangs()
 * - Garansi H-0..H+7     : DailyDigestService::expiringWarranties()
 * - Garansi H-8..H-30    : DailyDigestService::earlyExpiringWarranties()
 * - Ambil >3 hari        : DailyDigestService::stagnantPickupTickets()
 * - Opname >30 hari      : DailyDigestService::opnameReminderBlock() + max stock_movements
 * - Kunci periode        : DailyDigestService::periodLockReminderBlock() + PeriodLock::get()
 * - Promo kedaluwarsa    : DailyDigestService::expiredPromoCandidates()
 * - Hutang tempo ≤7 hari : DailyDigestService::dueDebts() + query due_date (DashboardController alert)
 * - Unit service yatim   : DashboardController@health cek #7 (product_items status service tanpa tiket aktif)
 * - Widget Perlu Perhatian: DashboardController@index $alerts
 *   (tiket terlambat, booking >24 jam, WA gagal/antri, stok menipis, promo <7 hari)
 *
 * SENGAJA tanpa tombol "Kirim Semua WA" (risiko spam massal).
 * Tiap baris hanya link ke aksi manual existing (index terkait / health).
 * Read-only: tanpa migration, tanpa pengiriman WA, tanpa update data.
 */
class ReminderController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(
            in_array(auth()->user()->role ?? '', ['superadmin', 'owner', 'admin'], true),
            403,
            'Hanya owner/admin.'
        );

        $storeId = session('active_store_id');
        $today = now()->startOfDay();
        $tomorrow = now()->addDay()->toDateString();

        $sections = [];

        // ---------- 1. HUTANG/PIUTANG LEWAT JATUH TEMPO (mendesak) ----------
        try {
            $overdues = collect();
            if (\Schema::hasTable('debts')) {
                $overdues = DB::table('debts')
                    ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
                    ->where('status', '!=', 'lunas')
                    ->whereNotNull('due_date')
                    ->whereDate('due_date', '<', $today->toDateString())
                    ->orderBy('due_date')
                    ->limit(20)
                    ->get()
                    ->map(fn ($d) => [
                        'judul' => ($d->type === 'hutang' ? 'Hutang' : 'Piutang') . ' — ' . ($d->party_name ?: ('#' . $d->id)),
                        'detail' => 'Sisa Rp ' . number_format((float) $d->remaining, 0, ',', '.')
                            . ' • jatuh tempo ' . $d->due_date,
                        'umur' => (int) $today->diffInDays(\Carbon\Carbon::parse($d->due_date)->startOfDay()) . ' hari lewat tempo',
                    ]);
                $overdueTotal = (int) DB::table('debts')
                    ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
                    ->where('status', '!=', 'lunas')
                    ->whereNotNull('due_date')
                    ->whereDate('due_date', '<', $today->toDateString())
                    ->count();
            } else {
                $overdueTotal = 0;
            }
            if ($overdues->isNotEmpty()) {
                $sections[] = [
                    'prioritas' => 'Mendesak',
                    'tipe' => 'Hutang/piutang lewat jatuh tempo',
                    'judul' => 'Lewat jatuh tempo (' . $overdueTotal . ')',
                    'items' => $overdues,
                    'total' => $overdueTotal,
                    'aksi_url' => route('debts.index'),
                    'aksi_label' => 'Buka Hutang/Piutang → ingatkan manual per baris',
                ];
            }
        } catch (\Throwable $e) {
        }

        // ---------- 2. TIKET SERVICE TERLAMBAT >3 HARI (widget Perlu Perhatian) ----------
        try {
            $lateTickets = collect();
            $lateTotal = 0;
            if (\Schema::hasTable('service_tickets')) {
                $lateTotal = (int) DB::table('service_tickets')
                    ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
                    ->whereNotIn('status', ['diserahkan', 'batal'])
                    ->where('created_at', '<=', now()->subDays(3))
                    ->count();
                $lateTickets = DB::table('service_tickets')
                    ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
                    ->whereNotIn('status', ['diserahkan', 'batal'])
                    ->where('created_at', '<=', now()->subDays(3))
                    ->orderBy('created_at')
                    ->limit(20)
                    ->get()
                    ->map(fn ($t) => [
                        'judul' => 'Tiket ' . ($t->ticket_no ?: ('#' . $t->id)) . ' [' . $t->status . ']',
                        'detail' => trim(($t->product_brand ?? '') . ' ' . ($t->product_type ?? '')) ?: '-',
                        'umur' => (int) $today->diffInDays(\Carbon\Carbon::parse($t->created_at)->startOfDay()) . ' hari sejak dibuat',
                    ]);
            }
            if ($lateTickets->isNotEmpty()) {
                $sections[] = [
                    'prioritas' => 'Mendesak',
                    'tipe' => 'Tiket terlambat',
                    'judul' => 'Tiket service terlambat >3 hari (' . $lateTotal . ')',
                    'items' => $lateTickets,
                    'total' => $lateTotal,
                    'aksi_url' => route('services.index'),
                    'aksi_label' => 'Buka Service → tindak lanjut manual',
                ];
            }
        } catch (\Throwable $e) {
        }

        // ---------- 3. UNIT SIAP DIAMBIL >3 HARI (ambil HP) ----------
        try {
            $pickups = DailyDigestService::stagnantPickupTickets($storeId);
            if ($pickups->isNotEmpty()) {
                $items = $pickups->take(20)->map(function ($t) use ($today) {
                    try {
                        $hari = (int) \Carbon\Carbon::parse($t->updated_at)->startOfDay()->diffInDays($today);
                    } catch (\Throwable $e) {
                        $hari = 4;
                    }

                    return [
                        'judul' => 'Tiket ' . $t->ticket_no . ' — ' . ($t->customer->name ?? 'Pelanggan'),
                        'detail' => 'HP ' . ($t->customer->phone ?: 'tanpa nomor HP'),
                        'umur' => $hari . ' hari menunggu diambil',
                    ];
                });
                $sections[] = [
                    'prioritas' => 'Mendesak',
                    'tipe' => 'Siap diambil',
                    'judul' => 'Unit siap diambil >3 hari (' . $pickups->count() . ')',
                    'items' => $items,
                    'total' => $pickups->count(),
                    'aksi_url' => route('services.index'),
                    'aksi_label' => 'Buka Service → kirim pengingat ambil manual per tiket',
                ];
            }
        } catch (\Throwable $e) {
        }

        // ---------- 4. GARANSI KEDALUWARSA (aktif, end_date < hari ini) ----------
        try {
            $expired = DailyDigestService::expiredWarrantyCandidates($storeId);
            if ($expired->isNotEmpty()) {
                $items = $expired->take(20)->map(function ($w) use ($today) {
                    try {
                        $hari = (int) \Carbon\Carbon::parse($w->end_date)->startOfDay()->diffInDays($today);
                    } catch (\Throwable $e) {
                        $hari = 0;
                    }

                    return [
                        'judul' => 'Garansi W#' . $w->id . ' — ' . ($w->customer->name ?? ('ref ' . $w->ref_type . ' #' . $w->ref_id)),
                        'detail' => 'Berakhir ' . $w->end_date,
                        'umur' => $hari . ' hari kedaluwarsa',
                    ];
                });
                $sections[] = [
                    'prioritas' => 'Mendesak',
                    'tipe' => 'Garansi kedaluwarsa',
                    'judul' => 'Garansi kedaluwarsa belum ditandai selesai (' . $expired->count() . ')',
                    'items' => $items,
                    'total' => $expired->count(),
                    'aksi_url' => route('warranties.index'),
                    'aksi_label' => 'Buka Garansi → tandai selesai manual per baris',
                ];
            }
        } catch (\Throwable $e) {
        }

        // ---------- 5. PROMO KEDALUWARSA / KUOTA HABIS ----------
        try {
            $promos = DailyDigestService::expiredPromoCandidates($storeId);
            if ($promos->isNotEmpty()) {
                $items = $promos->take(20)->map(fn ($p) => [
                    'judul' => 'Promo ' . ($p->code ?: ('#' . $p->id)) . ' — ' . ($p->name ?? ''),
                    'detail' => 'Berakhir ' . ($p->end_date ?: '-') . ' • kuota ' . ($p->quota ?: '∞') . ' / terpakai ' . ($p->used ?? 0),
                    'umur' => 'nonaktif otomatis oleh digest harian',
                ]);
                $sections[] = [
                    'prioritas' => 'Mendesak',
                    'tipe' => 'Promo kedaluwarsa',
                    'judul' => 'Promo kedaluwarsa / kuota habis (' . $promos->count() . ')',
                    'items' => $items,
                    'total' => $promos->count(),
                    'aksi_url' => route('promos.index'),
                    'aksi_label' => 'Buka Promo → perpanjang/nonaktifkan manual',
                ];
            }
        } catch (\Throwable $e) {
        }

        // ---------- 6. BOOKING H-1 (jadwal besok) ----------
        try {
            $bookings = DailyDigestService::tomorrowBookings($storeId, $tomorrow);
            if ($bookings->isNotEmpty()) {
                $items = $bookings->take(20)->map(function ($b) {
                    try {
                        $jadwal = $b->schedule_date ? \Carbon\Carbon::parse($b->schedule_date)->format('d/m/Y H:i') : '-';
                    } catch (\Throwable $e) {
                        $jadwal = '-';
                    }

                    return [
                        'judul' => 'Booking ' . $b->code . ' — ' . ($b->customer_name ?: '-'),
                        'detail' => 'Jadwal ' . $jadwal . ' • ' . ($b->device ?: '-') . ' • ' . ($b->customer_phone ?: 'tanpa nomor'),
                        'umur' => 'H-1 (besok)',
                    ];
                });
                $sections[] = [
                    'prioritas' => 'Besok',
                    'tipe' => 'Booking H-1',
                    'judul' => 'Booking jadwal besok (' . $bookings->count() . ')',
                    'items' => $items,
                    'total' => $bookings->count(),
                    'aksi_url' => route('bookings.index'),
                    'aksi_label' => 'Buka Booking → kirim pengingat manual per booking',
                ];
            }
        } catch (\Throwable $e) {
        }

        // ---------- 7. PIUTANG H-1 (jatuh tempo besok) ----------
        try {
            $debts = DailyDigestService::tomorrowPiutangs($storeId, $tomorrow);
            if ($debts->isNotEmpty()) {
                $items = $debts->take(20)->map(fn ($d) => [
                    'judul' => 'Piutang — ' . ($d->party_name ?: ('#' . $d->id)),
                    'detail' => 'Sisa Rp ' . number_format((float) $d->remaining, 0, ',', '.') . ' • jatuh tempo besok',
                    'umur' => 'H-1 (besok)',
                ]);
                $sections[] = [
                    'prioritas' => 'Besok',
                    'tipe' => 'Piutang H-1',
                    'judul' => 'Piutang jatuh tempo besok (' . $debts->count() . ')',
                    'items' => $items,
                    'total' => $debts->count(),
                    'aksi_url' => route('debts.index'),
                    'aksi_label' => 'Buka Hutang/Piutang → ingatkan manual per baris',
                ];
            }
        } catch (\Throwable $e) {
        }

        // ---------- 8. GARANSI H-0 s/d H+7 ----------
        try {
            $warrs = DailyDigestService::expiringWarranties($storeId);
            if ($warrs->isNotEmpty()) {
                $items = $warrs->take(20)->map(function ($w) {
                    try {
                        $sisa = max(0, (int) now()->startOfDay()->diffInDays(\Carbon\Carbon::parse($w->end_date)->startOfDay(), false));
                    } catch (\Throwable $e) {
                        $sisa = 0;
                    }

                    return [
                        'judul' => 'Garansi W#' . $w->id . ' — ' . ($w->customer->name ?? '-'),
                        'detail' => 'Berakhir ' . $w->end_date . ' • ' . ($w->customer->phone ?: 'tanpa nomor'),
                        'umur' => $sisa === 0 ? 'berakhir hari ini' : 'H-' . $sisa,
                    ];
                });
                $sections[] = [
                    'prioritas' => 'Minggu ini',
                    'tipe' => 'Garansi H-7',
                    'judul' => 'Garansi berakhir ≤7 hari (' . $warrs->count() . ')',
                    'items' => $items,
                    'total' => $warrs->count(),
                    'aksi_url' => route('warranties.index'),
                    'aksi_label' => 'Buka Garansi → ingatkan klaim manual per baris',
                ];
            }
        } catch (\Throwable $e) {
        }

        // ---------- 9. GARANSI H-8 s/d H-30 (pengingat awal) ----------
        try {
            $early = DailyDigestService::earlyExpiringWarranties($storeId);
            if ($early->isNotEmpty()) {
                $items = $early->take(20)->map(function ($w) {
                    try {
                        $sisa = max(0, (int) now()->startOfDay()->diffInDays(\Carbon\Carbon::parse($w->end_date)->startOfDay(), false));
                    } catch (\Throwable $e) {
                        $sisa = 0;
                    }

                    return [
                        'judul' => 'Garansi W#' . $w->id . ' — ' . ($w->customer->name ?? '-'),
                        'detail' => 'Berakhir ' . $w->end_date,
                        'umur' => 'H-' . $sisa,
                    ];
                });
                $sections[] = [
                    'prioritas' => 'Minggu ini',
                    'tipe' => 'Garansi H-30',
                    'judul' => 'Garansi berakhir 8–30 hari (pengingat awal, ' . $early->count() . ')',
                    'items' => $items,
                    'total' => $early->count(),
                    'aksi_url' => route('warranties.index'),
                    'aksi_label' => 'Buka Garansi → ingatkan awal manual per baris',
                ];
            }
        } catch (\Throwable $e) {
        }

        // ---------- 10. HUTANG/PIUTANG TEMPO ≤7 HARI (belum lewat) ----------
        try {
            $dues = DailyDigestService::dueDebts($storeId, 20);
            $dueCount = 0;
            if (\Schema::hasTable('debts')) {
                $dueCount = (int) DB::table('debts')
                    ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
                    ->where('status', '!=', 'lunas')
                    ->whereNotNull('due_date')
                    ->whereDate('due_date', '<=', now()->addDays(7)->toDateString())
                    ->whereDate('due_date', '>=', $today->toDateString())
                    ->count();
            }
            if ($dues->isNotEmpty()) {
                $items = $dues->map(function ($d) {
                    try {
                        $diff = (int) now()->startOfDay()->diffInDays(\Carbon\Carbon::parse($d->due_date)->startOfDay(), false);
                        $umur = $diff < 0 ? abs($diff) . ' hari lewat tempo' : ($diff === 0 ? 'jatuh tempo hari ini' : 'H-' . $diff);
                    } catch (\Throwable $e) {
                        $umur = '-';
                    }

                    return [
                        'judul' => ($d->party_name ?: ('#' . ($d->id ?? '?'))),
                        'detail' => 'Sisa Rp ' . number_format((float) ($d->remaining ?? 0), 0, ',', '.')
                            . ' • tempo ' . ($d->due_date ?? '-'),
                        'umur' => $umur,
                    ];
                });
                $sections[] = [
                    'prioritas' => 'Minggu ini',
                    'tipe' => 'Hutang tempo ≤7 hari',
                    'judul' => 'Hutang/piutang jatuh tempo ≤7 hari (' . max($dueCount, $dues->count()) . ', termasuk lewat tempo)',
                    'items' => $items,
                    'total' => max($dueCount, $dues->count()),
                    'aksi_url' => route('debts.index'),
                    'aksi_label' => 'Buka Hutang/Piutang → ingatkan manual per baris',
                ];
            }
        } catch (\Throwable $e) {
        }

        // ---------- 11. PROMO KEDALUWARSA <7 HARI (widget Perlu Perhatian) ----------
        try {
            $promoSoon = collect();
            $promoSoonTotal = 0;
            if (\Schema::hasTable('promos')) {
                $promoSoonTotal = (int) DB::table('promos')
                    ->when($storeId, fn ($q) => $q->where(fn ($w) => $w->where('store_id', $storeId)->orWhereNull('store_id')))
                    ->where('is_active', true)
                    ->whereNotNull('end_date')
                    ->whereDate('end_date', '>=', now()->toDateString())
                    ->whereDate('end_date', '<=', now()->addDays(7)->toDateString())
                    ->count();
                $promoSoon = DB::table('promos')
                    ->when($storeId, fn ($q) => $q->where(fn ($w) => $w->where('store_id', $storeId)->orWhereNull('store_id')))
                    ->where('is_active', true)
                    ->whereNotNull('end_date')
                    ->whereDate('end_date', '>=', now()->toDateString())
                    ->whereDate('end_date', '<=', now()->addDays(7)->toDateString())
                    ->orderBy('end_date')
                    ->limit(20)
                    ->get()
                    ->map(function ($p) {
                        try {
                            $sisa = max(0, (int) now()->startOfDay()->diffInDays(\Carbon\Carbon::parse($p->end_date)->startOfDay(), false));
                        } catch (\Throwable $e) {
                            $sisa = 0;
                        }

                        return [
                            'judul' => 'Promo ' . ($p->code ?: ('#' . $p->id)),
                            'detail' => 'Berakhir ' . $p->end_date,
                            'umur' => $sisa === 0 ? 'berakhir hari ini' : 'H-' . $sisa,
                        ];
                    });
            }
            if ($promoSoon->isNotEmpty()) {
                $sections[] = [
                    'prioritas' => 'Minggu ini',
                    'tipe' => 'Promo <7 hari',
                    'judul' => 'Promo aktif kedaluwarsa <7 hari (' . $promoSoonTotal . ')',
                    'items' => $promoSoon,
                    'total' => $promoSoonTotal,
                    'aksi_url' => route('promos.index'),
                    'aksi_label' => 'Buka Promo → perpanjang/nonaktifkan manual',
                ];
            }
        } catch (\Throwable $e) {
        }

        // ---------- 12. KUNCI PERIODE BULAN LALU ----------
        try {
            $lockLine = DailyDigestService::periodLockReminderBlock($storeId);
            if ($lockLine !== '') {
                $lock = PeriodLock::get($storeId);
                $sections[] = [
                    'prioritas' => 'Rutin',
                    'tipe' => 'Kunci periode',
                    'judul' => 'Kunci periode (tutup buku)',
                    'items' => [[
                        'judul' => $lockLine,
                        'detail' => 'Batas kunci saat ini: ' . ($lock ?: 'belum ada'),
                        'umur' => 'bulan lalu belum dikunci',
                    ]],
                    'total' => 1,
                    'aksi_url' => route('cash.closing'),
                    'aksi_label' => 'Buka Tutup Kasir → kunci periode manual',
                ];
            }
        } catch (\Throwable $e) {
        }

        // ---------- 13. OPNAME >30 HARI ----------
        try {
            $opnameLine = DailyDigestService::opnameReminderBlock($storeId);
            if ($opnameLine !== '') {
                $last = null;
                $umur = 'belum pernah opname';
                if (\Schema::hasTable('stock_movements') && $storeId) {
                    $last = DB::table('stock_movements')
                        ->where('store_id', $storeId)
                        ->where('type', 'opname')
                        ->max('created_at');
                    if ($last) {
                        $umur = (int) \Carbon\Carbon::parse($last)->startOfDay()->diffInDays($today) . ' hari lalu';
                    }
                }
                $sections[] = [
                    'prioritas' => 'Rutin',
                    'tipe' => 'Opname >30 hari',
                    'judul' => 'Stok opname berkala',
                    'items' => [[
                        'judul' => $opnameLine,
                        'detail' => 'Opname terakhir: ' . ($last ?: '-'),
                        'umur' => $umur,
                    ]],
                    'total' => 1,
                    'aksi_url' => route('stocks.opname'),
                    'aksi_label' => 'Buka Opname → jadwalkan stok opname manual',
                ];
            }
        } catch (\Throwable $e) {
        }

        // ---------- 14. UNIT SERVICE YATIM (DashboardController@health cek #7) ----------
        try {
            $orphans = collect();
            if (\Schema::hasTable('product_items') && \Schema::hasTable('service_tickets')) {
                $orphans = DB::table('product_items as pi')
                    ->leftJoin('stores', 'stores.id', '=', 'pi.store_id')
                    ->leftJoin('products', 'products.id', '=', 'pi.product_id')
                    ->when($storeId, fn ($q) => $q->where('pi.store_id', $storeId))
                    ->where('pi.status', 'service')
                    ->whereNotNull('pi.imei')
                    ->where('pi.imei', '!=', '')
                    ->whereNotExists(function ($q) {
                        $q->select(DB::raw(1))->from('service_tickets as st')
                            ->whereRaw('st.imei = pi.imei')
                            ->whereNotIn('st.status', ['diserahkan', 'batal']);
                    })
                    ->select('pi.id', 'pi.imei', 'pi.serial', 'stores.name as store_name', 'products.name as product_name')
                    ->orderByDesc('pi.id')
                    ->limit(20)
                    ->get()
                    ->map(fn ($u) => [
                        'judul' => 'Unit ' . ($u->imei ?: ('#' . $u->id)) . ' — ' . ($u->product_name ?: '-'),
                        'detail' => 'Toko ' . ($u->store_name ?: '-') . ' • status service tanpa tiket aktif',
                        'umur' => 'yatim (tidak ada tiket aktif ber-IMEI sama)',
                    ]);
            }
            if ($orphans->isNotEmpty()) {
                $sections[] = [
                    'prioritas' => 'Rutin',
                    'tipe' => 'Unit yatim',
                    'judul' => 'Unit status service tanpa tiket aktif (' . $orphans->count() . '+)',
                    'items' => $orphans,
                    'total' => $orphans->count(),
                    'aksi_url' => route('health'),
                    'aksi_label' => 'Buka Kesehatan Data → kembalikan ke tersedia manual',
                ];
            }
        } catch (\Throwable $e) {
        }

        // ---------- 15. BOOKING MENUNGGU >24 JAM (widget Perlu Perhatian) ----------
        try {
            $oldBookings = collect();
            $oldTotal = 0;
            if (\Schema::hasTable('bookings')) {
                $oldTotal = (int) DB::table('bookings')
                    ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
                    ->where('status', 'menunggu')
                    ->where('created_at', '<=', now()->subHours(24))
                    ->count();
                $oldBookings = DB::table('bookings')
                    ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
                    ->where('status', 'menunggu')
                    ->where('created_at', '<=', now()->subHours(24))
                    ->orderBy('created_at')
                    ->limit(20)
                    ->get()
                    ->map(function ($b) {
                        try {
                            $jam = (int) \Carbon\Carbon::parse($b->created_at)->diffInHours(now());
                            $umur = $jam >= 24 ? (int) floor($jam / 24) . ' hari menunggu' : $jam . ' jam menunggu';
                        } catch (\Throwable $e) {
                            $umur = '>24 jam menunggu';
                        }

                        return [
                            'judul' => 'Booking ' . ($b->code ?: ('#' . $b->id)) . ' — ' . ($b->customer_name ?: '-'),
                            'detail' => ($b->device ?: '-') . ' • ' . ($b->customer_phone ?: 'tanpa nomor'),
                            'umur' => $umur,
                        ];
                    });
            }
            if ($oldBookings->isNotEmpty()) {
                $sections[] = [
                    'prioritas' => 'Rutin',
                    'tipe' => 'Booking menunggu',
                    'judul' => 'Booking menunggu >24 jam (' . $oldTotal . ')',
                    'items' => $oldBookings,
                    'total' => $oldTotal,
                    'aksi_url' => route('bookings.index'),
                    'aksi_label' => 'Buka Booking → konfirmasi/batalkan manual',
                ];
            }
        } catch (\Throwable $e) {
        }

        // ---------- 16. STOK MENIPIS (widget Perlu Perhatian) ----------
        try {
            $lows = collect();
            $lowTotal = 0;
            if (\Schema::hasTable('product_stocks') && \Schema::hasTable('products')) {
                $lowBase = DB::table('product_stocks')
                    ->join('products', 'products.id', '=', 'product_stocks.product_id')
                    ->when($storeId, fn ($q) => $q->where('product_stocks.store_id', $storeId))
                    ->whereRaw('product_stocks.qty <= COALESCE(product_stocks.min_stock, products.min_stock)');
                $lowTotal = (int) (clone $lowBase)->count();
                $lows = $lowBase
                    ->select('products.name', 'product_stocks.qty')
                    ->selectRaw('COALESCE(product_stocks.min_stock, products.min_stock) as min_stock')
                    ->orderBy('product_stocks.qty')
                    ->limit(20)
                    ->get()
                    ->map(fn ($r) => [
                        'judul' => $r->name,
                        'detail' => 'Sisa ' . $r->qty . ' • min ' . ($r->min_stock ?? '-'),
                        'umur' => 'perlu restock',
                    ]);
            }
            if ($lows->isNotEmpty()) {
                $sections[] = [
                    'prioritas' => 'Rutin',
                    'tipe' => 'Stok menipis',
                    'judul' => 'Stok menipis (' . $lowTotal . ')',
                    'items' => $lows,
                    'total' => $lowTotal,
                    'aksi_url' => route('products.reorder'),
                    'aksi_label' => 'Buka Reorder → pesan restock manual',
                ];
            }
        } catch (\Throwable $e) {
        }

        // ---------- 17. WA GAGAL/ANTRI (widget Perlu Perhatian) ----------
        try {
            $waCount = 0;
            if (\Schema::hasTable('wa_logs')) {
                $waCount = (int) DB::table('wa_logs')
                    ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
                    ->whereIn('status', ['gagal', 'antri'])
                    ->count();
            }
            if ($waCount > 5) {
                $sections[] = [
                    'prioritas' => 'Rutin',
                    'tipe' => 'WA gagal',
                    'judul' => 'Pesan WA gagal/antri (' . $waCount . ')',
                    'items' => [[
                        'judul' => $waCount . ' pesan WA berstatus gagal/antri',
                        'detail' => 'Cek gateway & antrean, kirim ulang manual yang penting',
                        'umur' => 'perlu tindak lanjut',
                    ]],
                    'total' => $waCount,
                    'aksi_url' => route('settings.wa'),
                    'aksi_label' => 'Buka Setting WA → retry manual per log',
                ];
            }
        } catch (\Throwable $e) {
        }

        $totalItems = collect($sections)->sum(fn ($s) => (int) ($s['total'] ?? 0));

        return view('reminders.index', compact('sections', 'totalItems', 'tomorrow'));
    }
}
