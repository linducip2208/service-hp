<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\ServiceTicket;
use App\Services\EscPosService;
use App\Services\WaService;
use Illuminate\Http\Request;

class PrinterController extends Controller
{
    private function canManage(): bool
    {
        return in_array(auth()->user()->role ?? '', ['superadmin', 'owner', 'admin']);
    }

    /** Toko aktif untuk scope cetak (kasir tetap boleh cetak struk tokonya sendiri). */
    private function activeStoreId(): ?int
    {
        $id = session('active_store_id') ?? auth()->user()->active_store_id ?? null;
        return $id ? (int) $id : null;
    }

    private function authorizeStoreData(int $storeId): void
    {
        $active = $this->activeStoreId();
        if ($active && $storeId !== $active) {
            abort(403, 'Bukan data toko aktif.');
        }
    }

    // ---------- Kelola printer LAN/WiFi ----------
    public function index(Request $request)
    {
        abort_unless($this->canManage(), 403);
        $printers = EscPosService::all();
        $stores = \App\Models\Store::orderBy('name')->get();
        $activeStoreId = (int) ($request->get('store') ?: session('active_store_id') ?: $stores->first()?->id);
        $branding = EscPosService::branding($activeStoreId);
        $logoUrl = EscPosService::logoUrl($activeStoreId);
        return view('settings.printers', compact('printers', 'stores', 'activeStoreId', 'branding', 'logoUrl'));
    }

    public function store(Request $request)
    {
        abort_unless($this->canManage(), 403);
        $request->validate([
            'name' => 'required|max:60',
            'ip' => 'required|ip',
            'port' => 'nullable|integer|min:1|max:65535',
            'width' => 'required|in:58,80',
        ]);
        $list = EscPosService::all();
        $list[] = [
            'id' => 'PRN-' . strtoupper(\Str::random(5)),
            'name' => $request->name,
            'ip' => $request->ip,
            'port' => (int) ($request->port ?: 9100),
            'width' => (int) $request->width,
            'is_default' => empty($list),
        ];
        EscPosService::save($list);
        return back()->with('success', 'Printer LAN/WiFi ditambahkan. Gunakan tombol "Test" untuk memastikan koneksi.');
    }

    public function setDefault(string $id)
    {
        abort_unless($this->canManage(), 403);
        $list = collect(EscPosService::all())->map(fn($p) => array_merge($p, ['is_default' => $p['id'] === $id]))->all();
        EscPosService::save($list);
        return back()->with('success', 'Printer default diperbarui.');
    }

    public function destroy(string $id)
    {
        abort_unless($this->canManage(), 403);
        EscPosService::save(array_filter(EscPosService::all(), fn($p) => $p['id'] !== $id));
        return back()->with('success', 'Printer dihapus.');
    }

    // ---------- Branding struk per toko (logo + header/footer) ----------
    public function brandingSave(Request $request)
    {
        abort_unless($this->canManage(), 403);
        $data = $request->validate([
            'store_id' => 'required|exists:stores,id',
            'header_text' => 'nullable|string|max:500',
            'footer_text' => 'nullable|string|max:1000',
            'logo' => 'nullable|image|mimes:png,jpg,jpeg|max:1024',
            'remove_logo' => 'nullable|boolean',
            'auto_print' => 'nullable|boolean',
            'auto_drawer' => 'nullable|boolean',
        ]);
        $storeId = (int) $data['store_id'];
        $logoRel = EscPosService::branding($storeId)['logo'];
        $logoPath = storage_path('app/public/logo/' . $storeId . '.png');

        if ($request->boolean('remove_logo')) {
            @unlink($logoPath);
            $logoRel = null;
        } elseif ($request->hasFile('logo')) {
            if (!is_dir(dirname($logoPath))) mkdir(dirname($logoPath), 0755, true);
            // Simpan sebagai PNG per toko; dipakai di tampilan browser/PDF + raster logo ESC/POS (GS v 0) di struk LAN.
            $request->file('logo')->move(dirname($logoPath), $storeId . '.png');
            $logoRel = 'logo/' . $storeId . '.png';
        }

        EscPosService::saveBranding($storeId, [
            'header' => $data['header_text'] ?? '',
            'footer' => $data['footer_text'] ?? '',
            'logo' => $logoRel,
            'auto_print' => $request->boolean('auto_print'),
            'auto_drawer' => $request->boolean('auto_drawer'),
        ]);
        return redirect()->route('settings.printers', ['store' => $storeId])->with('success', 'Branding struk toko diperbarui. Berlaku di struk 58/80, invoice A4 & tiket service.');
    }

