<?php

namespace App\Http\Controllers;

use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\ProductItem;
use App\Models\ProductStock;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ReturnController extends Controller
{
    public function index(Request $request)
    {
        $storeId = (int) session('active_store_id');
        $showAll = $request->boolean('all') && (auth()->user()->role ?? '') === 'superadmin';
        $base = SaleReturn::with(['sale', 'saleItem.product', 'store', 'user'])
            ->when(!$showAll && $storeId, fn($q) => $q->where('store_id', $storeId))
            ->orderByDesc('id');
        // Export CSV (?export=csv) — hormati scope toko (?all superadmin), delimiter ; + BOM.
        if ($request->get('export') === 'csv') {
            $rows = (clone $base)->limit(5000)->get();
            $filename = 'retur-' . now()->format('Ymd-His') . '.csv';
            return response()->streamDownload(function () use ($rows) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['id', 'tanggal', 'invoice', 'toko', 'tipe', 'nominal', 'item', 'alasan', 'bukti', 'ttd', 'kasir'], ';');
                foreach ($rows as $r) {
                    $bukti = null;
                    if (preg_match('/\[BUKTI:(.*?)\]/', (string) ($r->reason ?? ''), $mB)) {
                        $bukti = trim($mB[1]);
                    }
                    $sig = null;
                    if (preg_match('/\[SIG:(.*?)\]/', (string) ($r->reason ?? ''), $mS)) {
                        $sig = trim($mS[1]);
                    }
                    $alasan = trim(preg_replace('/\[(BUKTI|SIG):.*?\]/', '', (string) ($r->reason ?? '')));
                    fputcsv($out, [
                        $r->id,
                        $r->created_at?->format('Y-m-d H:i') ?? '',
                        $r->sale->invoice_no ?? '',
                        $r->store->name ?? '',
                        $r->type ?? '',
                        (float) $r->amount,
                        $r->saleItem->description ?? 'Semua item nota asal',
                        $alasan,
                        $bukti ?? '',
                        $sig ?? '',
                        $r->user->name ?? '',
                    ], ';');
                }
                fclose($out);
            }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }
        $returns = (clone $base)->paginate(20)->withQueryString();
        $sales = Sale::when(!$showAll && $storeId, fn($q) => $q->where('store_id', $storeId))
            ->orderByDesc('id')->limit(200)->get(['id', 'invoice_no', 'total']);
        return view('returns.index', compact('returns', 'sales', 'showAll'));
    }

    public function store(Request $request)
    {
        $storeId = (int) session('active_store_id');
        abort_if(!$storeId, 422, 'Toko aktif belum dipilih.');

        $data = $request->validate([
            'sale_id' => 'required|exists:sales,id',
            'sale_item_id' => 'nullable|exists:sale_items,id',
            'amount' => 'required|numeric|min:0',
            'reason' => 'nullable|string|max:1000',
            'type' => 'required|in:retur,refund,tukar',
            'bukti' => 'nullable|image|max:2048',
            'signature' => 'nullable|string|max:2000000',
        ], [
            'sale_id.required' => 'Pilih transaksi penjualan dulu.',
            'sale_id.exists' => 'Transaksi tidak ditemukan.',
            'amount.required' => 'Nominal retur/refund wajib diisi.',
            'type.required' => 'Jenis (retur/refund/tukar) wajib dipilih.',
            'type.in' => 'Jenis retur tidak valid.',
        ]);

        $sale = Sale::findOrFail($data['sale_id']);
        abort_if($sale->store_id !== $storeId, 403, 'Bukan transaksi toko aktif.');
        // VOID koreksi terakhir: reason diawali [VOID] → retur penuh 1 klik dari sales/index.
        // Guard: hanya transaksi HARI INI milik kasir yang login & belum pernah di-void.
        $isVoid = str_starts_with(trim((string) ($data['reason'] ?? '')), '[VOID]');
        if ($isVoid) {
            abort_if(!$sale->created_at->isToday(), 422, 'Void hanya untuk transaksi hari ini.');
            abort_if((int) $sale->user_id !== (int) auth()->id(), 403, 'Void hanya untuk transaksi milik kasir yang login.');
            abort_if(str_contains((string) $sale->note, '[VOID]'), 422, 'Transaksi ini sudah di-void.');
            abort_if(!empty($data['sale_item_id']), 422, 'Void memproses seluruh item sekaligus.');
            // Paksa nominal = total transaksi (retur penuh) + tipe retur
            $data['amount'] = (float) $sale->total;
            $data['type'] = 'retur';
        }
        $saleItem = null;
        if (!empty($data['sale_item_id'])) {
            $saleItem = SaleItem::where('sale_id', $sale->id)->findOrFail($data['sale_item_id']);
        }

        // KEPUTUSAN BISNIS (Continuous 124): retur/refund > Rp2.000.000
        // hanya owner/admin/superadmin. Role lain ditolak 422.
        // Guard memakai nominal efektif (void memaksa amount = total sale).
        $roleNow = $request->user()?->role ?? auth()->user()->role ?? '';
        if (
            (float) ($data['amount'] ?? 0) > 2000000
            && ! in_array($roleNow, ['superadmin', 'owner', 'admin'], true)
        ) {
            abort(422, 'Retur/refund di atas Rp2.000.000 hanya boleh diinput owner/admin/superadmin. Minta owner/admin/superadmin yang input (login dengan akun tersebut lalu ulangi transaksi).');
        }

        // KEPUTUSAN BISNIS (Continuous 167, selaras approval owner Continuous 124):
        // foto bukti WAJIB untuk retur/refund dengan nominal efektif > Rp2.000.000.
        // Guard memakai nominal efektif (void memaksa amount = total sale), tanpa migration.
        if ((float) ($data['amount'] ?? 0) > 2000000 && ! $request->hasFile('bukti')) {
            abort(422, 'Retur/refund di atas Rp2.000.000 wajib melampirkan foto bukti. Pilih file foto pada kolom bukti lalu ulangi.');
        }

        // KEPUTUSAN BISNIS (Continuous 167): TTD penerima WAJIB bila ada uang
        // kembali (amount > 0). Tukar nominal 0 dikecualikan otomatis (amount = 0
        // → tak wajib). Void 1-klik dari sales/index dikecualikan: koreksi
        // internal kasir tanpa penerima dana eksternal + form void tanpa canvas.
        // Tanpa migration: dataURL PNG canvas divalidasi magic-byte lalu disimpan
        // di signatures/return-{id}.png + path dicatat di marker [SIG:path] pada
        // reason (cermin pola [BUKTI:] & TTD cicilan PosController@store).
        $needSig = !$isVoid && (float) ($data['amount'] ?? 0) > 0;
        $sigBin = null;
        if ($needSig) {
            $sig = trim((string) ($data['signature'] ?? ''));
            if ($sig === '' || !str_starts_with($sig, 'data:image/png;base64,')) {
                return back()->withInput()->with('error', 'Retur/refund dengan uang kembali wajib disertai tanda tangan penerima (klik "Ambil Tanda Tangan").');
            }
            $sigB64 = substr($sig, strlen('data:image/png;base64,'));
            $sigBin = base64_decode($sigB64, true);
            if ($sigBin === false || strlen($sigBin) < 100 || strlen($sigBin) > 2097152 || substr($sigBin, 0, 8) !== "\x89PNG\r\n\x1a\n") {
                return back()->withInput()->with('error', 'Tanda tangan tidak valid (PNG dataURL rusak). Ulangi tanda tangan.');
            }
        }

        DB::transaction(function () use ($data, $storeId, $sale, $saleItem, $isVoid, $request, $needSig, $sigBin) {
            // Foto bukti retur opsional (tanpa migration): file ke disk public,
            // path dilacak via marker [BUKTI:path] di reason (cermin purchases.note
            // ala PurchaseController@store) + marker sama di description kas keluar
            // refund (cermin settle ConsignmentController).
            $reason = $data['reason'] ?? null;
            $buktiSuffix = null;
            if ($request->hasFile('bukti')) {
                $buktiPath = $request->file('bukti')->store('bukti-retur', 'public');
                $buktiSuffix = '[BUKTI:' . $buktiPath . ']';
                $reason = mb_substr(trim(($reason ? $reason . ' ' : '') . $buktiSuffix), 0, 1000);
            }

            $retur = SaleReturn::create([
                'sale_id' => $sale->id, 'sale_item_id' => $saleItem?->id, 'store_id' => $storeId,
                'amount' => $data['amount'], 'reason' => $reason,
                'type' => $data['type'], 'user_id' => auth()->id(),
            ]);

            // Simpan file TTD penerima (di dalam transaksi agar gagal total bila
            // disk error — cermin PosController@store) + catat path di marker
            // [SIG:...] kolom reason (TEXT, tanpa migration). Hapus file bila
            // retur dihapus: LEWATI — route returns hanya index+store, tak ada
            // destroy sehingga file yatim tak terjadi via aplikasi.
            if ($needSig && $sigBin) {
                $sigPath = 'signatures/return-' . $retur->id . '.png';
                try {
                    Storage::disk('public')->put($sigPath, $sigBin);
                } catch (\Throwable $e) {
                    throw ValidationException::withMessages([
                        'signature' => 'Gagal menyimpan tanda tangan: ' . $e->getMessage(),
                    ]);
                }
                $retur->reason = mb_substr(trim(trim((string) $retur->reason) . ' [SIG:' . $sigPath . ']'), 0, 1000);
                $retur->save();
            }

            // Kembalikan stok
            $items = $saleItem ? collect([$saleItem]) : $sale->items;
            foreach ($items as $it) {
                if ($it->product_item_id) {
                    $unit = ProductItem::find($it->product_item_id);
                    if ($unit) $unit->update(['status' => 'tersedia']);
                } else {
                    $stock = ProductStock::firstOrCreate(
                        ['store_id' => $storeId, 'product_id' => $it->product_id], ['qty' => 0]
                    );
                    $stock->increment('qty', $it->qty);
                }
                StockMovement::create([
                    'store_id' => $storeId, 'product_id' => $it->product_id,
                    'product_item_id' => $it->product_item_id, 'type' => 'retur_jual',
                    'qty' => $it->qty, 'ref_type' => 'sale_return', 'ref_id' => $retur->id,
                    'note' => 'Retur ' . $sale->invoice_no, 'user_id' => auth()->id(),
                ]);
            }

            // Kas keluar (refund ke pelanggan) + guard saldo (pola CashController@store).
            if ((float) $data['amount'] > 0) {
                $account = CashAccount::where('store_id', $storeId)->orderByDesc('is_default')->lockForUpdate()->first();
                abort_if(!$account, 422, 'Akun kas toko aktif belum ada, buat dulu di menu Kas.');
                abort_if((float) $account->balance < (float) $data['amount'], 422, 'Saldo akun kas tidak mencukupi untuk refund Rp ' . number_format((float) $data['amount'], 0, ',', '.') . '.');
                $account->decrement('balance', $data['amount']);
                $accountId = $account->id;
                $cashDesc = ($isVoid ? 'Void ' : 'Refund ') . $sale->invoice_no . ' (' . $data['type'] . ')';
                if ($buktiSuffix) {
                    $cashDesc .= ' ' . $buktiSuffix;
                }
                CashTransaction::create([
                    'store_id' => $storeId, 'cash_account_id' => $accountId, 'type' => 'keluar',
                    'category' => 'penjualan', 'amount' => $data['amount'],
                    'ref_type' => 'sale_return', 'ref_id' => $retur->id,
                    'description' => $cashDesc,
                    'user_id' => auth()->id(),
                ]);
            }

            // Tandai sale yang di-void via note (tanpa kolom/migration baru)
            if ($isVoid) {
                $sale->note = trim(($sale->note ? $sale->note . ' ' : '') . '[VOID ' . now()->format('d/m H:i') . ']');
                $sale->save();
            }
        });

        return back()->with('success', $isVoid
            ? 'Transaksi ' . $sale->invoice_no . ' di-void: stok/unit dikembalikan & kas penyesuai keluar dicatat.'
            : 'Retur/refund berhasil dicatat dan stok dikembalikan.');
    }
}
