<?php

namespace App\Http\Controllers;

use App\Models\Promo;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PromoController extends Controller
{
    /** Tulis promo = uang (diskon). Hanya owner/admin/superadmin. */
    private function authorizePromoWrite(): void
    {
        abort_unless(in_array(auth()->user()->role ?? '', ['superadmin', 'owner', 'admin'], true), 403, 'Hanya owner/admin yang boleh mengelola promo.');
    }

    public function index(Request $request)
    {
        // Filter "Belum pernah dipakai": ?unused=1 → used=0 + sales count 0 (query string, tanpa route baru).
        $unusedOnly = $request->boolean('unused');
        $query = Promo::with('store')->withCount('sales');
        if ($unusedOnly) {
            $query->where('used', 0)->whereDoesntHave('sales');
        }
        $query->when(
            $request->filled('q'),
            fn ($q) => $q->where(function ($w) use ($request) {
                $w->where('code', 'like', '%' . $request->q . '%')
                    ->orWhere('name', 'like', '%' . $request->q . '%');
            })
        );
        $promos = $query->orderByDesc('id')->paginate(20)->withQueryString();
        $stores = Store::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        // Counter badge filter (tanpa migration): promo yang belum pernah dipakai.
        $unusedCount = Promo::where('used', 0)->whereDoesntHave('sales')->count();
        // Badge masa berlaku + sisa quota + hitung mundur (tanpa query tambahan).
        $today = now()->toDateString();
        $nowDay = now()->startOfDay();
        foreach ($promos as $p) {
            $p->sisa_quota = $p->quota > 0 ? max(0, (int) $p->quota - (int) $p->used) : null; // null = tanpa batas
            if (!$p->is_active) {
                $p->promo_status = 'Nonaktif';
                $p->promo_class = 'secondary';
            } elseif ($p->sisa_quota !== null && $p->sisa_quota <= 0) {
                $p->promo_status = 'Quota habis';
                $p->promo_class = 'orange';
            } elseif ($p->start_date && $p->start_date->toDateString() > $today) {
                $p->promo_status = 'Segera';
                $p->promo_class = 'blue';
            } elseif ($p->end_date && $p->end_date->toDateString() < $today) {
                $p->promo_status = 'Berakhir';
                $p->promo_class = 'red';
            } else {
                $p->promo_status = 'Berlaku sekarang';
                $p->promo_class = 'green';
            }
            // Hitung mundur: "X hari lagi" (belum mulai) / "berakhir dalam X hari" (berjalan).
            $p->promo_countdown = null;
            try {
                if ($p->start_date && $p->start_date->toDateString() > $today) {
                    $days = $nowDay->copy()->diffInDays($p->start_date->copy()->startOfDay());
                    $p->promo_countdown = $days <= 1 ? '1 hari lagi' : $days . ' hari lagi';
                } elseif ($p->is_active && $p->end_date && $p->end_date->toDateString() >= $today
                    && ($p->sisa_quota === null || $p->sisa_quota > 0)) {
                    $days = $nowDay->copy()->diffInDays($p->end_date->copy()->startOfDay());
                    $p->promo_countdown = $days === 0 ? 'berakhir hari ini' : 'berakhir dalam ' . $days . ' hari';
                }
            } catch (\Throwable $e) {
                $p->promo_countdown = null;
            }
            // Badge "Belum pernah dipakai": used=0 + sales count 0 (seperti statPakai di show()).
            $p->is_unused = ((int) $p->used === 0 && (int) ($p->sales_count ?? 0) === 0);
        }
        return view('promos.index', compact('promos', 'stores', 'unusedOnly', 'unusedCount'));
    }

    /** Lembar QR gabungan semua promo aktif: kode + syarat + periode, grid 6/halaman. */
    public function qrSheet()
    {
        $today = now()->toDateString();
        $promos = Promo::with('store')
            ->where('is_active', true)
            ->where(function ($q) use ($today) {
                $q->whereNull('start_date')->orWhere('start_date', '<=', $today);
            })
            ->where(function ($q) use ($today) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $today);
            })
            ->orderBy('code')
            ->get()
            ->filter(function ($p) {
                return $p->quota <= 0 || ((int) $p->used < (int) $p->quota);
            })
            ->values();
        return view('promos.qr-sheet', compact('promos', 'today'));
    }

    public function create(Request $request)
    {
        $stores = Store::where('is_active', true)->orderBy('name')->get();
        // Prefill form via query params (tanpa route/migration baru).
        // Dipakai tombol "Buat Bundling" dari kartu pasangan / halaman promo,
        // mis. /promos/create?type=bundling&name=Bundling+A+%2B+B&min_belanja=50000.
        $allowedTypes = ['diskon_persen', 'diskon_rupiah', 'bundling', 'voucher', 'member'];
        $type = $request->query('type');
        $valueQuery = $request->query('value');
        $minQuery = $request->query('min_belanja');
        $prefill = [
            'type' => in_array($type, $allowedTypes, true) ? $type : null,
            'name' => substr(trim((string) $request->query('name', '')), 0, 255),
            'code' => substr(trim((string) $request->query('code', '')), 0, 50),
            'value' => is_numeric($valueQuery) ? max(0, (float) $valueQuery) : null,
            'min_belanja' => is_numeric($minQuery) ? max(0, (float) $minQuery) : null,
        ];
        return view('promos.create', compact('stores', 'prefill'));
    }

    /** Aturan validasi promo + pesan Indonesia; persen (diskon_persen/bundling) dicap 0–100. */
    protected function promoRules(?Promo $promo = null): array
    {
        $unique = $promo ? 'unique:promos,code,' . $promo->id : 'unique:promos,code';
        return [
            'rules' => [
                'store_id' => 'nullable|exists:stores,id',
                'code' => 'required|string|max:50|' . $unique,
                'name' => 'required|string|max:255',
                'type' => 'required|in:diskon_persen,diskon_rupiah,bundling,voucher,member',
                'value' => 'required|numeric|min:0',
                'min_belanja' => 'nullable|numeric|min:0',
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date|after_or_equal:start_date',
                'quota' => 'nullable|integer|min:0',
                'is_active' => 'nullable|boolean',
            ],
            'messages' => [
                'store_id.exists' => 'Toko tidak ditemukan.',
                'code.required' => 'Kode promo wajib diisi.',
                'code.unique' => 'Kode promo sudah dipakai.',
                'code.max' => 'Kode promo maksimal 50 karakter.',
                'name.required' => 'Nama promo wajib diisi.',
                'type.required' => 'Tipe promo wajib dipilih.',
                'type.in' => 'Tipe promo tidak valid.',
                'value.required' => 'Nilai promo wajib diisi.',
                'value.numeric' => 'Nilai promo harus berupa angka.',
                'value.min' => 'Nilai promo minimal 0.',
                'min_belanja.numeric' => 'Min. belanja harus berupa angka.',
                'min_belanja.min' => 'Min. belanja minimal 0.',
                'start_date.date' => 'Tanggal mulai tidak valid.',
                'end_date.date' => 'Tanggal berakhir tidak valid.',
                'end_date.after_or_equal' => 'Tanggal berakhir minimal sama dengan tanggal mulai.',
                'quota.integer' => 'Quota harus bilangan bulat.',
                'quota.min' => 'Quota minimal 0 (0 = tanpa batas).',
            ],
        ];
    }

    /** Cek semantik nilai per tipe; return pesan error Indonesia atau null bila OK. */
    protected function promoTypeError(string $type, float $value): ?string
    {
        if (in_array($type, ['diskon_persen', 'bundling'], true) && $value > 100) {
            $label = $type === 'bundling' ? 'bundling (persen diskon)' : 'diskon persen';
            return 'Untuk tipe ' . $label . ', nilai maksimal 100 (= 100%). Isi ' . $value . ' tidak wajar.';
        }
        return null;
    }

    public function store(Request $request)
    {
        $this->authorizePromoWrite();
        ['rules' => $rules, 'messages' => $messages] = $this->promoRules();
        $data = $request->validate($rules, $messages);
        if ($err = $this->promoTypeError($data['type'], (float) $data['value'])) {
            return back()->withInput()->withErrors(['value' => $err]);
        }
        $data['min_belanja'] = $data['min_belanja'] ?? 0;
        $data['quota'] = $data['quota'] ?? 0;
        $data['is_active'] = (bool) ($data['is_active'] ?? true);
        $promo = Promo::create($data);
        return redirect()->route('promos.show', $promo)->with('success', 'Promo dibuat.');
    }

    /** Detail + validasi quota/tanggal + daftar pemakaian (sales ber-promo). */
    public function show(Request $request, Promo $promo)
    {
        // Export CSV semua pemakaian: ?export=csv (invoice, tanggal, pelanggan, diskon, omzet).
        if ($request->query('export') === 'csv') {
            $rows = $promo->sales()->with(['customer'])->orderBy('id')->get();
            $filename = 'promo-' . preg_replace('/[^A-Za-z0-9-_]+/', '-', (string) $promo->code)
                . '-pemakaian-' . now()->format('Ymd-His') . '.csv';
            return response()->streamDownload(function () use ($rows) {
                $out = fopen('php://output', 'w');
                fputcsv($out, ['invoice', 'tanggal', 'pelanggan', 'diskon', 'omzet']);
                foreach ($rows as $s) {
                    fputcsv($out, [
                        $s->invoice_no,
                        optional($s->created_at)->format('Y-m-d H:i:s'),
                        $s->customer->name ?? '-',
                        (float) $s->discount,
                        (float) $s->total,
                    ]);
                }
                fclose($out);
            }, $filename, ['Content-Type' => 'text/csv']);
        }
        $promo->load('store');
        $today = now()->toDateString();
        $validTanggal = (!$promo->start_date || $promo->start_date <= $today)
            && (!$promo->end_date || $promo->end_date >= $today);
        $sisaQuota = ($promo->quota > 0) ? max(0, $promo->quota - $promo->used) : null; // null = tanpa batas
        $masihBerlaku = $promo->is_active && $validTanggal && ($sisaQuota === null || $sisaQuota > 0);
        $usage = $promo->sales()->with(['customer', 'store'])->orderByDesc('id')->limit(50)->get();
        $recentUsage = $promo->sales()->with(['customer'])->orderByDesc('id')->limit(10)->get();
        // Statistik pemakaian: jumlah pakai + total diskon + omzet terkait (dari sales)
        $statPakai = (int) $promo->sales()->count();
        $statDiskon = 0; $statOmzet = 0;
        try {
            $statDiskon = (float) $promo->sales()->sum('discount');
            $statOmzet = (float) $promo->sales()->sum('total');
        } catch (\Throwable $e) {
            report($e);
        }
        // ROI promo: (omzet terkait - diskon) / diskon x 100%. Null bila diskon=0.
        $statRoi = null;
        if ($statDiskon > 0) {
            $statRoi = ($statOmzet - $statDiskon) / $statDiskon * 100;
        }
        $trendLabels = []; $trendPakai = []; $trendDiskon = [];
        try {
            $weeks = [];
            for ($i = 11; $i >= 0; $i--) {
                $ws = now()->copy()->startOfWeek()->subWeeks($i);
                $we = $ws->copy()->endOfWeek();
                $weeks[] = ['start' => $ws, 'end' => $we];
                $trendLabels[] = $ws->format('d/m');
            }
            $rows = $promo->sales()
                ->where('created_at', '>=', $weeks[0]['start']->copy()->startOfDay())
                ->get(['created_at', 'discount']);
            foreach ($weeks as $w) {
                $pakai = 0; $diskon = 0.0;
                foreach ($rows as $r) {
                    if ($r->created_at >= $w['start']->copy()->startOfDay()
                        && $r->created_at <= $w['end']->copy()->endOfDay()) {
                        $pakai++;
                        $diskon += (float) $r->discount;
                    }
                }
                $trendPakai[] = $pakai;
                $trendDiskon[] = $diskon;
            }
        } catch (\Throwable $e) {
            report($e);
            $trendLabels = []; $trendPakai = []; $trendDiskon = [];
        }
        return view('promos.show', compact('promo', 'validTanggal', 'sisaQuota', 'masihBerlaku', 'usage', 'recentUsage', 'statPakai', 'statDiskon', 'statOmzet', 'statRoi', 'trendLabels', 'trendPakai', 'trendDiskon'));
    }

    public function edit(Promo $promo)
    {
        $stores = Store::where('is_active', true)->orderBy('name')->get();
        return view('promos.edit', compact('promo', 'stores'));
    }

    public function update(Request $request, Promo $promo)
    {
        $this->authorizePromoWrite();
        ['rules' => $rules, 'messages' => $messages] = $this->promoRules($promo);
        $data = $request->validate($rules, $messages);
        if ($err = $this->promoTypeError($data['type'], (float) $data['value'])) {
            return back()->withInput()->withErrors(['value' => $err]);
        }
        $data['is_active'] = (bool) ($data['is_active'] ?? false);
        $promo->update($data);
        return redirect()->route('promos.show', $promo)->with('success', 'Promo diperbarui.');
    }

    public function destroy(Promo $promo)
    {
        $this->authorizePromoWrite();
        $promo->delete();
        return redirect()->route('promos.index')->with('success', 'Promo dihapus.');
    }

    /** Nonaktif massal: set is_active=false untuk ids terpilih (tanpa migration). */
    public function bulkOff(Request $request)
    {
        $this->authorizePromoWrite();
        $data = $request->validate(
            [
                'ids' => 'required|array|min:1',
                'ids.*' => 'integer|exists:promos,id',
            ],
            [
                'ids.required' => 'Pilih minimal satu promo.',
                'ids.array' => 'Data pilihan tidak valid.',
                'ids.min' => 'Pilih minimal satu promo.',
                'ids.*.integer' => 'Data pilihan tidak valid.',
                'ids.*.exists' => 'Salah satu promo tidak ditemukan.',
            ]
        );

        $count = 0;
        DB::transaction(function () use ($data, &$count) {
            $count = Promo::whereIn('id', $data['ids'])
                ->where('is_active', true)
                ->update(['is_active' => false]);
        });

        return redirect()
            ->route('promos.index')
            ->with('success', $count . ' promo dinonaktifkan.');
    }

    /** Aktifkan massal (simetri bulkOff): set is_active=true untuk ids terpilih.
     * Validasi ringan: lewati yang sudah kedaluwarsa (end_date < hari ini)
     * atau quota habis (quota > 0 && used >= quota), lalu laporkan yang dilewati. */
    public function bulkOn(Request $request)
    {
        $this->authorizePromoWrite();
        $data = $request->validate(
            [
                'ids' => 'required|array|min:1',
                'ids.*' => 'integer|exists:promos,id',
            ],
            [
                'ids.required' => 'Pilih minimal satu promo.',
                'ids.array' => 'Data pilihan tidak valid.',
                'ids.min' => 'Pilih minimal satu promo.',
                'ids.*.integer' => 'Data pilihan tidak valid.',
                'ids.*.exists' => 'Salah satu promo tidak ditemukan.',
            ]
        );

        $today = now()->toDateString();
        $ok = 0;
        $skipExpired = 0;
        $skipQuota = 0;
        $skipActive = 0;
        DB::transaction(function () use ($data, $today, &$ok, &$skipExpired, &$skipQuota, &$skipActive) {
            $promos = Promo::whereIn('id', $data['ids'])->get();
            foreach ($promos as $p) {
                if ($p->is_active) {
                    $skipActive++;
                    continue;
                }
                if ($p->end_date && $p->end_date->toDateString() < $today) {
                    $skipExpired++;
                    continue;
                }
                if ((int) $p->quota > 0 && (int) $p->used >= (int) $p->quota) {
                    $skipQuota++;
                    continue;
                }
                $p->is_active = true;
                $p->save();
                $ok++;
            }
        });

        $msg = $ok . ' promo diaktifkan.';
        $parts = [];
        if ($skipExpired > 0) {
            $parts[] = $skipExpired . ' kedaluwarsa';
        }
        if ($skipQuota > 0) {
            $parts[] = $skipQuota . ' quota habis';
        }
        if ($skipActive > 0) {
            $parts[] = $skipActive . ' sudah aktif';
        }
        if (!empty($parts)) {
            $msg .= ' Dilewati ' . ($skipExpired + $skipQuota + $skipActive) . ' (' . implode(', ', $parts) . ').';
        }

        return redirect()
            ->route('promos.index')
            ->with('success', $msg);
    }

    /** Perpanjang massal +30 hari (tanpa migration).
     * Aturan (dilaporkan per baris via flash message):
     *  - end_date baru = max(hari ini, end_date lama) + 30 hari. Basis "hari ini"
     *    agar promo yang sudah lewat tidak "diperpanjang ke masa lalu".
     *    Bila end_date NULL (tanpa batas waktu) → dilewati (tidak perlu diperpanjang).
     *  - Quota habis (quota > 0 && used >= quota) → tanggal TETAP diperpanjang,
     *    tetapi TIDAK diaktifkan (tetap nonaktif) agar tidak bisa dipakai.
     *  - Selain itu → tanggal diperpanjang DAN is_active=true (reaktivasi otomatis,
     *    termasuk yang sudah kedaluwarsa — itulah gunanya perpanjang).
     *  - start_date > end_date baru tidak mungkin terjadi karena basis >= hari ini
     *    dan +30 hari; bila start_date justru di masa depan melebihi itu
     *    (kasus aneh), end_date disamakan ke start_date + 30 hari. */
    public function bulkExtend(Request $request)
    {
        $this->authorizePromoWrite();
        $data = $request->validate(
            [
                'ids' => 'required|array|min:1',
                'ids.*' => 'integer|exists:promos,id',
            ],
            [
                'ids.required' => 'Pilih minimal satu promo.',
                'ids.array' => 'Data pilihan tidak valid.',
                'ids.min' => 'Pilih minimal satu promo.',
                'ids.*.integer' => 'Data pilihan tidak valid.',
                'ids.*.exists' => 'Salah satu promo tidak ditemukan.',
            ]
        );

        $today = now()->startOfDay();
        $lines = [];
        $ok = 0;
        $activated = 0;
        $skipQuota = 0;
        $skipNoExpiry = 0;
        DB::transaction(function () use ($data, $today, &$lines, &$ok, &$activated, &$skipQuota, &$skipNoExpiry) {
            $promos = Promo::whereIn('id', $data['ids'])->orderBy('code')->get();
            foreach ($promos as $p) {
                if (is_null($p->end_date)) {
                    $skipNoExpiry++;
                    $lines[] = $p->code . ': dilewati (tanpa batas waktu)';
                    continue;
                }
                $old = $p->end_date->copy()->startOfDay();
                $base = $old->greaterThan($today) ? $old->copy() : $today->copy();
                $new = $base->copy()->addDays(30);
                // Jaga-jaga start_date futuristik: jangan sampai end < start.
                if ($p->start_date && $p->start_date->copy()->startOfDay()->greaterThan($new)) {
                    $new = $p->start_date->copy()->startOfDay()->addDays(30);
                }
                $quotaHabis = (int) $p->quota > 0 && (int) $p->used >= (int) $p->quota;
                $p->end_date = $new->toDateString();
                if ($quotaHabis) {
                    // Tanggal diperpanjang, status aktif tidak diubah (tetap mati).
                    $p->save();
                    $skipQuota++;
                    $lines[] = $p->code . ': ' . $old->format('d/m/y')
                        . ' → ' . $new->format('d/m/y')
                        . ' (quota habis, tetap nonaktif)';
                    continue;
                }
                $p->is_active = true;
                $p->save();
                $ok++;
                $activated++;
                $lines[] = $p->code . ': ' . $old->format('d/m/y')
                    . ' → ' . $new->format('d/m/y')
                    . ' (diaktifkan)';
            }
        });

        $msg = $ok . ' promo diperpanjang +30 hari & diaktifkan.';
        if ($skipQuota > 0 || $skipNoExpiry > 0) {
            $msg .= ' Dilewati ' . ($skipQuota + $skipNoExpiry);
            $bits = [];
            if ($skipQuota > 0) {
                $bits[] = $skipQuota . ' quota habis (tanggal tetap +30 hari, tidak diaktifkan)';
            }
            if ($skipNoExpiry > 0) {
                $bits[] = $skipNoExpiry . ' tanpa batas waktu';
            }
            $msg .= ' (' . implode(', ', $bits) . ').';
        }
        if (!empty($lines)) {
            $msg .= ' Rincian: ' . implode('; ', $lines) . '.';
        }

        return redirect()
            ->route('promos.index')
            ->with('success', $msg);
    }

    /** Duplikat massal: tiap promo terpilih disalin satu baris
     * (kode "{KODE}-COPY" unik global, tetap di toko asal, tanggal dikosongkan,
     * used=0, quota=0, nonaktif) lalu dilaporkan per baris via flash message. */
    public function bulkDuplicate(Request $request)
    {
        $this->authorizePromoWrite();
        $data = $request->validate(
            [
                'ids' => 'required|array|min:1',
                'ids.*' => 'integer|exists:promos,id',
            ],
            [
                'ids.required' => 'Pilih minimal satu promo.',
                'ids.array' => 'Data pilihan tidak valid.',
                'ids.min' => 'Pilih minimal satu promo.',
                'ids.*.integer' => 'Data pilihan tidak valid.',
                'ids.*.exists' => 'Salah satu promo tidak ditemukan.',
            ]
        );

        $lines = [];
        $made = [];
        DB::transaction(function () use ($data, &$lines, &$made) {
            $promos = Promo::whereIn('id', $data['ids'])->orderBy('code')->get();
            // Batch: muat semua kode sekali ke set memori (dulu exists() per
            // kandidat suffix = N+1 query di dalam loop).
            $taken = Promo::pluck('code')->mapWithKeys(fn ($c) => [(string) $c => true])->all();
            foreach ($promos as $p) {
                $base = substr($p->code . '-COPY', 0, 50);
                $code = $base;
                $i = 2;
                while (isset($taken[$code]) || in_array($code, $made, true)) {
                    $suffix = '-' . $i;
                    $code = substr($base, 0, 50 - strlen($suffix)) . $suffix;
                    $i++;
                    if ($i > 999) {
                        break;
                    }
                }
                $copy = $p->replicate();
                $copy->code = $code;
                $copy->start_date = null;
                $copy->end_date = null;
                $copy->quota = 0;
                $copy->used = 0;
                $copy->is_active = false;
                $copy->save();
                $taken[$code] = true;
                $made[] = $code;
                $lines[] = $p->code . ' → ' . $code;
            }
        });

        $msg = count($made) . ' promo diduplikat (nonaktif, quota 0, tanggal dikosongkan).';
        if (!empty($lines)) {
            $msg .= ' Rincian: ' . implode('; ', $lines) . '.';
        }
        $msg .= ' Sesuaikan tanggal lalu aktifkan.';

        return redirect()
            ->route('promos.index')
            ->with('success', $msg);
    }

    /** Import CSV promo (cermin import produk).
     * Kolom: code,name,type,value,min_belanja,start_date,end_date,quota,is_active
     * Aturan: type valid; code unik (duplikat DB/file = LEWATI + lapor,
     * JANGAN update agar quota/used aman); tanggal valid & end >= start;
     * value numerik (persen/bundling maks 100); quota int >= 0.
     * Default: min_belanja=0, quota=0 (tanpa batas), is_active=true, used=0, store global.
     * Template: GET /promos/template (header + 1 contoh, delimiter ;, BOM). */
    public function template()
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv(
                $out,
                ['code', 'name', 'type', 'value', 'min_belanja', 'start_date', 'end_date', 'quota', 'is_active'],
                ';'
            );
            fputcsv(
                $out,
                ['HEMAT10', 'Promo Hemat 10%', 'diskon_persen', '10', '50000', '2026-01-01', '2026-12-31', '100', '1'],
                ';'
            );
            fclose($out);
        }, 'template_promo.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function import(Request $request)
    {
        $this->authorizePromoWrite();
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:2048']);
        $allowedTypes = ['diskon_persen', 'diskon_rupiah', 'bundling', 'voucher', 'member'];

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        // Deteksi delimiter: file hasil Export memakai ';' (Excel Indonesia), file manual umumnya ','.
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
        $seenCodes = [];
        while (($cols = $readCsv()) !== false) {
            $line++;
            if (count($cols) === 1 && trim((string) $cols[0]) === '') continue;
            $row = array_combine(array_slice(array_merge($header, range(0, count($cols) - 1)), 0, count($cols)), $cols);
            $g = fn($k) => trim((string) ($row[$k] ?? ''));
            $err = null;
            $code = strtoupper($g('code'));
            $name = $g('name');
            $type = strtolower($g('type'));
            $valueRaw = $g('value');
            $minRaw = $g('min_belanja');
            $startRaw = $g('start_date');
            $endRaw = $g('end_date');
            $quotaRaw = $g('quota');
            $activeRaw = strtolower($g('is_active'));
            if ($code === '') $err = 'code kosong';
            elseif (strlen($code) > 50) $err = 'code maksimal 50 karakter';
            elseif ($name === '') $err = 'name kosong';
            elseif (!in_array($type, $allowedTypes, true)) $err = 'type tidak valid: ' . ($g('type') === '' ? '(kosong)' : $g('type')) . ' (valid: ' . implode(',', $allowedTypes) . ')';
            elseif ($valueRaw === '' || !is_numeric($valueRaw)) $err = 'value wajib angka';
            elseif ((float) $valueRaw < 0) $err = 'value minimal 0';
            elseif (in_array($type, ['diskon_persen', 'bundling'], true) && (float) $valueRaw > 100) $err = 'value maksimal 100 untuk type ' . $type;
            elseif ($minRaw !== '' && !is_numeric($minRaw)) $err = 'min_belanja bukan angka';
            elseif ($minRaw !== '' && (float) $minRaw < 0) $err = 'min_belanja minimal 0';
            elseif ($quotaRaw !== '' && !ctype_digit($quotaRaw)) $err = 'quota harus bilangan bulat >= 0';
            elseif ($startRaw !== '' && strtotime($startRaw) === false) $err = 'start_date tidak valid: ' . $startRaw;
            elseif ($endRaw !== '' && strtotime($endRaw) === false) $err = 'end_date tidak valid: ' . $endRaw;
            if (!$err && $startRaw !== '' && $endRaw !== '' && date('Y-m-d', strtotime($endRaw)) < date('Y-m-d', strtotime($startRaw))) {
                $err = 'end_date minimal sama dengan start_date';
            }
            if (!$err && isset($seenCodes[$code])) {
                $err = 'code duplikat di file (dilewati, tidak diupdate)';
            }
            if (!$err && Promo::where('code', $code)->exists()) {
                $err = 'code sudah dipakai (dilewati, tidak diupdate)';
            }
            if ($err) {
                $fail[] = "Baris $line ($code): $err";
                if (str_contains($err, 'dilewati')) $skipped++;
                continue;
            }
            $seenCodes[$code] = true;

            try {
                $isActive = true;
                if ($activeRaw !== '') {
                    $isActive = !in_array($activeRaw, ['0', 'false', 'no', 'tidak', 'nonaktif', 'mati', 'off'], true);
                }
                Promo::create([
                    'store_id' => null,
                    'code' => $code,
                    'name' => $name,
                    'type' => $type,
                    'value' => (float) $valueRaw,
                    'min_belanja' => $minRaw === '' ? 0 : (float) $minRaw,
                    'start_date' => $startRaw === '' ? null : date('Y-m-d', strtotime($startRaw)),
                    'end_date' => $endRaw === '' ? null : date('Y-m-d', strtotime($endRaw)),
                    'quota' => $quotaRaw === '' ? 0 : (int) $quotaRaw,
                    'used' => 0,
                    'is_active' => $isActive,
                ]);
                $ok++;
            } catch (\Throwable $e) {
                $fail[] = "Baris $line ($code): " . $e->getMessage();
            }
        }
        fclose($handle);

        return redirect()->route('promos.index')
            ->with('success', "Import selesai: $ok baris berhasil, $skipped duplikat dilewati, " . (count($fail) - $skipped) . ' gagal.')
            ->with('import_fail', $fail);
    }

    /** Duplikat promo: copy aman (kode unik + -COPY, tanggal dikosongkan, used=0, nonaktif) → lanjut ke edit.
     * Bila form "Salin ke toko" mengirim store_id, copy memakai store_id tujuan itu;
     * tanpa store_id → tetap di toko asal. Kode selalu dibuat unik global (-COPY, -2, dst). */
    public function duplicate(Request $request, Promo $promo)
    {
        $this->authorizePromoWrite();
        $targetStoreId = $promo->store_id;
        $targetStoreName = null;
        if (array_key_exists('store_id', $request->all())) {
            $data = $request->validate(
                [
                    'store_id' => 'nullable|exists:stores,id',
                ],
                [
                    'store_id.exists' => 'Toko tujuan tidak ditemukan.',
                ]
            );
            // null = salin sebagai promo global (semua toko).
            $targetStoreId = $data['store_id'] ?? null;
            if ($targetStoreId) {
                $targetStoreName = Store::whereKey($targetStoreId)->value('name');
            }
        }
        $base = substr($promo->code . '-COPY', 0, 50);
        $code = $base;
        $i = 2;
        while (Promo::where('code', $code)->exists()) {
            $suffix = '-' . $i;
            $code = substr($base, 0, 50 - strlen($suffix)) . $suffix;
            $i++;
            if ($i > 999) {
                break;
            }
        }
        $copy = $promo->replicate();
        $copy->code = $code;
        $copy->store_id = $targetStoreId;
        $copy->start_date = null;
        $copy->end_date = null;
        $copy->used = 0;
        $copy->is_active = false;
        $copy->save();

        $extra = $targetStoreName !== null
            ? ' ke toko ' . $targetStoreName
            : ($targetStoreId === null && $promo->store_id !== null ? ' sebagai promo global' : '');
        return redirect()
            ->route('promos.edit', $copy)
            ->with('success', 'Promo diduplikat sebagai ' . $code . $extra . '. Sesuaikan tanggal lalu aktifkan.');
    }

    /** Salin promo ke semua toko aktif (tanpa migration).
     * Aturan duplikat: kode promo unik GLOBAL (kolom promos.code unique) sehingga
     * kode yang SAMA tidak bisa dipakai dua baris. Karena itu salinan per toko
     * memakai kode turunan "{KODE}-{KODETOKO}" (maks 50 char, nonaktif, used=0,
     * tanggal dikosongkan seperti duplicate()). Toko dilewati bila:
     *  - toko asal promo itu sendiri, atau
     *  - toko tujuan sudah punya kode sama persis, atau
     *  - kode turunan sudah dipakai baris lain (global).
     * Hasil dilaporkan per toko via flash message. */
    public function duplicateAll(Request $request, Promo $promo)
    {
        $this->authorizePromoWrite();
        $stores = Store::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        if ($stores->isEmpty()) {
            return redirect()
                ->route('promos.index')
                ->with('success', 'Tidak ada toko aktif untuk disalin.');
        }

        $created = [];
        $skipped = [];
        DB::transaction(function () use ($promo, $stores, &$created, &$skipped) {
            // Batch: muat semua kode promo sekali (dulu 2x exists() per toko).
            $taken = Promo::pluck('code')->mapWithKeys(fn ($c) => [(string) $c => true])->all();
            $byStore = Promo::whereIn('store_id', $stores->pluck('id')->all())->pluck('store_id', 'code');
            foreach ($stores as $store) {
                // Toko asal sudah punya promo ini → lewati.
                if ($promo->store_id && (int) $store->id === (int) $promo->store_id) {
                    $skipped[] = $store->name . ' (toko asal)';
                    continue;
                }
                // Kode sama di toko tujuan → lewati (idempoten bila dijalankan ulang).
                if ((int) ($byStore->get($promo->code) ?? -1) === (int) $store->id) {
                    $skipped[] = $store->name . ' (kode ' . $promo->code . ' sudah ada)';
                    continue;
                }
                $tag = strtoupper(trim((string) $store->code));
                if ($tag === '') {
                    $tag = 'T' . $store->id;
                }
                $suffix = '-' . $tag;
                // Pastikan muat suffix: potong basis bila kepanjangan (maks 50 char).
                if (strlen($promo->code . $suffix) > 50) {
                    $candidate = substr($promo->code, 0, 50 - strlen($suffix)) . $suffix;
                } else {
                    $candidate = $promo->code . $suffix;
                }
                // Kode turunan sudah dipakai global (unique) → lewati.
                if (isset($taken[$candidate])) {
                    $skipped[] = $store->name . ' (kode ' . $candidate . ' sudah dipakai)';
                    continue;
                }
                $copy = $promo->replicate();
                $copy->code = $candidate;
                $copy->store_id = $store->id;
                $copy->start_date = null;
                $copy->end_date = null;
                $copy->used = 0;
                $copy->is_active = false;
                $copy->save();
                $taken[$candidate] = true;
                $byStore[$candidate] = $store->id;
                $created[] = $store->name . ' (' . $candidate . ')';
            }
        });

        $msg = count($created) . ' toko disalin (nonaktif, tanggal dikosongkan).';
        if (!empty($created)) {
            $msg .= ' Dibuat: ' . implode(', ', $created) . '.';
        }
        if (!empty($skipped)) {
            $msg .= ' Dilewati ' . count($skipped) . ': ' . implode(', ', $skipped) . '.';
        }

        return redirect()
            ->route('promos.index')
            ->with('success', $msg);
    }

    /** Salin massal beberapa promo ke SATU toko tujuan (tanpa migration, maks 20).
     * Cermin duplicateAll: kode turunan "{KODE}-{KODETOKO}" (maks 50 char,
     * basis dipotong bila perlu), nonaktif, used=0, tanggal dikosongkan.
     * Idempoten: kode sama di toko tujuan / kode turunan sudah dipakai
     * global → dilewati + dilaporkan per baris. */
    public function bulkDuplicateAll(Request $request)
    {
        $this->authorizePromoWrite();
        $data = $request->validate(
            [
                'ids' => 'required|array|min:1|max:20',
                'ids.*' => 'integer|exists:promos,id',
                'store_id' => 'required|integer|exists:stores,id',
            ],
            [
                'ids.required' => 'Pilih minimal satu promo.',
                'ids.array' => 'Data pilihan tidak valid.',
                'ids.min' => 'Pilih minimal satu promo.',
                'ids.max' => 'Maksimal 20 promo sekaligus.',
                'ids.*.integer' => 'Data pilihan tidak valid.',
                'ids.*.exists' => 'Salah satu promo tidak ditemukan.',
                'store_id.required' => 'Pilih toko tujuan.',
                'store_id.integer' => 'Toko tujuan tidak valid.',
                'store_id.exists' => 'Toko tujuan tidak ditemukan.',
            ]
        );

        $store = Store::whereKey($data['store_id'])->first(['id', 'code', 'name', 'is_active']);
        if (!$store || !$store->is_active) {
            return redirect()
                ->route('promos.index')
                ->withErrors(['store_id' => 'Toko tujuan tidak aktif.'])
                ->withInput();
        }

        $tag = strtoupper(trim((string) $store->code));
        if ($tag === '') {
            $tag = 'T' . $store->id;
        }
        $suffix = '-' . $tag;

        $lines = [];
        $ok = 0;
        $skip = 0;
        DB::transaction(function () use ($data, $store, $suffix, &$lines, &$ok, &$skip) {
            $promos = Promo::whereIn('id', $data['ids'])->orderBy('code')->get();
            // Batch: peta kode→toko + set kode global sekali (dulu 2x exists() per promo).
            $codeStore = Promo::pluck('store_id', 'code');
            $taken = [];
            foreach ($codeStore as $c => $s) $taken[(string) $c] = true;
            foreach ($promos as $p) {
                // Kode sama persis di toko tujuan → lewati (idempoten).
                if ((int) ($codeStore->get($p->code) ?? -1) === (int) $store->id) {
                    $skip++;
                    $lines[] = $p->code . ' → ' . $store->name . ': dilewati (kode sudah ada di toko)';
                    continue;
                }
                if (strlen($p->code . $suffix) > 50) {
                    $candidate = substr($p->code, 0, 50 - strlen($suffix)) . $suffix;
                } else {
                    $candidate = $p->code . $suffix;
                }
                // Kode turunan sudah dipakai global (unique) → lewati.
                if (isset($taken[$candidate])) {
                    $skip++;
                    $lines[] = $p->code . ' → ' . $store->name . ': dilewati (kode ' . $candidate . ' sudah dipakai)';
                    continue;
                }
                $copy = $p->replicate();
                $copy->code = $candidate;
                $copy->store_id = $store->id;
                $copy->start_date = null;
                $copy->end_date = null;
                $copy->used = 0;
                $copy->is_active = false;
                $copy->save();
                $taken[$candidate] = true;
                $codeStore[$candidate] = $store->id;
                $ok++;
                $lines[] = $p->code . ' → ' . $candidate . ' @ ' . $store->name . ' (dibuat, nonaktif)';
            }
        });

        $msg = $ok . ' promo disalin ke ' . $store->name . ' (nonaktif, tanggal dikosongkan).';
        if ($skip > 0) {
            $msg .= ' Dilewati ' . $skip . '.';
        }
        if (!empty($lines)) {
            $msg .= ' Rincian: ' . implode('; ', $lines) . '.';
        }

        return redirect()
            ->route('promos.index')
            ->with('success', $msg);
    }
}