    public function test(string $id)
    {
        abort_unless($this->canManage(), 403);
        $p = collect(EscPosService::all())->firstWhere('id', $id);
        abort_unless($p, 404);
        $cols = $p['width'] == 58 ? 32 : 48;

        // Logo branding toko aktif (fallback toko pertama) — bila ada ikut sebagai raster GS v 0.
        $storeId = $this->activeStoreId() ?? \App\Models\Store::orderBy('id')->value('id');
        $logoAbs = EscPosService::logoAbs($storeId);
        $gd = EscPosService::gdAvailable();
        $raster = $logoAbs ? EscPosService::rasterFile($logoAbs, EscPosService::maxRasterWidth($cols)) : '';
        $logoOk = $raster !== '';
        if ($logoOk) {
            $logoNote = 'Logo ikut (' . basename((string) $logoAbs) . ', ' . strlen($raster) . ' byte raster)';
        } else {
            $logoWhy = !$storeId ? 'tanpa toko aktif'
                : (!$logoAbs ? 'belum ada logo branding toko (storage/app/public/logo/' . $storeId . '.png)'
                : (!$gd ? 'ekstensi GD tidak aktif'
                : 'file logo tidak valid/nyaris kosong'));
            $logoNote = 'Logo tidak ikut (' . $logoWhy . ')';
        }

        // QR sampel: payload teks info printer+timestamp (BUKAN QRIS dinamis).
        $qrPayload = 'SERVICEHP|TEST|' . ($p['name'] ?? $p['id']) . '|' . now()->format('Ymd-His');
        $qrNote = 'QR ok (Model 2, EC-M)';

        // Susunan struk test: teks + logo (bila ada) + QR sampel + garis + potong.
        $e = new EscPosService($cols);
        if ($logoOk) $e->image($logoAbs);
        $e->align('center')->bold()->text($p['name'])->bold(false)
          ->text('Test print ServiceHP')
          ->text(now()->format('d/m/Y H:i'))
          ->text(($p['ip'] ?? '-') . ':' . ($p['port'] ?? 9100) . ' - ' . ($p['width'] ?? '-') . 'mm (' . $cols . ' kol)')
          ->line()
          ->align('center')->text($logoNote)
          ->qr($qrPayload)
          ->text($qrPayload)
          ->line()
          ->bold()->text('Printer OK')->bold(false)
          ->cut();

        $bytes = $e->bytes();
        $res = EscPosService::sendLan($p['ip'], $p['port'], $bytes);
        $msg = $res['msg'] . ' | Struk ' . strlen($bytes) . ' byte | ' . $logoNote . ' | ' . $qrNote;
        return back()->with($res['ok'] ? 'success' : 'error', $msg);
    }

    /** Uji buka laci kas: kirim 5 byte ESC p (1B 70 00 19 FA) via sendLan existing. */
    public function testDrawer(string $id)
    {
        abort_unless($this->canManage(), 403);
        $p = collect(EscPosService::all())->firstWhere('id', $id);
        abort_unless($p, 404);
        $bytes = EscPosService::cashDrawer();
        $res = EscPosService::sendLan($p['ip'], $p['port'], $bytes);
        $msg = $res['msg'] . ' | Pulsa laci ESC p 5 byte (1B 70 00 19 FA) ke ' . ($p['name'] ?? $id)
            . ($res['ok'] ? ' — laci seharusnya terbuka.' : ' — laci tidak merespons, cek kabel RJ11 laci ke printer & daya printer.');
        return back()->with($res['ok'] ? 'success' : 'error', $msg);
    }

