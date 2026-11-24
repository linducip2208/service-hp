<!doctype html><html lang="id"><head><meta charset="utf-8"><title>Invoice {{ $sale->invoice_no }} — ServiceHP</title>
<link href="/vendor/tabler/tabler.min.css" rel="stylesheet">
<style>
  .invoice-box{max-width:210mm;margin:auto;background:#fff;color:#000}
  @media print{ .no-print{display:none!important} body{background:#fff} .invoice-box{max-width:100%} @page{size:A4;margin:12mm} }
</style></head>
<body class="bg-light">
<div class="invoice-box p-4 p-print-0 shadow-sm my-3">
  <div class="d-flex justify-content-between align-items-start border-bottom pb-3 mb-3">
    <div class="d-flex gap-3 align-items-center"><div>@if(!empty($brandingLogo))<img src="{{ asset($brandingLogo) }}" style="max-height:64px" alt="Logo">@endif</div><div><h2 class="mb-0">@if(empty($brandingLogo))📱 @endif{{ $sale->store->name ?? 'ServiceHP' }}</h2>
      <div class="text-secondary">@if(!empty($branding['header'])){!! nl2br(e($branding['header'])) !!}@else{{ $sale->store->address ?? '' }}<br>{{ $sale->store->phone ?? '' }}@endif</div></div></div>
    <div class="text-end"><h1 class="mb-0">INVOICE</h1><b>{{ $sale->invoice_no }}</b><br>
      <span class="text-secondary">{{ $sale->created_at->format('d F Y, H:i') }}</span></div>
  </div>
  <div class="row mb-3">
    <div class="col-6"><b>Ditagihkan kepada:</b><br>{{ $sale->customer->name ?? 'Pelanggan Umum' }}<br><span class="text-secondary">{{ $sale->customer->phone ?? '' }} {{ $sale->customer->address ?? '' }}</span></div>
    <div class="col-6 text-end"><b>Kasir:</b> {{ $sale->user->name ?? '-' }}<br><b>Pembayaran:</b> {{ strtoupper($sale->payment_method) }} ({{ strtoupper($sale->payment_status) }})</div>
  </div>
  <table class="table table-bordered"><thead><tr><th>#</th><th>Deskripsi</th><th class="text-end">Qty</th><th class="text-end">Harga</th><th class="text-end">Subtotal</th></tr></thead><tbody>
  @foreach($sale->items as $i => $it)<tr><td>{{ $i+1 }}</td><td>{{ $it->description }}</td><td class="text-end">{{ $it->qty }}</td><td class="text-end">{{ number_format($it->price,0,',','.') }}</td><td class="text-end">{{ number_format($it->subtotal,0,',','.') }}</td></tr>@endforeach
  </tbody><tfoot>
    <tr><td colspan="4" class="text-end">Subtotal</td><td class="text-end">{{ number_format($sale->subtotal,0,',','.') }}</td></tr>
    <tr><td colspan="4" class="text-end">Diskon{{ $sale->promo ? ' ('.$sale->promo->code.')' : '' }}</td><td class="text-end">{{ number_format($sale->discount,0,',','.') }}</td></tr>
    @if($sale->trade_in_value>0)<tr><td colspan="4" class="text-end">Tukar tambah</td><td class="text-end">-{{ number_format($sale->trade_in_value,0,',','.') }}</td></tr>@endif
    @if((float) ($sale->tax ?? 0) > 0)
    <tr><td colspan="4" class="text-end">Pajak</td><td class="text-end">{{ number_format((float) $sale->tax,0,',','.') }}</td></tr>
    @endif
    <tr class="table-light"><td colspan="4" class="text-end"><b>TOTAL</b></td><td class="text-end"><b>Rp {{ number_format($sale->total,0,',','.') }}</b></td></tr>
    <tr><td colspan="4" class="text-end">Dibayar</td><td class="text-end">{{ number_format($sale->paid_amount,0,',','.') }}</td></tr>
    <tr><td colspan="4" class="text-end">Sisa</td><td class="text-end">{{ number_format($sale->remaining,0,',','.') }}</td></tr>
  </tfoot></table>
  <div class="row mt-4"><div class="col-6 text-secondary small">@if(!empty($branding['footer'])){!! nl2br(e($branding['footer'])) !!}@else Syarat: garansi toko & retur maks 7 hari dengan invoice asli.@endif<br>Lacak service: {{ url('/track') }}
    <div class="mt-3 text-center" style="max-width:180px">
      <div class="small">QR info pembayaran (bukan QRIS dinamis)</div>
      <div id="qr-pay-a4" class="d-inline-block my-1"></div>
      <div class="small font-monospace" id="qr-pay-a4-text" style="word-break:break-all">SERVICEHP|{{ $sale->invoice_no }}|{{ (int)$sale->total }}</div>
    </div></div>
  <div class="col-6 text-end"><br><br><br>___________________<br><b>{{ $sale->store->name ?? 'ServiceHP' }}</b></div></div>
  <div class="text-center no-print mt-4 d-flex gap-2 justify-content-center">
    <button class="btn btn-primary" onclick="window.print()">🖨️ Cetak / Simpan PDF</button>
    <a class="btn btn-outline-secondary" href="{{ route('pos.print', $sale) }}">Kembali ke struk</a>
  </div>
</div>
<script src="/vendor/qrcode/qrcode.min.js"></script>
<script>
// QR info pembayaran (bukan QRIS dinamis): payload teks SERVICEHP|invoice|total.
// Bila CDN qrcodejs gagal, teks payload tetap tampil sebagai fallback.
(function(){
  var el = document.getElementById('qr-pay-a4');
  var txt = document.getElementById('qr-pay-a4-text');
  var payload = txt ? txt.textContent.trim() : '';
  if (!el || !payload) return;
  try {
    if (typeof QRCode !== 'undefined') { new QRCode(el, { text: payload, width: 140, height: 140, correctLevel: QRCode.CorrectLevel.M }); return; }
  } catch (e) {}
  el.innerHTML = '';
  el.style.display = 'none';
})();
</script>
</body></html>
