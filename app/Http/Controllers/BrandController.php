<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Product;
use App\Support\LookupCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BrandController extends Controller
{
    public function index(Request $request)
    {
        $brands = Brand::withCount('products')
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%' . $request->q . '%'))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        // Section "Kemungkinan Duplikat" by nama — hanya saat tidak sedang mencari (agar ringan)
        $duplicateGroups = $request->filled('q') ? [] : self::findDuplicateGroups();

        return view('brands.index', compact('brands', 'duplicateGroups'));
    }

    /** Kunci nama mirip: lowercase tanpa spasi & non-alfanumerik (cermin SupplierController). */
    public static function nameKey(string $name): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(trim($name)));
    }

    /**
     * Kelompok kemungkinan duplikat by nama (max 20 grup), dihitung di PHP.
     * Hanya saat tidak sedang mencari (agar ringan) — cermin SupplierController.
     */
    public static function findDuplicateGroups(int $maxGroups = 20, int $scanLimit = 2000): array
    {
        $rows = Brand::query()
            ->orderByDesc('id')
            ->limit($scanLimit)
            ->get(['id', 'name']);

        $byName = [];
        foreach ($rows as $b) {
            $nk = self::nameKey((string) $b->name);
            if ($nk !== '') {
                $byName[$nk][] = $b;
            }
        }

        $groups = [];
        $seen = [];
        foreach ($byName as $key => $list) {
            if (count($list) < 2 || count($groups) >= $maxGroups) {
                continue;
            }
            $members = collect($list)->sortByDesc('id')->values();
            $ids = $members->map(fn ($m) => $m->id)->sort()->values()->implode('-');
            if (isset($seen[$ids])) {
                continue;
            }
            $seen[$ids] = true;
            $groups[] = ['kind' => 'Nama mirip', 'key' => (string) $key, 'members' => $members];
        }

        return array_slice($groups, 0, $maxGroups);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255|unique:brands,name',
        ]);

        Brand::create(['name' => trim($data['name'])]);
        LookupCache::forgetAll();

        return redirect()->route('brands.index')->with('success', 'Merek ditambahkan.');
    }

    public function update(Request $request, Brand $brand)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255|unique:brands,name,' . $brand->id,
        ]);

        $brand->update(['name' => trim($data['name'])]);
        LookupCache::forgetAll();

        return redirect()->route('brands.index')->with('success', 'Merek diperbarui.');
    }

    /**
     * Gabung merek duplikat ke satu primary (cermin SupplierController@merge).
     * Pindahkan products ber-FK brand_id ke primary, lalu hapus duplikat. Tanpa migration.
     */
    protected static function mergeIdsIntoPrimary(int $primaryId, array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!in_array($primaryId, $ids, true)) {
            throw new \InvalidArgumentException('Primary harus salah satu dari grup duplikat.');
        }

        $members = Brand::whereIn('id', $ids)->get();
        if ($members->count() < 2) {
            throw new \InvalidArgumentException('Pilih minimal 2 merek untuk digabung.');
        }

        $primary = $members->firstWhere('id', $primaryId);
        $dupeIds = $members->where('id', '!=', $primary->id)->pluck('id')->all();

        DB::transaction(function () use ($primary, $dupeIds) {
            Product::whereIn('brand_id', $dupeIds)->update(['brand_id' => $primary->id]);
            Brand::whereIn('id', $dupeIds)->delete();
        });
    }

    public function merge(Request $request)
    {
        $data = $request->validate([
            'primary_id' => 'required|exists:brands,id',
            'ids' => 'required|array|min:2',
            'ids.*' => 'exists:brands,id',
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['ids'])));
        if (!in_array((int) $data['primary_id'], $ids, true)) {
            return back()->withErrors(['primary_id' => 'Primary harus salah satu dari grup duplikat.']);
        }

        $members = Brand::whereIn('id', $ids)->get();
        if ($members->count() < 2) {
            return back()->withErrors(['ids' => 'Pilih minimal 2 merek untuk digabung.']);
        }

        $primary = $members->firstWhere('id', (int) $data['primary_id']);
        $dupeIds = $members->where('id', '!=', $primary->id)->pluck('id')->all();

        try {
            self::mergeIdsIntoPrimary((int) $primary->id, $dupeIds ? array_merge([$primary->id], $dupeIds) : [$primary->id]);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['ids' => 'Gagal gabung: ' . $e->getMessage()]);
        }
        LookupCache::forgetAll();

        return redirect()->route('brands.index')->with('success', 'Merek berhasil digabung ke "' . $primary->name . '".');
    }

    /**
     * Gabung OTOMATIS semua grup duplikat nama SAMA PERSIS (cermin suppliers.merge-exact).
     * ATURAN primary: ID TERKECIL = master tertua.
     * - Hanya grup kind 'Nama mirip' yang SEMUA anggotanya punya nama mentah
     *   identik persis (string sama, case-sensitive). Grup yang hanya cocok
     *   ternormalisasi (beda kapital/spasi/simbol, cth "Apple" vs "apple ")
     *   dianggap AMBIGU → dilewati + dilaporkan, lanjutkan manual per grup.
     * - TIDAK ada fuzzy/levenshtein/similar_text.
     * - Tiap grup jalan dalam transaction sendiri (via mergeIdsIntoPrimary):
     *   1 grup gagal tidak menggagalkan grup lain; hasil per grup dilaporkan
     *   via flash session. POST /brands/merge-exact — tanpa migration.
     */
    public function mergeExact()
    {
        $groups = array_values(array_filter(
            self::findDuplicateGroups(),
            fn ($g) => ($g['kind'] ?? '') === 'Nama mirip'
        ));
        if (empty($groups)) {
            return back()->with('error', 'Tidak ada grup duplikat yang perlu digabung.');
        }

        $ok = [];
        $skipped = [];
        $failed = [];
        foreach ($groups as $g) {
            $ids = $g['members']->map(fn ($m) => (int) $m->id)->unique()->values()->all();
            sort($ids);
            $primaryId = $ids[0]; // ID terkecil = master tertua
            // Guard nama sama persis: semua nama mentah harus identik.
            $rawNames = $g['members']->map(fn ($m) => (string) $m->name)->unique()->values()->all();
            $label = 'Nama "' . $g['key'] . '" (ID ' . implode(',', $ids) . ' → primary #' . $primaryId . ')';
            if (count($rawNames) > 1) {
                $skipped[] = $label . ' — dilewati: nama tidak sama persis (' . implode(' / ', $rawNames) . '), gabung manual per grup';
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

        LookupCache::forgetAll();

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
            . '(hanya nama sama persis; tanpa fuzzy).';
        if (count($failed) > 0) {
            return back()->with('brand_merge_exact_summary', $summary)->with('error', $msg);
        }

        return back()->with('brand_merge_exact_summary', $summary)->with('success', $msg);
    }

    /**
     * UNDUH TEMPLATE CSV merek: header + 1 baris contoh, delimiter ;, BOM.
     * Kolom cocok dengan @import (nama).
     * GET /brands/template — didaftarkan SEBELUM resource.
     */
    public function template()
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv(
                $out,
                ['nama'],
                ';'
            );
            fputcsv(
                $out,
                ['Samsung'],
                ';'
            );
            fclose($out);
        }, 'template_merek.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * IMPORT CSV MEREK (cermin ProductController@import).
     * Kolom: nama (alias: name). Delimiter otomatis `;`/`,`,
     * header `id` (kolom `id` hasil export) diabaikan.
     * - nama wajib, max 255; kosong → baris gagal + dilaporkan.
     * - Duplikat = LEWATI bila nama sudah ada (case-insensitive,
     *   LOWER(name) sama) + dilaporkan di session `import_fail`.
     * - Tanpa migration. POST /brands/import.
     */
    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:2048']);

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
        $ok = 0; $skipped = 0; $fail = []; $line = 1;
        while (($cols = $readCsv()) !== false) {
            $line++;
            if (count($cols) === 1 && trim((string) $cols[0]) === '') continue;
            $row = array_combine(array_slice(array_merge($header, range(0, count($cols) - 1)), 0, count($cols)), $cols);
            $g = fn($k) => trim((string) ($row[$k] ?? ''));
            $nama = $g('nama') !== '' ? $g('nama') : $g('name');
            if ($nama === '') { $fail[] = "Baris $line: nama kosong"; continue; }
            if (mb_strlen($nama) > 255) { $fail[] = "Baris $line: nama max 255 karakter"; continue; }

            try {
                $exists = Brand::whereRaw('LOWER(name) = ?', [mb_strtolower($nama, 'UTF-8')])->exists();
                if ($exists) {
                    $skipped++;
                    $fail[] = "Baris $line: dilewati — duplikat ($nama sudah ada)";
                    continue;
                }
                Brand::create(['name' => $nama]);
                $ok++;
            } catch (\Throwable $e) {
                report($e);
                $fail[] = "Baris $line: " . $e->getMessage();
            }
        }
        fclose($handle);
        LookupCache::forgetAll();

        return redirect()->route('brands.index')
            ->with('success', "Import selesai: $ok berhasil, $skipped dilewati (duplikat), " . (count($fail) - $skipped) . ' gagal.')
            ->with('import_fail', $fail);
    }

    public function destroy(Brand $brand)
    {
        $used = $brand->products()->count();
        if ($used > 0) {
            return back()->withErrors([
                'brand' => 'Merek "' . $brand->name . '" dipakai ' . $used . ' produk — tidak bisa dihapus. Gabung (merge) ke merek lain dulu.',
            ]);
        }

        $brand->delete();
        LookupCache::forgetAll();

        return redirect()->route('brands.index')->with('success', 'Merek dihapus.');
    }
}