    // ---------- Cetak struk ke printer LAN ----------
    public function sendSale(Sale $sale, string $id)
    {
        $this->authorizeStoreData((int) $sale->store_id);
        $p = collect(EscPosService::all())->firstWhere('id', $id);
        abort_unless($p, 404, 'Printer tidak ditemukan.');
        $sale->load(['items', 'customer', 'store', 'user']);
        $cols = $p['width'] == 58 ? 32 : 48;
        $res = EscPosService::sendLan($p['ip'], $p['port'], EscPosService::saleReceipt($sale, $cols));
        return back()->with($res['ok'] ? 'success' : 'error', $res['msg']);
    }

    public function sendService(ServiceTicket $service, string $id)
    {
        $this->authorizeStoreData((int) $service->store_id);
        $p = collect(EscPosService::all())->firstWhere('id', $id);
        abort_unless($p, 404, 'Printer tidak ditemukan.');
        $service->load(['customer', 'store']);
        $cols = $p['width'] == 58 ? 32 : 48;
        $res = EscPosService::sendLan($p['ip'], $p['port'], EscPosService::serviceReceipt($service, $cols));
        return back()->with($res['ok'] ? 'success' : 'error', $res['msg']);
    }

    // ---------- Kirim struk ringkas via WA (dari halaman cetak) ----------
    public function sendWaSale(Request $request, Sale $sale)
    {
        $this->authorizeStoreData((int) $sale->store_id);
        $request->validate(['phone' => 'required|string|max:20'], [
            'phone.required' => 'Nomor WA wajib diisi.',
        ]);
        $phone = WaService::normalize((string) $request->input('phone'));
        if (strlen($phone) < 9 || strlen($phone) > 15) {
            return back()->with('error', 'Nomor WA tidak valid.');
        }
        $sale->load(['items', 'customer', 'store', 'user']);
        $msg = self::saleWaMessage($sale);
        $ok = WaService::send($phone, $msg, 'struk_sale', $sale->store_id);
        return back()->with($ok ? 'success' : 'error', $ok
            ? 'Struk ' . $sale->invoice_no . ' terkirim via WA ke ' . $phone . '.'
            : 'Gagal kirim WA ke ' . $phone . '. Cek pengaturan gateway WA.');
    }

    public function sendWaService(Request $request, ServiceTicket $service)
    {
        $this->authorizeStoreData((int) $service->store_id);
        $request->validate(['phone' => 'required|string|max:20'], [
            'phone.required' => 'Nomor WA wajib diisi.',
        ]);
        $phone = WaService::normalize((string) $request->input('phone'));
        if (strlen($phone) < 9 || strlen($phone) > 15) {
            return back()->with('error', 'Nomor WA tidak valid.');
        }
        $service->load(['customer', 'store']);
        $msg = $this->serviceWaMessage($service);
        $ok = WaService::send($phone, $msg, 'struk_service', $service->store_id);
        return back()->with($ok ? 'success' : 'error', $ok
            ? 'Tanda terima ' . $service->ticket_no . ' terkirim via WA ke ' . $phone . '.'
            : 'Gagal kirim WA ke ' . $phone . '. Cek pengaturan gateway WA.');
    }

    private static function rp(float $n): string
    {
        return 'Rp ' . number_format($n, 0, ',', '.');
    }

