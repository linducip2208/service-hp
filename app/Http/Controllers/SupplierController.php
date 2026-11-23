<?php

namespace App\Http\Controllers;

use App\Models\Debt;
use App\Models\Supplier;
use App\Support\LookupCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $suppliers = Supplier::with('store')->withCount('purchases')
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%' . $request->q . '%'))
            ->orderBy('name')->paginate(20)->withQueryString();

        // Outstanding per supplier = Σ(total - paid) pembelian belum lunas (tanpa tabel baru)
        $outstandingMap = [];
        try {
            $outstandingMap = \App\Models\Purchase::whereIn('status', ['belum', 'sebagian'])
                ->selectRaw('supplier_id, SUM(total - paid) as sisa')
                ->groupBy('supplier_id')->pluck('sisa', 'supplier_id')->toArray();
        } catch (\Throwable $e) {
            report($e);
        }

        // Scorecard supplier (scope toko aktif, tanpa migration/tabel/route baru)
        $storeId = session('active_store_id');
        $monthTotalMap = $notaCountMap = $lastBuyMap = [];
        try {
            $ids = $suppliers->pluck('id')->all();
            if ($ids) {
                $base = \App\Models\Purchase::whereIn('supplier_id', $ids)
                    ->when($storeId, fn ($q) => $q->where('store_id', $storeId));
                $monthTotalMap = (clone $base)
                    ->whereYear('purchases.created_at', now()->year)
                    ->whereMonth('purchases.created_at', now()->month)
                    ->selectRaw('supplier_id, SUM(total) as agg')
                    ->groupBy('supplier_id')->pluck('agg', 'supplier_id')->toArray();
                $notaCountMap = (clone $base)
                    ->selectRaw('supplier_id, COUNT(*) as agg')
                    ->groupBy('supplier_id')->pluck('agg', 'supplier_id')->toArray();
                $lastBuyMap = (clone $base)
                    ->selectRaw('supplier_id, MAX(created_at) as agg')
                    ->groupBy('supplier_id')->pluck('agg', 'supplier_id')->toArray();
            }
        } catch (\Throwable $e) {
            report($e);
        }

        // Section "Kemungkinan Duplikat" by nama — hanya saat tidak sedang mencari (agar ringan)
        $duplicateGroups = $request->filled('q') ? [] : self::findDuplicateGroups($storeId ? (int) $storeId : null);

        // Export CSV (?export=csv) — hormati filter q; agregat ringan terbatas pada ID tereksport
        if ($request->get('export') === 'csv') {
            $rows = Supplier::with('store')->withCount('purchases')
                ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%' . $request->q . '%'))
                ->orderBy('name')->limit(5000)->get();
            $ids = $rows->pluck('id')->all();
            $outExp = $totExp = $monthExp = $lastExp = [];
            try {
                if ($ids) {
                    $outExp = \App\Models\Purchase::whereIn('supplier_id', $ids)
                        ->whereIn('status', ['belum', 'sebagian'])
                        ->selectRaw('supplier_id, SUM(total - paid) as sisa')
                        ->groupBy('supplier_id')->pluck('sisa', 'supplier_id')->toArray();
                    $scoped = \App\Models\Purchase::whereIn('supplier_id', $ids)
                        ->when($storeId, fn ($q) => $q->where('store_id', $storeId));
                    $totExp = (clone $scoped)
                        ->selectRaw('supplier_id, SUM(total) as agg')
                        ->groupBy('supplier_id')->pluck('agg', 'supplier_id')->toArray();
                    $monthExp = (clone $scoped)
                        ->whereYear('purchases.created_at', now()->year)
                        ->whereMonth('purchases.created_at', now()->month)
                        ->selectRaw('supplier_id, SUM(total) as agg')
                        ->groupBy('supplier_id')->pluck('agg', 'supplier_id')->toArray();
                    $lastExp = (clone $scoped)
                        ->selectRaw('supplier_id, MAX(created_at) as agg')
                        ->groupBy('supplier_id')->pluck('agg', 'supplier_id')->toArray();
                }
            } catch (\Throwable $e) {
                report($e);
            }
            $filename = 'supplier-' . now()->format('Ymd-His') . '.csv';
            return response()->streamDownload(function () use ($rows, $outExp, $totExp, $monthExp, $lastExp) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['id', 'kode', 'nama', 'hp', 'alamat', 'toko', 'jumlah_nota', 'total_beli_rp', 'beli_bulan_ini_rp', 'hutang_rp', 'outstanding_rp', 'pembelian_terakhir'], ';');
                foreach ($rows as $s) {
                    fputcsv($out, [
                        $s->id,
                        $s->code ?? '',
                        $s->name,
                        $s->phone ?? '',
                        $s->address ?? '',
                        $s->store->name ?? '',
                        (int) ($s->purchases_count ?? 0),
                        (float) ($totExp[$s->id] ?? 0),
                        (float) ($monthExp[$s->id] ?? 0),
                        (float) $s->hutang,
                        (float) ($outExp[$s->id] ?? 0),
                        $lastExp[$s->id] ?? '',
                    ], ';');
                }
                fclose($out);
            }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return view('suppliers.index', compact('suppliers', 'outstandingMap', 'monthTotalMap', 'notaCountMap', 'lastBuyMap', 'duplicateGroups'));
    }

    /** Kunci nama mirip: lowercase tanpa spasi & non-alfanumerik (cermin CustomerController). */
    public static function nameKey(string $name): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(trim($name)));
    }

    /**
     * Kelompok kemungkinan duplikat by nama (max 20 grup), dihitung di PHP.
     * Hanya saat tidak sedang mencari (agar ringan) — cermin CustomerController.
     */
    public static function findDuplicateGroups(?int $storeId, int $maxGroups = 20, int $scanLimit = 2000): array
    {
        $rows = Supplier::query()
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->orderByDesc('id')->limit($scanLimit)->get(['id', 'name', 'phone', 'code', 'store_id', 'hutang']);
        $byName = [];
        foreach ($rows as $s) {
            $nk = self::nameKey((string) $s->name);
            if ($nk !== '') $byName[$nk][] = $s;
        }
        $groups = [];
        $seen = [];
        foreach ($byName as $key => $list) {
            if (count($list) < 2 || count($groups) >= $maxGroups) continue;
            $members = collect($list)->sortByDesc('id')->values();
            $ids = $members->map(fn ($m) => $m->id)->sort()->values()->implode('-');
            if (isset($seen[$ids])) continue;
            $seen[$ids] = true;
            $groups[] = ['kind' => 'Nama mirip', 'key' => (string) $key, 'members' => $members];
        }
        return array_slice($groups, 0, $maxGroups);
    }

    public function create()
    {
        $stores = LookupCache::activeStores();
        return view('suppliers.create', compact('stores'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'store_id' => 'nullable|exists:stores,id',
            'code' => 'required|string|max:50',
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string',
        ]);
        $data['store_id'] = $data['store_id'] ?? session('active_store_id');
        $supplier = Supplier::create($data);
        return redirect()->route('suppliers.show', $supplier)->with('success', 'Supplier ditambahkan.');
    }

    /** Detail + info hutang + riwayat pembelian (retur: tampilkan sisa hutang terkait). */
    public function show(Supplier $supplier)
    {
        $supplier->load('store');
        $purchases = $supplier->purchases()->with('items')->orderByDesc('id')->limit(30)->get();
        $outstanding = $supplier->purchases()->whereIn('status', ['belum', 'sebagian'])->get()
            ->sum(fn ($p) => (float) $p->total - (float) $p->paid);
        $debts = Debt::where('ref_type', 'purchase')
            ->whereIn('ref_id', $purchases->pluck('id'))
            ->where('status', '!=', 'lunas')->orderByDesc('id')->get();

        // Kartu skor supplier (scope toko aktif, tanpa migration/tabel/route baru).
        // Retur: stock_movements bertipe keluar/ref_type=retur_beli tertaut ke supplier
        // via purchases.id = ref_id, TAPI movement hanya menyimpan qty (tanpa harga),
        // jadi nominal Rp retur tidak bisa dihitung jujur dari data existing → tampil "–".
        $storeId = session('active_store_id');
        $score = [
            'total_all' => 0, 'total_month' => 0, 'nota_count' => 0, 'avg_nota' => 0,
            'retur_count' => 0, 'retur_qty' => 0, 'last' => null,
        ];
        try {
            $scoped = $supplier->purchases()->when($storeId, fn ($q) => $q->where('store_id', $storeId));
            $score['total_all'] = (float) (clone $scoped)->sum('total');
            $score['total_month'] = (float) (clone $scoped)
                ->whereYear('created_at', now()->year)->whereMonth('created_at', now()->month)->sum('total');
            $score['nota_count'] = (clone $scoped)->count();
            $score['avg_nota'] = $score['nota_count'] > 0 ? $score['total_all'] / $score['nota_count'] : 0;
            $score['last'] = (clone $scoped)->orderByDesc('id')->first();
            $retur = \App\Models\StockMovement::join('purchases', function ($j) {
                    $j->on('purchases.id', '=', 'stock_movements.ref_id')
                      ->where('stock_movements.ref_type', '=', 'retur_beli');
                })
                ->where('purchases.supplier_id', $supplier->id)
                ->when($storeId, fn ($q) => $q->where('stock_movements.store_id', $storeId))
                ->selectRaw('COUNT(*) as c, COALESCE(SUM(stock_movements.qty),0) as q')->first();
            $score['retur_count'] = (int) ($retur->c ?? 0);
            $score['retur_qty'] = (int) ($retur->q ?? 0);
        } catch (\Throwable $e) {
            report($e);
        }
        return view('suppliers.show', compact('supplier', 'purchases', 'outstanding', 'debts', 'score'));
    }

    public function edit(Supplier $supplier)
    {
        $stores = LookupCache::activeStores();
        return view('suppliers.edit', compact('supplier', 'stores'));
    }

    public function update(Request $request, Supplier $supplier)
    {
        $data = $request->validate([
            'store_id' => 'nullable|exists:stores,id',
            'code' => 'required|string|max:50',
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string',
        ]);
        $supplier->update($data);
        return redirect()->route('suppliers.show', $supplier)->with('success', 'Supplier diperbarui.');
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
        $members = Supplier::whereIn('id', $ids)->get();
        if ($members->count() < 2) {
            throw new \InvalidArgumentException('Pilih minimal 2 supplier untuk digabung.');
        }
        $nonNullStores = $members->pluck('store_id')->filter(fn ($s) => $s !== null)->unique()->values();
        if ($nonNullStores->count() > 1) {
            throw new \InvalidArgumentException('Supplier berasal dari toko berbeda.');
        }
        $primary = $members->firstWhere('id', $primaryId);
        $dupes = $members->where('id', '!=', $primary->id);
        $dupeIds = $dupes->pluck('id')->all();

        DB::transaction(function () use ($primary, $dupes, $dupeIds, $nonNullStores) {
            // Lengkapi field kosong primary dari duplikat (tanpa menimpa yang sudah ada)
            foreach ($dupes as $d) {
                if (!$primary->phone && $d->phone) $primary->phone = $d->phone;
                if (!$primary->address && $d->address) $primary->address = $d->address;
            }
            // Bila primary null-store tapi duplikat punya toko → adopsi toko tsb
            if ($primary->store_id === null && $nonNullStores->count() === 1) {
                $primary->store_id = $nonNullStores->first();
            }
            // Gabung hutang supplier (kolom suppliers.hutang)
            $primary->hutang = (float) $primary->hutang + (float) $dupes->sum('hutang');
            $primary->save();

            // Pindahkan relasi ber-FK supplier_id
            \App\Models\Purchase::whereIn('supplier_id', $dupeIds)->update(['supplier_id' => $primary->id]);
            \App\Models\Consignment::whereIn('supplier_id', $dupeIds)->update(['supplier_id' => $primary->id]);
            \App\Models\ProductItem::whereIn('supplier_id', $dupeIds)->update(['supplier_id' => $primary->id]);
            // Debts ikut via purchases (ref_type=purchase, ref_id=purchase.id) — tanpa pindah manual.
            foreach ($dupes as $d) {
                $d->delete();
            }
        });
    }

    /**
     * Gabung supplier duplikat ke satu primary (cermin CustomerController@merge).
     * Pindahkan purchases, consignments, product_items ber-FK supplier_id,
     * gabung hutang supplier (sum ke primary), lalu hapus duplikat. Tanpa migration.
     * Debts ikut via purchases (ref_type=purchase, ref_id=purchase.id) — tanpa pindah manual.
     */
    public function merge(Request $request)
    {
        $data = $request->validate([
            'primary_id' => 'required|exists:suppliers,id',
            'ids' => 'required|array|min:2',
            'ids.*' => 'exists:suppliers,id',
        ]);
        try {
            self::mergeIdsIntoPrimary((int) $data['primary_id'], $data['ids']);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['ids' => 'Gagal gabung: ' . $e->getMessage()]);
        }
        $primary = Supplier::find((int) $data['primary_id']);

        return redirect()->route('suppliers.show', $primary)->with('success', 'Supplier berhasil digabung.');
    }

    /**
     * Gabung OTOMATIS semua grup duplikat nama exact ternormalisasi (nameKey).
     * ATURAN primary: ID TERKECIL = master tertua (cermin customers.merge-exact).
     * - Hanya grup kind 'Nama mirip' yang dihitung dari nameKey exact
     *   (lowercase tanpa spasi & non-alfanumerik). TIDAK ada fuzzy/levenshtein/
     *   similar_text — grup mirip bunyi/ejaan tidak disentuh, lanjutkan manual.
     * - Guard lintas-toko: grup dengan >1 store_id non-null dilewati + dilaporkan.
     * - Tiap grup jalan dalam transaction sendiri (via mergeIdsIntoPrimary): 1 grup gagal
     *   tidak menggagalkan grup lain; hasil per grup dilaporkan via flash session.
     * POST /suppliers/merge-exact — tanpa migration, tanpa tabel baru.
     */
    public function mergeExact(Request $request)
    {
        $storeId = session('active_store_id');
        $storeId = $storeId ? (int) $storeId : null;
        $groups = array_values(array_filter(
            self::findDuplicateGroups($storeId),
            fn ($g) => ($g['kind'] ?? '') === 'Nama mirip'
        ));
        if (empty($groups)) {
            return back()->with('error', 'Tidak ada grup duplikat nama sama persis yang perlu digabung.');
        }
        $ok = [];
        $skipped = [];
        $failed = [];
        foreach ($groups as $g) {
            $ids = $g['members']->map(fn ($m) => (int) $m->id)->unique()->values()->all();
            sort($ids);
            $primaryId = $ids[0]; // ID terkecil = master tertua
            $label = 'Nama "' . $g['key'] . '" (ID ' . implode(',', $ids) . ' → primary #' . $primaryId . ')';
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
            . '(hanya nama sama persis ternormalisasi; tanpa fuzzy).';
        if (count($failed) > 0) {
            return back()->with('supplier_merge_exact_summary', $summary)->with('error', $msg);
        }
        return back()->with('supplier_merge_exact_summary', $summary)->with('success', $msg);
    }

    /**
     * UNDUH TEMPLATE CSV supplier: header + 1 baris contoh, delimiter ;, BOM.
     * Kolom cocok dengan @import (kode,nama,hp,alamat).
     * GET /suppliers/template — didaftarkan SEBELUM resource agar tak tertabrak {supplier}.
     */
    public function template()
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv(
                $out,
                ['kode', 'nama', 'hp', 'alamat'],
                ';'
            );
            fputcsv(
                $out,
                ['SUP-001', 'PT Ponsel Jaya', '08123456789', 'Jl. Glodok No.1'],
                ';'
            );
            fclose($out);
        }, 'template_supplier.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * IMPORT CSV SUPPLIER (cermin ProductController@import).
     * Kolom: kode,nama,hp,alamat (alias: code/kode, name/nama,
     * phone/hp/telepon/telp/no_hp, address/alamat).
     * - nama wajib; kode kosong → otomatis SUP-xxx (unik).
     * - Duplikat = UPDATE bila nama sama persis case-insensitive
     *   (LOWER(name) sama) dalam scope toko aktif: hp & alamat & kode
     *   ditimpa hanya bila kolom CSV non-kosong, hutang tidak diubah.
     * - Tanpa migration. POST /suppliers/import.
     */
    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:2048']);
        $storeId = session('active_store_id') ? (int) session('active_store_id') : null;

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        // Deteksi delimiter: file hasil Excel Indonesia memakai ';', file manual umumnya ','.
        $firstLine = fgets($handle) ?: '';
        rewind($handle);
        $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';
        $readCsv = function () use ($handle, $delimiter) {
            $cols = fgetcsv($handle, 0, $delimiter);
            if (is_array($cols)) $cols = array_map(fn($c) => is_string($c) ? ltrim($c, "\xEF\xBB\xBF") : $c, $cols);
            return $cols;
        };
        $header = array_map(fn($h) => strtolower(trim((string) $h)), $readCsv() ?: []);
        $created = 0; $updated = 0; $fail = []; $line = 1;
        while (($cols = $readCsv()) !== false) {
            $line++;
            if (count($cols) === 1 && trim((string) $cols[0]) === '') continue;
            $row = array_combine(array_slice(array_merge($header, range(0, count($cols) - 1)), 0, count($cols)), $cols);
            $g = fn($k) => trim((string) ($row[$k] ?? ''));
            $err = null;
            $nama = $g('nama') !== '' ? $g('nama') : $g('name');
            $kode = $g('kode') !== '' ? $g('kode') : $g('code');
            $hp = $g('hp');
            foreach (['phone', 'telepon', 'telp', 'no_hp'] as $alias) {
                if ($hp === '' && $g($alias) !== '') $hp = $g($alias);
            }
            $alamat = $g('alamat') !== '' ? $g('alamat') : $g('address');
            if ($nama === '') $err = 'nama kosong';
            elseif (mb_strlen($nama) > 255) $err = 'nama max 255 karakter';
            elseif ($kode !== '' && mb_strlen($kode) > 50) $err = 'kode max 50 karakter';
            elseif ($hp !== '' && mb_strlen($hp) > 50) $err = 'hp max 50 karakter';
            if ($err) { $fail[] = "Baris $line: $err"; continue; }

            try {
                $existing = Supplier::whereRaw('LOWER(name) = ?', [mb_strtolower($nama, 'UTF-8')])
                    ->when($storeId, fn($q) => $q->where('store_id', $storeId))
                    ->first();
                if ($existing) {
                    $attrs = [];
                    if ($kode !== '') $attrs['code'] = $kode;
                    if ($hp !== '') $attrs['phone'] = $hp;
                    if ($alamat !== '') $attrs['address'] = $alamat;
                    if ($attrs) $existing->update($attrs);
                    $updated++;
                } else {
                    $newCode = $kode;
                    if ($newCode === '') {
                        do {
                            $newCode = 'SUP-' . now()->format('Ymd') . '-' . strtoupper(\Illuminate\Support\Str::random(4));
                        } while (Supplier::where('code', $newCode)->exists());
                    }
                    Supplier::create([
                        'store_id' => $storeId,
                        'code' => $newCode,
                        'name' => $nama,
                        'phone' => $hp !== '' ? $hp : null,
                        'address' => $alamat !== '' ? $alamat : null,
                    ]);
                    $created++;
                }
            } catch (\Throwable $e) {
                $fail[] = "Baris $line: " . $e->getMessage();
            }
        }
        fclose($handle);

        return redirect()->route('suppliers.index')
            ->with('success', "Import supplier selesai: $created baru, $updated diperbarui, " . count($fail) . ' gagal.')
            ->with('import_fail', $fail);
    }

    public function destroy(Supplier $supplier)
    {
        $supplier->delete();
        return redirect()->route('suppliers.index')->with('success', 'Supplier dihapus.');
    }

    /**
     * UNDUH KONTAK vCard (.vcf) per supplier: nama, HP, alamat.
     * Cermin customers.member-card (GET singular sebelum resource, tanpa migration).
     */
    public function vcard(Supplier $supplier)
    {
        $supplier->loadMissing('store');
        $esc = function (?string $v): string {
            $v = (string) ($v ?? '');
            $v = str_replace('\\', '\\\\', $v);
            $v = str_replace(["\r\n", "\r", "\n"], '\\n', $v);
            $v = str_replace([',', ';'], ['\\,', '\\;'], $v);
            return trim($v);
        };
        $name = trim((string) $supplier->name) !== '' ? (string) $supplier->name : 'Supplier #' . $supplier->id;
        $phone = trim((string) ($supplier->phone ?? ''));
        $address = trim((string) ($supplier->address ?? ''));
        $lines = [
            'BEGIN:VCARD',
            'VERSION:3.0',
            'FN:' . $esc($name),
            'N:' . $esc($name) . ';;;;',
        ];
        if ($phone !== '') {
            $lines[] = 'TEL;TYPE=CELL,VOICE:' . $esc($phone);
        }
        if ($address !== '') {
            $lines[] = 'ADR;TYPE=WORK:;;' . $esc($address) . ';;;;';
        }
        $lines[] = 'NOTE:' . $esc('Supplier ' . ($supplier->code ?? '') . ($supplier->store->name ?? '' ? ' — ' . $supplier->store->name : ''));
        $lines[] = 'END:VCARD';
        $vcf = implode("\r\n", $lines) . "\r\n";
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
        $slug = trim($slug, '-') !== '' ? trim($slug, '-') : ('supplier-' . $supplier->id);
        $filename = $slug . '-' . $supplier->id . '.vcf';

        return response($vcf, 200, [
            'Content-Type' => 'text/vcard; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
