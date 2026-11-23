<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Tanda Terima {{ $service->ticket_no }} — ServiceHP</title>
<link href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<style>
  .ticket-box{max-width:480px;margin:auto;background:#fff;color:#000}
  @media print{
    body *{visibility:hidden !important}
    #ticket-area, #ticket-area *{visibility:visible !important}
    #ticket-area{position:absolute;left:0;top:0;width:100% !important;max-width:80mm;margin:0}
    body[data-print-paper="A5"] #ticket-area{max-width:148mm}
    @page{margin:4mm}
    .no-print{display:none!important}
  }
  #print-log{max-height:120px;overflow-y:auto;font-family:monospace;font-size:12px}
</style></head>
<body class="bg-light">
<div class="container py-3">
  {{-- PANEL CETAK --}}
  <div class="card mb-3 no-print"><div class="card-header"><h3 class="card-title">🖨️ Cetak tanda terima</h3><span class="card-subtitle ms-2">{{ $service->ticket_no }}</span></div>
  <div class="card-body">
    <div class="btn-list mb-2">
      <button class="btn btn-primary btn-sm" onclick="doPrint('80mm')">Struk 80mm</button>
      <button class="btn btn-secondary btn-sm" onclick="doPrint('A5')">Kertas A5</button>
      <a class="btn btn-outline-dark btn-sm" href="{{ route('services.show',$service) }}">Kembali</a>
    </div>
    <div class="row g-2 align-items-end">
      <div class="col-md-3"><label class="form-label">Lebar thermal</label><select id="bt-width" class="form-select form-select-sm"><option value="32">58mm</option><option value="48">80mm</option></select></div>
      <div class="col-md-9"><div class="btn-list">
        <button class="btn btn-sm btn-info" id="btn-bt">📡 Bluetooth</button>
        <button class="btn btn-sm btn-outline-info" id="btn-usb">USB</button>
        <button class="btn btn-sm btn-dark" id="btn-bt-img" title="Cetak tampilan tiket apa adanya sebagai gambar (lambat ±10-30 dtk)">📡 BT Gambar</button>
        <button class="btn btn-sm btn-outline-dark" id="btn-usb-img" title="Cetak tampilan tiket apa adanya sebagai gambar via USB">🔌 USB Gambar</button>
        <button class="btn btn-sm btn-outline-warning" id="btn-drawer-bt" title="Kirim ESC p (1B 70 00 19 FA) via Bluetooth — buka laci kas RJ11 tanpa cetak">🗄️ Laci (BT)</button>
        <button class="btn btn-sm btn-outline-warning" id="btn-drawer-usb" title="Kirim ESC p (1B 70 00 19 FA) via USB — buka laci kas RJ11 tanpa cetak">🗄️ Laci (USB)</button>
        @if(!empty($lanPrinters))
        <select id="lan-printer" class="form-select form-select-sm d-inline-block" style="max-width:220px">
          @foreach(($lanPrinters ?? []) as $p)
          <option value="{{ $p['id'] }}" data-width="{{ $p['width'] }}" data-cols="{{ $p['width']==58?32:48 }}">{{ $p['name'] }} — {{ $p['width'] }}mm ({{ $p['ip'] }})</option>
          @endforeach
        </select>
        <button class="btn btn-sm btn-success" id="btn-lan">Cetak LAN</button>
        @endif
        @foreach(($lanPrinters ?? []) as $p)
        <form method="POST" action="{{ route('printers.lan.service', [$service->id, $p['id']]) }}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-success">🌐 {{ $p['name'] }}</button></form>
        @endforeach
        <button class="btn btn-sm btn-outline-dark" id="btn-png">🖼️ PNG</button>
        <form method="POST" action="{{ route('printers.wa.service', $service) }}" class="d-inline-flex gap-1 align-items-center" title="Kirim tanda terima ringkas via WA">
          @csrf
          <input type="text" name="phone" class="form-control form-control-sm d-inline-block" style="max-width:150px" placeholder="No WA cth 0812…" value="{{ $service->customer->phone ?? '' }}" required>
          <button class="btn btn-sm btn-success text-nowrap" type="submit">📲 Kirim via WA</button>
        </form>
      </div>
      @if(!empty($lanPrinters))<div class="form-text mt-1">Dropdown LAN otomatis dipilih sesuai lebar kertas; pilihan tersimpan di HP/kasir ini.</div>@endif</div>
    </div>
    <div id="print-log" class="bg-dark text-green rounded p-2 mt-2 d-none"></div>
  </div></div>

  {{-- AREA CETAK --}}
  <div id="ticket-area" class="ticket-box p-3 shadow-sm rounded">
    @if(!empty($brandingLogo))<div class="text-center mb-1"><img src="{{ asset($brandingLogo) }}" style="max-height:64px;max-width:100%" alt="Logo"></div>@endif
    <h3 class="text-center mb-0">TANDA TERIMA SERVICE</h3>
    <p class="text-center text-secondary mb-1">{{ $service->store->name ?? 'ServiceHP' }}@if(!empty($branding['header']))<br>{!! nl2br(e($branding['header'])) !!}@else — {{ $service->store->phone ?? '' }}@endif</p><hr>
    <table class="table table-sm"><tr><th>Tiket</th><td><b>{{ $service->ticket_no }}</b></td></tr>
    <tr><th>Tanggal</th><td>{{ $service->created_at->format('d/m/Y H:i') }}</td></tr>
    <tr><th>Pelanggan</th><td>{{ $service->customer->name ?? '' }} ({{ $service->customer->phone ?? '' }})</td></tr>
    <tr><th>Device</th><td>{{ $service->product_brand }} {{ $service->product_type }}</td></tr>
    <tr><th>IMEI</th><td>{{ $service->imei ?? '-' }}</td></tr>
    <tr><th>Keluhan</th><td>{{ $service->complaint }}</td></tr>
    <tr><th>Kondisi</th><td>{{ $service->condition_note ?? '-' }}</td></tr>
    <tr><th>Estimasi</th><td>Rp {{ number_format($service->estimate_cost,0,',','.') }}</td></tr>
    <tr><th>Status</th><td><b>{{ strtoupper($service->status) }}</b></td></tr></table>
    <div class="text-center"><svg id="barcode"></svg><div class="small text-secondary">{{ url('/track') }}</div></div>
    @if($service->photos)
        @php
            $beforePhotos = collect((array) $service->photos)->reject(fn($p) => str_contains((string) $p, 'after'))->values();
            $afterPhotos = collect((array) $service->photos)->filter(fn($p) => str_contains((string) $p, 'after'))->values();
        @endphp
        <div class="mt-2"><b>Foto kondisi awal:</b>
        @if($beforePhotos->isNotEmpty())
            <div class="row g-1">
            @foreach($beforePhotos as $p)
                <div class="col-6"><img src="{{ asset('storage/'.$p) }}" class="w-100 rounded"></div>
            @endforeach
            </div>
        @else
            <span class="text-secondary"> -</span>
        @endif
        </div>
        @if($afterPhotos->isNotEmpty())
        <div class="mt-2"><b>Foto sesudah:</b>
            <div class="row g-1">
            @foreach($afterPhotos as $p)
                <div class="col-6"><img src="{{ asset('storage/'.$p) }}" class="w-100 rounded"></div>
            @endforeach
            </div>
        </div>
        @endif
    @endif
    @if(!empty($handoverSignature))
    <div class="mt-2"><b>Serah terima bertanda tangan:</b>
      <div class="d-flex align-items-end justify-content-between gap-2 mt-1">
        <div><img src="{{ asset('storage/'.$handoverSignature) }}" alt="TTD pelanggan" style="max-height:90px;background:#fff;border:1px solid #ccc;border-radius:6px"><div class="small text-secondary">{{ $service->customer->name ?? '' }} · {{ $handoverLog?->created_at?->format('d/m/Y H:i') }}</div></div>
        <div class="small text-center">Petugas,<br><br><br>(..............)</div>
      </div>
    </div>
    @endif
    <p class="text-secondary small mt-2 mb-0">@if(!empty($branding['footer'])){!! nl2br(e($branding['footer'])) !!}@else Pengambilan wajib bawa tanda terima ini. Garansi {{ $service->warranty_days }} hari setelah serah terima.@endif</p>
  </div>
</div>
<script id="receipt-data" type="application/json">{"type":"service","store":{"name":@json($service->store->name ?? 'ServiceHP'),"phone":@json($service->store->phone ?? '')},"ticket":@json($service->ticket_no),"datetime":@json($service->created_at->format('d/m/Y H:i')),"customer":@json(trim(($service->customer->name ?? '-').' '.($service->customer->phone ?? ''))),"device":@json(trim(($service->product_brand ?? '').' '.($service->product_type ?? ''))),"imei":@json($service->imei),"complaint":@json($service->complaint),"condition":@json($service->condition_note),"estimate":{{ (float)$service->estimate_cost }},"dp":{{ (float)$service->dp }},"status":@json($service->status),"track_url":@json(url('/track').'?no='.$service->ticket_no)}</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="{{ asset('js/printer.js') }}"></script>
<script>
try{JsBarcode("#barcode",@json($service->ticket_no),{format:"CODE128",height:50,width:1.4,fontSize:12});}catch(e){}
function doPrint(paper){ document.body.setAttribute('data-print-paper',paper); window.print(); }
(function(){
  const logBox=document.getElementById('print-log');
  const log=m=>{logBox.classList.remove('d-none');logBox.innerHTML+='<div>> '+m+'</div>';logBox.scrollTop=1e6;};
  const cols=()=>parseInt(document.getElementById('bt-width').value,10);
  const widthMm=()=>cols()===32?58:80;
  const data=JSON.parse(document.getElementById('receipt-data').textContent);
  const lanSel=document.getElementById('lan-printer');
  const syncLan=()=>{if(lanSel)PrintHub.lanAutoPick(lanSel,widthMm());};
  document.getElementById('bt-width').addEventListener('change',syncLan);
  if(lanSel){
    syncLan();
    lanSel.addEventListener('change',()=>PrintHub.lanSetDefault(widthMm(),lanSel.value));
    document.getElementById('btn-lan').onclick=()=>{
      if(!lanSel.value){alert('Pilih printer WiFi/LAN dulu.');return;}
      PrintHub.lanSetDefault(widthMm(),lanSel.value);
      const f=document.createElement('form');
      f.method='POST';
      f.action='{{ url('/printers/lan/service') }}/{{ $service->id }}/'+encodeURIComponent(lanSel.value);
      const t=document.createElement('input');t.type='hidden';t.name='_token';t.value='{{ csrf_token() }}';
      f.appendChild(t);document.body.appendChild(f);f.submit();
    };
  }
  document.getElementById('btn-bt').onclick=async()=>{logBox.innerHTML='';try{await PrintHub.sendBluetooth(PrintHub.serviceBytes(data,cols()),log);}catch(e){log('GAGAL: '+e.message);alert(e.message);}};
  document.getElementById('btn-usb').onclick=async()=>{logBox.innerHTML='';try{await PrintHub.sendUSB(PrintHub.serviceBytes(data,cols()),log);}catch(e){log('GAGAL: '+e.message);alert(e.message);}};
  // Mode gambar: tampilan tiket apa adanya (lambat ±10-30 dtk, butuh kertas cukup).
  const imgWidth=()=>cols()===32?384:576;
  document.getElementById('btn-bt-img').onclick=async()=>{logBox.innerHTML='';try{await PrintHub.printImage('ticket-area','bluetooth',imgWidth(),log);}catch(e){log('GAGAL: '+e.message);alert(e.message);}};
  document.getElementById('btn-usb-img').onclick=async()=>{logBox.innerHTML='';try{await PrintHub.printImage('ticket-area','usb',imgWidth(),log);}catch(e){log('GAGAL: '+e.message);alert(e.message);}};
  // Buka laci kas (ESC p m t1 t2 = 1B 70 00 19 FA) — tanpa cetak, tanpa backend.
  document.getElementById('btn-drawer-bt').onclick=async()=>{logBox.innerHTML='';try{await PrintHub.openDrawer('bluetooth',0,log);}catch(e){log('GAGAL: '+e.message);alert(e.message);}};
  document.getElementById('btn-drawer-usb').onclick=async()=>{logBox.innerHTML='';try{await PrintHub.openDrawer('usb',0,log);}catch(e){log('GAGAL: '+e.message);alert(e.message);}};
  document.getElementById('btn-png').onclick=async()=>{try{await PrintHub.downloadPNG('ticket-area',data.ticket+'.png');}catch(e){alert(e.message);}};
  @if(!empty($branding['auto_print']))
  // Cetak otomatis sekali saat halaman dibuka (setting per toko). Tombol manual tetap tersedia.
  if(!window.__autoPrinted){window.__autoPrinted=true;setTimeout(()=>{try{doPrint('80mm');}catch(e){}},600);}
  @endif
})();
</script>
</body></html>