    /** Ambil ucapan kado dari sales.note (cermin logika pos/print.blade.php). */
    public static function extractUcapan(?string $note): string
    {
        $raw = (string) ($note ?? '');
        if (preg_match('/\[UCAPAN\]\s*(.*?)(?=\s*\[[A-Z\]]|\s*$)/s', $raw . ' ', $m)) {
            $u = trim($m[1]);
            if ($u !== '') return $u;
        }
        return trim((string) preg_replace('/\s*\[[^\]]+\]/', '', $raw));
    }

    /** Struk penjualan ringkas untuk WA — dipakai tombol manual & kirim otomatis POS (public agar bisa dipakai PosController). */
    public static function saleWaMessage(Sale $sale): string
    {
        $store = $sale->store->name ?? 'ServiceHP';
        $lines = [];
        $lines[] = '*' . $store . '*';
        $lines[] = 'Struk penjualan *' . $sale->invoice_no . '*';
        $lines[] = ($sale->created_at?->format('d/m/Y H:i') ?? '') . ($sale->user->name ?? '' ? ' • Kasir: ' . $sale->user->name : '');
        $lines[] = 'Pelanggan: ' . ($sale->customer->name ?? 'Umum');
        $lines[] = '----------------';
        $items = $sale->items->take(15);
        foreach ($items as $it) {
            $lines[] = '• ' . $it->description . ' x' . rtrim(rtrim(number_format((float) $it->qty, 2, ',', '.'), '0'), ',')
                . ' = ' . self::rp((float) $it->subtotal);
        }
        if ($sale->items->count() > 15) {
            $lines[] = '… +' . ($sale->items->count() - 15) . ' item lainnya';
        }
        $lines[] = '----------------';
        $lines[] = 'Subtotal: ' . self::rp((float) $sale->subtotal);
        if ((float) $sale->discount > 0) $lines[] = 'Diskon: -' . self::rp((float) $sale->discount);
        if ((float) $sale->trade_in_value > 0) $lines[] = 'Tukar tambah: -' . self::rp((float) $sale->trade_in_value);
        if ((float) ($sale->tax ?? 0) > 0) {
            $lines[] = 'Pajak: ' . self::rp((float) $sale->tax);
        }
        $lines[] = '*TOTAL: ' . self::rp((float) $sale->total) . '*';
        $lines[] = 'Bayar (' . strtoupper($sale->payment_method ?? '-') . '): ' . self::rp((float) $sale->paid_amount);
        $change = (float) $sale->paid_amount - (float) $sale->total;
        if ($change > 0) {
            $lines[] = 'Kembalian: ' . self::rp($change);
        }
        $lines[] = 'Sisa (' . strtoupper($sale->payment_status ?? '-') . '): ' . self::rp((float) $sale->remaining);
        $ucapan = self::extractUcapan($sale->note);
        if ($ucapan !== '') $lines[] = '🎁 ' . $ucapan;
        $lines[] = 'Lacak service: ' . url('/track');
        $lines[] = 'Terima kasih 🙏';
        return implode("\n", $lines);
    }

    private function serviceWaMessage(ServiceTicket $service): string
    {
        $store = $service->store->name ?? 'ServiceHP';
        $nama = $service->customer->name ?? 'Pelanggan';
        $device = trim(($service->product_brand ?? '') . ' ' . ($service->product_type ?? ''));
        $track = url('/track') . '?no=' . $service->ticket_no;
        $lines = [];
        $lines[] = '*' . $store . '* — Tanda Terima Service';
        $lines[] = 'Halo ' . $nama . ',';
        $lines[] = 'Tiket: *' . $service->ticket_no . '*';
        $lines[] = 'Tanggal: ' . ($service->created_at?->format('d/m/Y H:i') ?? '');
        if ($device !== '') $lines[] = 'Device: ' . $device;
        if ($service->imei) $lines[] = 'IMEI: ' . $service->imei;
        $lines[] = 'Keluhan: ' . ($service->complaint ?? '-');
        if ($service->condition_note) $lines[] = 'Kondisi: ' . $service->condition_note;
        if ((float) $service->estimate_cost > 0) $lines[] = 'Estimasi: ' . self::rp((float) $service->estimate_cost);
        if ((float) $service->dp > 0) $lines[] = 'DP masuk: ' . self::rp((float) $service->dp);
        $lines[] = 'Status: *' . strtoupper($service->status ?? '-') . '*';
        $lines[] = 'Lacak: ' . $track;
        $lines[] = 'Tunjukkan tiket ini saat pengambilan 🙏';
        return implode("\n", $lines);
    }

