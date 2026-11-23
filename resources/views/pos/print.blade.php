@extends('layouts.tabler')
@section('title', 'Cetak '.$sale->invoice_no)
@push('css')
<style>
  .print-toolbar-sticky{position:sticky;top:0;z-index:20}
  .print-area{background:#fff;color:#000}
  .struk-58{width:58mm;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:11px;line-height:1.45}
  .struk-80{width:80mm;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;line-height:1.45}
  #print-log{max-height:150px;overflow-y:auto;font-family:ui-monospace,monospace;font-size:12px}
  @media print{
    body *{visibility:hidden !important}
    .print-active, .print-active *{visibility:visible !important}
    .print-active{position:absolute !important;left:0;top:0;width:100% !important;margin:0 !important;padding:0 !important}
    body[data-print-paper="58mm"] .print-active{width:58mm !important;font-size:11px}
    body[data-print-paper="80mm"] .print-active{width:80mm !important}
    body[data-print-paper="A4"] .print-active{width:210mm !important}
    @page{margin:4mm}
  }
</style>
@endpush
@section('content')
@php($isGift = request()->boolean('gift'))
{{-- Ucapan kado: ambil isi marker [UCAPAN ...] (berhenti sebelum marker sistem spt [VOID ...]);
     bila tanpa marker, pakai note bersih dari semua segmen [TANDA]. --}}
@php
$__rawNote = (string) ($sale->note ?? '');
$__ucapan = '';
$__noteBersih = '';
if (preg_match('/\[UCAPAN\]\s*(.*?)(?=\s*\[[A-Z\]]|\s*$)/s', $__rawNote . ' ', $__m)) { $__ucapan = trim($__m[1]); }
if ($__ucapan === '') { $__ucapan = trim((string) preg_replace('/\s*\[[^\]]+\]/', '', $__rawNote)); }
$__noteBersih = trim((string) preg_replace('/\s*\[(?:VOID|UCAPAN|SIG)[^\]]*\]/', '', $__rawNote));
@endphp
@if($isGift)
<div class="alert alert-success d-print-none mt-2">🎁 <b>Mode Struk Hadiah</b> — semua nominal harga disembunyikan. Gunakan tombol Struk 58mm / 80mm untuk cetak via browser.</div>
@endif
<div class="page-header d-print-none">
  <div class="row align-items-center">
    <div class="col">
      <div class="page-pretitle">Pusat Cetak • Semua printer didukung</div>
      <h2 class="page-title">{{ $sale->invoice_no }} @if(!$isGift)<span class="badge bg-green-lt ms-1">Rp {{ number_format($sale->total,0,',','.') }}</span>@else<span class="badge bg-green-lt ms-1">🎁 Struk Hadiah</span>@endif</h2>
    </div>
    <div class="col-auto ms-auto d-flex gap-1">
      <a href="{{ route('pos.index') }}" class="btn btn-outline-secondary">← POS</a>
      <a href="{{ route('sales.show', $sale) }}" class="btn btn-outline-primary">Detail</a>
      @if(!$isGift)
      <a href="{{ route('pos.print', [$sale, 'gift' => 1]) }}" class="btn btn-outline-success" target="_blank" title="Buka versi tanpa harga di tab baru">🎁 Struk Hadiah</a>
      @else
      <a href="{{ route('pos.print', $sale) }}" class="btn btn-outline-secondary">← Struk Normal</a>
      @endif
    </div>
  </div>
</div>

{{-- PANEL CETAK UNIVERSAL --}}
<div class="card mt-2 d-print-none">
  <div class="card-header"><h3 class="card-title">🖨️ Pilih cara cetak</h3><span class="card-subtitle ms-2">Berfungsi di semua printer: thermal, inkjet, laser, Bluetooth, USB, WiFi</span></div>
  <div class="card-body">
    <div class="row row-cards">
      <div class="col-md-4">
        <div class="border rounded p-3 h-100">
          <div class="fw-bold mb-1">🖨️ Printer biasa / PDF</div>
          <div class="text-secondary small mb-2">Via browser — cocok untuk printer USB, jaringan, inkjet/laser, thermal + driver, dan Simpan sebagai PDF.</div>
          <div class="btn-list">
            <button class="btn btn-primary" onclick="PrintHub.browserPrint('struk-58','58mm')">Struk 58mm</button>
            <button class="btn btn-secondary" onclick="PrintHub.browserPrint('struk-80','80mm')">Struk 80mm</button>
            <a class="btn btn-outline-dark" href="{{ route('sales.invoice',$sale) }}" target="_blank">Invoice A4</a>
          </div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="border rounded p-3 h-100">
          <div class="fw-bold mb-1">📡 Thermal langsung <span id="bt-badge" class="badge bg-secondary-lt">cek…</span></div>
          <div class="text-secondary small mb-2">Bluetooth (HP & PC) / USB (PC Chrome) — tanpa driver, bahasa ESC/POS.</div>
          <div class="d-flex gap-2 mb-2">
            <select id="bt-width" class="form-select form-select-sm" style="max-width:130px"><option value="32">Kertas 58mm</option><option value="48">Kertas 80mm</option></select>
            <button class="btn btn-sm btn-info" id="btn-bt">Bluetooth</button>
            <button class="btn btn-sm btn-outline-info" id="btn-usb">USB</button>
          </div>
          <div class="d-flex gap-2 mb-2">
            <button class="btn btn-sm btn-dark" id="btn-bt-img" title="Cetak tampilan struk apa adanya sebagai gambar (lambat ±10-30 dtk, butuh kertas cukup)">📡 BT Gambar</button>
            <button class="btn btn-sm btn-outline-dark" id="btn-usb-img" title="Cetak tampilan struk apa adanya sebagai gambar via USB">🔌 USB Gambar</button>
          </div>
          <div class="text-secondary small mb-2">Mode Gambar: mencetak tampilan struk apa adanya (logo & tabel ikut). Lebih lambat ±10–30 dtk & boros kertas — pastikan kertas cukup.</div>
          <div class="d-flex gap-2 mb-2">
            <button class="btn btn-sm btn-outline-warning" id="btn-drawer-bt" title="Kirim ESC p (1B 70 00 19 FA) via Bluetooth — membuka laci kas RJ11 tanpa cetak struk">🗄️ Buka Laci (BT)</button>
            <button class="btn btn-sm btn-outline-warning" id="btn-drawer-usb" title="Kirim ESC p (1B 70 00 19 FA) via USB — membuka laci kas RJ11 tanpa cetak struk">🗄️ Buka Laci (USB)</button>
          </div>
          <div class="text-secondary small mb-2">Buka laci kas tanpa cetak struk — laci wajib tersambung ke port RJ11 printer.</div>
          <div id="print-log" class="bg-dark text-green rounded p-2 d-none"></div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="border rounded p-3 h-100">
          <div class="fw-bold mb-1">🌐 Printer WiFi/LAN & Digital</div>
          <div class="text-secondary small mb-2">Kirim langsung dari server ke printer jaringan (port 9100), atau simpan sebagai gambar.</div>
          @if(!empty($lanPrinters))
          <div class="d-flex gap-2 mb-2">
            <select id="lan-printer" class="form-select form-select-sm">
              @foreach(($lanPrinters ?? []) as $p)
              <option value="{{ $p['id'] }}" data-width="{{ $p['width'] }}" data-cols="{{ $p['width']==58?32:48 }}">{{ $p['name'] }} — {{ $p['width'] }}mm ({{ $p['ip'] }})</option>
              @endforeach
            </select>
            <button class="btn btn-sm btn-success" id="btn-lan">Cetak LAN</button>
          </div>
          <div class="text-secondary small mb-2">Otomatis dipilih sesuai lebar kertas; pilihan tersimpan di HP/kasir ini.</div>
          @endif
          <div class="btn-list">
            @forelse(($lanPrinters ?? []) as $p)
              <form method="POST" action="{{ route('printers.lan.sale', [$sale, $p['id']]) }}" class="d-inline">@csrf
                <button class="btn btn-sm btn-outline-success" title="{{ $p['ip'] }}:{{ $p['port'] }}">🌐 {{ $p['name'] }}</button>
              </form>
            @empty
              <a href="{{ route('settings.printers') }}" class="btn btn-sm btn-outline-secondary">+ Daftarkan printer WiFi</a>
            @endforelse
            <button class="btn btn-sm btn-outline-dark" id="btn-png">🖼️ PNG / Bagikan</button>
          </div>
          <form method="POST" action="{{ route('printers.wa.sale', $sale) }}" class="d-flex gap-1 mt-2" title="Kirim struk ringkas via WA">
            @csrf
            <input type="text" name="phone" class="form-control form-control-sm" placeholder="No WA cth 0812…" value="{{ $sale->customer->phone ?? '' }}" required>
            <button class="btn btn-sm btn-success text-nowrap" type="submit">📲 Kirim via WA</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="row mt-3">
  <div class="col-md-5">
    <div class="card"><div class="card-header"><h3 class="card-title">Pratinjau 58mm</h3></div><div class="card-body bg-light">
      <div id="struk-58" class="print-area struk-58 mx-auto p-2{{ $isGift ? ' gift-mode' : '' }}">
        <div style="text-align:center">@if(!empty($brandingLogo))<img src="{{ asset($brandingLogo) }}" style="max-height:60px;max-width:100%"><br>@endif<b>{{ $sale->store->name ?? 'ServiceHP' }}</b><br>@if(!empty($branding['header'])){!! nl2br(e($branding['header'])) !!}@else{{ $sale->store->address ?? '' }}<br>{{ $sale->store->phone ?? '' }}@endif<hr>
        {{ $sale->invoice_no }}<br>{{ $sale->created_at->format('d/m/Y H:i') }} — {{ $sale->user->name ?? '' }}<br>
        Pelanggan: {{ $sale->customer->name ?? 'Umum' }}@if($isGift)<br>🎁 Hadiah untuk Anda@endif<hr></div>
        @foreach($sale->items as $it)
        @if($isGift){{ $it->description }}<br>Jumlah: {{ $it->qty }}<br>@else{{ $it->description }}<br>{{ $it->qty }} x {{ number_format($it->price,0,',','.') }} = {{ number_format($it->subtotal,0,',','.') }}<br>@endif
        @endforeach
        @if($isGift && $__ucapan !== '')<hr><div style="text-align:center;border:1px dashed #000;padding:4px;margin:2px 0">🎁<br><b>{{ $__ucapan }}</b></div>@endif
        @if($isGift)<hr><div style="text-align:center"><b>🎁 HADIAH — Tanpa Harga</b><br>Semoga bermanfaat & berkah 🙏</div>@else<hr>
        Subtotal: {{ number_format($sale->subtotal,0,',','.') }}<br>
        Diskon: {{ number_format($sale->discount,0,',','.') }}<br>
        @if($sale->trade_in_value>0)Tukar tambah: -{{ number_format($sale->trade_in_value,0,',','.') }}<br>@endif
        @if((float) ($sale->tax ?? 0) > 0)
        Pajak: {{ number_format((float) $sale->tax,0,',','.') }}<br>
        @endif
        <b>TOTAL: Rp {{ number_format($sale->total,0,',','.') }}</b><br>
        Bayar ({{ strtoupper($sale->payment_method) }}): {{ number_format($sale->paid_amount,0,',','.') }}<br>
        @if($sale->paid_amount > $sale->total)
        <b>Kembalian: Rp {{ number_format($sale->paid_amount - $sale->total,0,',','.') }}</b><br>
        @endif
        Sisa ({{ strtoupper($sale->payment_status) }}): {{ number_format($sale->remaining,0,',','.') }}@endif@if(!$isGift && $__noteBersih !== '')<br>Catatan: {{ $__noteBersih }}@endif<hr>
        @if(!$isGift)<div style="text-align:center;margin-top:2px">
          <div style="font-size:10px">QR info pembayaran (bukan QRIS dinamis)</div>
          <div id="qr-pay-58" style="display:inline-block;margin:2px auto"></div>
          <div id="qr-pay-58-text" style="font-size:10px;word-break:break-all">SERVICEHP|{{ $sale->invoice_no }}|{{ (int)$sale->total }}</div>
        </div><hr>@endif
        <div style="text-align:center">@if(!empty($branding['footer'])){!! nl2br(e($branding['footer'])) !!}<br>@else Terima kasih<br>Garansi & retur maks 7 hari dengan struk ini<br>@endif Lacak service: {{ url('/track') }}</div>
      </div>
    </div></div>
  </div>
  <div class="col-md-7">
    <div class="card"><div class="card-header"><h3 class="card-title">Pratinjau 80mm</h3></div><div class="card-body bg-light">
      <div id="struk-80" class="print-area struk-80 mx-auto p-2{{ $isGift ? ' gift-mode' : '' }}">
        <div style="text-align:center">@if(!empty($brandingLogo))<img src="{{ asset($brandingLogo) }}" style="max-height:70px;max-width:100%"><br>@endif<h4 style="margin:0">{{ $sale->store->name ?? 'ServiceHP' }}</h4>@if(!empty($branding['header'])){!! nl2br(e($branding['header'])) !!}@else{{ $sale->store->address ?? '' }} — {{ $sale->store->phone ?? '' }}@endif<hr></div>
        <table style="width:100%"><tr><td>No: <b>{{ $sale->invoice_no }}</b></td><td style="text-align:right">{{ $sale->created_at->format('d/m/Y H:i') }}</td></tr>
        <tr><td>Pelanggan: {{ $sale->customer->name ?? 'Umum' }}</td><td style="text-align:right">Kasir: {{ $sale->user->name ?? '' }}</td></tr></table>@if($isGift)<div style="text-align:center">🎁 Hadiah untuk Anda</div>@endif<hr>
        <table style="width:100%">
        @foreach($sale->items as $it)<tr><td>{{ $it->description }}<br><small>@if($isGift)Jumlah: {{ $it->qty }}@else{{ $it->qty }} x {{ number_format($it->price,0,',','.') }}@endif</small></td>@if(!$isGift)<td style="text-align:right">{{ number_format($it->subtotal,0,',','.') }}</td>@endif</tr>@endforeach
        </table><hr>
        @if($isGift)
        @if($__ucapan !== '')<div style="text-align:center;border:2px dashed #000;padding:8px;margin:4px 0;font-size:15px">🎁<br><b>{{ $__ucapan }}</b></div><hr>@endif
        <div style="text-align:center"><b>🎁 HADIAH — Tanpa Harga</b><br><small>Semoga bermanfaat & berkah 🙏</small></div><hr>
        @else
        <table style="width:100%">
          <tr><td>Subtotal</td><td style="text-align:right">{{ number_format($sale->subtotal,0,',','.') }}</td></tr>
          <tr><td>Diskon{{ $sale->promo ? ' ('.$sale->promo->code.')' : '' }}</td><td style="text-align:right">{{ number_format($sale->discount,0,',','.') }}</td></tr>
          @if($sale->trade_in_value>0)<tr><td>Tukar tambah</td><td style="text-align:right">-{{ number_format($sale->trade_in_value,0,',','.') }}</td></tr>@endif
          @if((float) ($sale->tax ?? 0) > 0)
          <tr><td>Pajak</td><td style="text-align:right">{{ number_format((float) $sale->tax,0,',','.') }}</td></tr>
          @endif
          <tr><td><b>TOTAL</b></td><td style="text-align:right"><b>Rp {{ number_format($sale->total,0,',','.') }}</b></td></tr>
          <tr><td>Bayar ({{ strtoupper($sale->payment_method) }})</td><td style="text-align:right">{{ number_format($sale->paid_amount,0,',','.') }}</td></tr>
          @if($sale->paid_amount > $sale->total)
          <tr><td><b>Kembalian</b></td><td style="text-align:right"><b>Rp {{ number_format($sale->paid_amount - $sale->total,0,',','.') }}</b></td></tr>
          @endif
          <tr><td>Sisa ({{ strtoupper($sale->payment_status) }})</td><td style="text-align:right">{{ number_format($sale->remaining,0,',','.') }}</td></tr>
          @if($__noteBersih !== '')<tr><td>Catatan</td><td style="text-align:right">{{ $__noteBersih }}</td></tr>@endif
        </table><hr>
        <div style="text-align:center;margin-top:2px">
          <div><small>QR info pembayaran (bukan QRIS dinamis)</small></div>
          <div id="qr-pay-80" style="display:inline-block;margin:2px auto"></div>
          <div><small id="qr-pay-80-text" style="word-break:break-all">SERVICEHP|{{ $sale->invoice_no }}|{{ (int)$sale->total }}</small></div>
        </div><hr>
        @endif
        <p style="text-align:center">@if(!empty($branding['footer'])){!! nl2br(e($branding['footer'])) !!}<br>@else Terima kasih atas kunjungan Anda<br>@endif <small>{{ $sale->invoice_no }} • Lacak service: {{ url('/track') }}</small></p>
      </div>
    </div></div>
  </div>
</div>

<script id="receipt-data" type="application/json">@json(\App\Http\Controllers\PrinterController::saleData($sale))</script>
{{-- Laci otomatis hanya untuk bayar tunai: metode dominan TUNAI atau ada leg split tunai. --}}
@php($hasCash = strtoupper($sale->payment_method ?? '') === 'TUNAI' || ($sale->payments ?? collect())->contains(fn($p) => strtolower($p->method ?? '') === 'tunai'))
@endsection
@push('js')
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="{{ asset('js/printer.js') }}"></script>
<script>
(function(){
  // QR info pembayaran (bukan QRIS dinamis): payload teks SERVICEHP|invoice|total.
  // Bila CDN qrcodejs gagal, teks payload di bawah QR tetap tampil sebagai fallback.
  function renderPayQr(elId, text) {
    var el = document.getElementById(elId);
    if (!el) return;
    try {
      if (typeof QRCode !== 'undefined') { new QRCode(el, { text: text, width: 120, height: 120, correctLevel: QRCode.CorrectLevel.M }); return; }
    } catch (e) {}
    el.innerHTML = '';
    el.style.display = 'none';
  }
  const logBox = document.getElementById('print-log');
  const log = m => { logBox.classList.remove('d-none'); logBox.innerHTML += '<div>> '+m+'</div>'; logBox.scrollTop = 1e6; };
  document.getElementById('bt-badge').textContent = PrintHub.supported.bluetooth ? 'didukung' : 'tidak didukung';
  document.getElementById('bt-badge').className = 'badge bg-' + (PrintHub.supported.bluetooth ? 'green' : 'red') + '-lt';
  const cols = () => parseInt(document.getElementById('bt-width').value, 10);
  const widthMm = () => cols() === 32 ? 58 : 80;
  const data = JSON.parse(document.getElementById('receipt-data').textContent);
  const payQrText = data.pay_qr || ('SERVICEHP|' + (data.invoice || '') + '|' + Math.round(Number(data.total) || 0));
  renderPayQr('qr-pay-58', payQrText);
  renderPayQr('qr-pay-80', payQrText);
  const lanSel = document.getElementById('lan-printer');
  const syncLan = () => { if (lanSel) PrintHub.lanAutoPick(lanSel, widthMm()); };
  document.getElementById('bt-width').addEventListener('change', syncLan);
  if (lanSel) {
    syncLan();
    lanSel.addEventListener('change', () => PrintHub.lanSetDefault(widthMm(), lanSel.value));
    document.getElementById('btn-lan').onclick = () => {
      if (!lanSel.value) { alert('Pilih printer WiFi/LAN dulu.'); return; }
      PrintHub.lanSetDefault(widthMm(), lanSel.value);
      const f = document.createElement('form');
      f.method = 'POST';
      f.action = '{{ url('/printers/lan/sale') }}/{{ $sale->id }}/' + encodeURIComponent(lanSel.value);
      const t = document.createElement('input'); t.type = 'hidden'; t.name = '_token'; t.value = '{{ csrf_token() }}';
      f.appendChild(t); document.body.appendChild(f); f.submit();
    };
  }
  document.getElementById('btn-bt').onclick = async () => {
    logBox.innerHTML = '';
    try { await PrintHub.sendBluetooth(PrintHub.saleBytes(data, cols()), log); }
    catch(e){ log('GAGAL: ' + e.message); alert(e.message); }
  };
  document.getElementById('btn-usb').onclick = async () => {
    logBox.innerHTML = '';
    try { await PrintHub.sendUSB(PrintHub.saleBytes(data, cols()), log); }
    catch(e){ log('GAGAL: ' + e.message); alert(e.message); }
  };
  // Mode gambar: render pratinjau sesuai lebar kertas (58→struk-58/384px, 80→struk-80/576px).
  const imgArea = () => (cols() === 32 ? 'struk-58' : 'struk-80');
  const imgWidth = () => (cols() === 32 ? 384 : 576);
  document.getElementById('btn-bt-img').onclick = async () => {
    logBox.innerHTML = '';
    try { await PrintHub.printImage(imgArea(), 'bluetooth', imgWidth(), log); }
    catch(e){ log('GAGAL: ' + e.message); alert(e.message); }
  };
  document.getElementById('btn-usb-img').onclick = async () => {
    logBox.innerHTML = '';
    try { await PrintHub.printImage(imgArea(), 'usb', imgWidth(), log); }
    catch(e){ log('GAGAL: ' + e.message); alert(e.message); }
  };
  // Buka laci kas (ESC p m t1 t2 = 1B 70 00 19 FA) — tanpa cetak struk, tanpa backend.
  document.getElementById('btn-drawer-bt').onclick = async () => {
    logBox.innerHTML = '';
    try { await PrintHub.openDrawer('bluetooth', 0, log); }
    catch(e){ log('GAGAL: ' + e.message); alert(e.message); }
  };
  document.getElementById('btn-drawer-usb').onclick = async () => {
    logBox.innerHTML = '';
    try { await PrintHub.openDrawer('usb', 0, log); }
    catch(e){ log('GAGAL: ' + e.message); alert(e.message); }
  };
  document.getElementById('btn-png').onclick = async () => {
    try { await PrintHub.downloadPNG('struk-80', '{{ $sale->invoice_no }}.png'); }
    catch(e){ alert(e.message); }
  };
  @if(!empty($branding['auto_print']))
  // Cetak otomatis sekali saat halaman dibuka (setting per toko). Tombol manual tetap tersedia.
  if (!window.__autoPrinted) {
    window.__autoPrinted = true;
    setTimeout(() => { try { PrintHub.browserPrint('struk-80', '80mm'); } catch (e) {} }, 600);
  }
  @endif
  @if(!empty($branding['auto_drawer']) && $hasCash)
  // Laci otomatis sekali setelah bayar tunai (setting per toko): drawer-kick 5 byte
  // via transport BT/USB yang TERAKHIR dipakai sesi ini. Tanpa popup pairing —
  // bila device belum pernah connect / izin hilang, dilewati diam-diam (log saja).
  // Delay 1,5 dtk agar tidak berebut radio BT dengan tombol manual & dialog cetak.
  if (!window.__autoDrawerKicked) {
    window.__autoDrawerKicked = true;
    setTimeout(async () => {
      try {
        const r = await PrintHub.openDrawerSilent(log);
        if (r && r.ok) log('Laci kas terbuka otomatis (' + r.transport + ').');
      } catch (e) {}
    }, 1500);
  }
  @endif
})();
</script>
@endpush
