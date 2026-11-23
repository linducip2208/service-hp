<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductItem;
use App\Models\StockMovement;
use App\Models\TradeIn;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TradeInController extends Controller
{
    public function index(Request $request)
    {
        $storeId = (int) session('active_store_id');
        $showAll = $request->boolean('all') && (auth()->user()->role ?? '') === 'superadmin';
        $base = TradeIn::with(['customer', 'sale', 'store'])
            ->when(!$showAll && $storeId, fn($q) => $q->where('store_id', $storeId))
            ->when($request->filled('q'), fn($q) => $q->where(function ($w) {
                $w->where('old_brand', 'like', '%' . request('q') . '%')
                  ->orWhere('old_type', 'like', '%' . request('q') . '%')
                  ->orWhere('imei', 'like', '%' . request('q') . '%');
            }))
            ->orderByDesc('id');
        // Export CSV (?export=csv) — hormati filter q + scope toko, delimiter ; + BOM.
        if ($request->get('export') === 'csv') {
            $rows = (clone $base)->limit(5000)->get();
            $filename = 'tukar-tambah-' . now()->format('Ymd-His') . '.csv';
            return response()->streamDownload(function () use ($rows) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['id', 'tanggal', 'toko', 'pelanggan', 'merek_lama', 'tipe_lama', 'imei', 'catatan', 'taksiran', 'sale_id'], ';');
                foreach ($rows as $t) {
                    fputcsv($out, [$t->id, $t->created_at?->format('Y-m-d H:i') ?? '', $t->store->name ?? '', $t->customer->name ?? '', $t->old_brand, $t->old_type, $t->imei ?? '', $t->condition_note ?? '', (float) $t->appraisal_price, $t->sale_id ?? ''], ';');
                }
                fclose($out);
            }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }
        $tradeIns = (clone $base)->paginate(20)->withQueryString();
        $customers = Customer::when(!$showAll && $storeId, fn($q) => $q->where('store_id', $storeId))->orderBy('name')->get();
        return view('tradeins.index', compact('tradeIns', 'customers', 'showAll'));
    }

    public function store(Request $request)
    {
        $storeId = (int) session('active_store_id');
        abort_if(!$storeId, 422, 'Toko aktif belum dipilih.');
        \App\Support\PeriodLock::check($storeId, now()->toDateString(), 'Tanggal pencatatan');

        $data = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'old_brand' => 'required|string|max:100',
            'old_type' => 'required|string|max:100',
            'imei' => 'nullable|string|max:50|unique:product_items,imei',
            'condition_note' => 'nullable|string|max:1000',
            'appraisal_price' => 'required|numeric|min:0',
            'jadikan_stok' => 'nullable|boolean',
            'photos' => 'required|array|min:1|max:2',
            'photos.*' => 'required|image|max:2048',
            'market_price' => 'nullable|numeric|min:0',
            'ck_layar' => 'nullable|in:baik,kurang,rusak',
            'ck_baterai' => 'nullable|in:baik,kurang,rusak',
            'ck_tombol' => 'nullable|in:baik,kurang,rusak',
            'ck_sinyal' => 'nullable|in:baik,kurang,rusak',
            'ck_kelengkapan' => 'nullable|in:baik,kurang,rusak',
        ], [
            'photos.required' => 'Foto bukti tukar tambah wajib diisi (minimal 1 foto).',
            'photos.min' => 'Foto bukti tukar tambah wajib diisi (minimal 1 foto).',
            'photos.*.required' => 'Foto bukti tukar tambah wajib diisi (minimal 1 foto).',
            'photos.*.image' => 'Foto bukti harus berupa gambar.',
            'photos.*.max' => 'Ukuran tiap foto maksimal 2 MB.',
        ]);

        $guide = $this->appraisalGuide($data);
        $note = $data['condition_note'] ?? null;
        if ($guide) {
            $summary = '[Skor ' . $guide['score'] . '/100 • Grade ' . $guide['grade']
                . ' • Pasar Rp ' . number_format($guide['market'], 0, ',', '.')
                . ' → saran Rp ' . number_format($guide['suggestion'], 0, ',', '.') . '] '
                . 'Layar:' . $guide['labels']['ck_layar'] . '; Baterai:' . $guide['labels']['ck_baterai']
                . '; Tombol:' . $guide['labels']['ck_tombol'] . '; Sinyal:' . $guide['labels']['ck_sinyal']
                . '; Kelengkapan:' . $guide['labels']['ck_kelengkapan'];
            $note = trim($summary . ($note ? ' | ' . $note : ''));
            $note = mb_substr($note, 0, 1000);
        }

        // Pola foto = ServiceController@store: file ke disk public (maks 2 @2MB),
        // path dilacak via marker [FOTO:path] di condition_note (tanpa migration).
        $photoPaths = [];
        if ($request->hasFile('photos')) {
            foreach ((array) $request->file('photos') as $f) {
                if (!$f || !$f->isValid()) {
                    continue;
                }
                if (count($photoPaths) >= 2) {
                    break;
                }
                $photoPaths[] = $f->store('tradein', 'public');
            }
        }
        if ($photoPaths) {
            $markers = implode(' ', array_map(fn($p) => '[FOTO:' . $p . ']', $photoPaths));
            $note = mb_substr(trim(($note ? $note . ' ' : '') . $markers), 0, 1000);
        }

        DB::transaction(function () use ($data, $storeId, $note) {
            $tradeIn = TradeIn::create([
                'store_id' => $storeId, 'sale_id' => null,
                'customer_id' => $data['customer_id'] ?? null,
                'old_brand' => $data['old_brand'], 'old_type' => $data['old_type'],
                'imei' => $data['imei'] ?? null, 'condition_note' => $note,
                'appraisal_price' => $data['appraisal_price'],
            ]);

            // Jadikan stok bekas bila diminta / appraisal > 0
            if (($data['jadikan_stok'] ?? true) && (float) $data['appraisal_price'] > 0) {
                $product = Product::firstOrCreate(
                    ['name' => trim($data['old_brand'] . ' ' . $data['old_type']), 'type' => 'hp_bekas'],
                    [
                        'sku' => 'TT-' . strtoupper(Str::random(6)),
                        'purchase_price' => $data['appraisal_price'],
                        'selling_price' => (float) $data['appraisal_price'] * 1.2,
                        'is_serialized' => true, 'is_active' => true,
                    ]
                );
                $unit = ProductItem::create([
                    'product_id' => $product->id, 'store_id' => $storeId,
                    'imei' => $data['imei'] ?? null, 'condition' => 'bekas',
                    'purchase_price' => $data['appraisal_price'],
                    'selling_price' => (float) $data['appraisal_price'] * 1.2,
                    'status' => 'tersedia',
                ]);
                StockMovement::create([
                    'store_id' => $storeId, 'product_id' => $product->id, 'product_item_id' => $unit->id,
                    'type' => 'masuk', 'qty' => 1, 'ref_type' => 'trade_in', 'ref_id' => $tradeIn->id,
                    'note' => 'Tukar tambah ' . $data['old_brand'] . ' ' . $data['old_type'],
                    'user_id' => auth()->id(),
                ]);
            }
        });

        return back()->with('success', 'Tukar tambah dicatat. Nilai appraisal bisa dipakai sebagai potongan di POS.');
    }

    /** Penilaian terpandu: skor 0-100 → grade → saran harga. Null bila form panduan tak dipakai. */
    protected function appraisalGuide(array $data): ?array
    {
        $keys = ['ck_layar', 'ck_baterai', 'ck_tombol', 'ck_sinyal', 'ck_kelengkapan'];
        $used = !empty($data['market_price']) || array_filter(array_intersect_key($data, array_flip($keys)));
        if (!$used) return null;
        $w = ['baik' => 20, 'kurang' => 10, 'rusak' => 0];
        $labels = [];
        $score = 0;
        $labelMap = ['baik' => 'Baik', 'kurang' => 'Kurang', 'rusak' => 'Rusak'];
        foreach ($keys as $k) {
            $v = $data[$k] ?? 'kurang';
            $score += $w[$v] ?? 10;
            $labels[$k] = $labelMap[$v] ?? $v;
        }
        $grade = $score >= 90 ? 'A' : ($score >= 75 ? 'B' : ($score >= 55 ? 'C' : 'D'));
        $pct = ['A' => 0.80, 'B' => 0.65, 'C' => 0.50, 'D' => 0.35][$grade];
        $market = (float) ($data['market_price'] ?? 0);
        return ['score' => $score, 'grade' => $grade, 'pct' => $pct, 'market' => $market,
            'suggestion' => round($market * $pct), 'labels' => $labels];
    }
}