    // ---------- Data struk JSON (untuk Bluetooth/USB via JS) ----------
    public function receiptSale(Sale $sale)
    {
        $this->authorizeStoreData((int) $sale->store_id);
        $sale->load(['items', 'customer', 'store', 'user']);
        return response()->json($this->saleData($sale));
    }

    public function receiptService(ServiceTicket $service)
    {
        $this->authorizeStoreData((int) $service->store_id);
        $service->load(['customer', 'store']);
        $b = EscPosService::branding($service->store_id);
        return response()->json([
            'type' => 'service',
            'store' => ['name' => $service->store->name ?? 'ServiceHP', 'phone' => $service->store->phone ?? ''],
            'header_lines' => EscPosService::lines($b['header']),
            'footer_lines' => EscPosService::lines($b['footer']),
            'ticket' => $service->ticket_no,
            'datetime' => $service->created_at->format('d/m/Y H:i'),
            'customer' => trim(($service->customer->name ?? '-') . ' ' . ($service->customer->phone ?? '')),
            'device' => trim(($service->product_brand ?? '') . ' ' . ($service->product_type ?? '')),
            'imei' => $service->imei,
            'complaint' => $service->complaint,
            'condition' => $service->condition_note,
            'estimate' => (float) $service->estimate_cost,
            'dp' => (float) $service->dp,
            'status' => $service->status,
            'track_url' => url('/track') . '?no=' . $service->ticket_no,
        ]);
    }

    public static function saleData(Sale $sale): array
    {
        $b = EscPosService::branding($sale->store_id);
        $change = max(0, (float) $sale->paid_amount - (float) $sale->total);
        return [
            'type' => 'sale',
            'store' => [
                'name' => $sale->store->name ?? 'ServiceHP',
                'address' => $sale->store->address ?? '',
                'phone' => $sale->store->phone ?? '',
            ],
            'header_lines' => EscPosService::lines($b['header']),
            'footer_lines' => EscPosService::lines($b['footer']),
            'invoice' => $sale->invoice_no,
            'datetime' => $sale->created_at->format('d/m/Y H:i'),
            'cashier' => $sale->user->name ?? '-',
            'customer' => $sale->customer->name ?? 'Umum',
            'items' => $sale->items->map(fn($it) => [
                'name' => $it->description,
                'qty' => (float) $it->qty,
                'price' => (float) $it->price,
                'subtotal' => (float) $it->subtotal,
            ])->all(),
            'subtotal' => (float) $sale->subtotal,
            'discount' => (float) $sale->discount,
            'tradein' => (float) $sale->trade_in_value,
            'tax' => (float) ($sale->tax ?? 0),
            'total' => (float) $sale->total,
            'pay_method' => strtoupper($sale->payment_method),
            'pay_status' => strtoupper($sale->payment_status),
            'paid' => (float) $sale->paid_amount,
            'remaining' => (float) $sale->remaining,
            // Kembalian tunai lebih bayar (turunan, tanpa kolom baru): tampil bila > 0.
            'change' => $change,
            // QR info pembayaran (BUKAN QRIS dinamis): payload teks invoice+total.
            'pay_qr' => 'SERVICEHP|' . $sale->invoice_no . '|' . (int) $sale->total,
            'ucapan' => self::extractUcapan($sale->note),
        ];
    }
}
