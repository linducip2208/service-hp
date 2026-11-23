@extends('layouts.tabler')
@section('title', $product->name)
@section('content')
<div class="page-header"><div class="row align-items-center"><div class="col"><h2 class="page-title">{{ $product->name }}</h2>
<div class="text-secondary font-monospace">{{ $product->sku }} • {{ $product->type }} • {{ $product->brand->name ?? '' }}</div></div>
<div class="col-auto"><button class="btn btn-outline-primary" onclick="cetakLabel()">Cetak Label Rak</button> <a href="{{ route('products.edit',$product) }}" class="btn btn-outline-secondary">Edit</a> <a href="{{ route('products.index') }}" class="btn btn-outline-secondary">Kembali</a></div></div></div>
<div class="row g-3">
<div class="col-lg-5"><div class="card"><div class="card-body text-center">
@if($product->photo)<img src="{{ asset('storage/'.$product->photo) }}" class="img-fluid rounded mb-2" style="max-height:240px">@else<div class="text-secondary py-4">Tidak ada foto</div>@endif
<div><svg id="barcode"></svg><div class="font-monospace small">{{ $product->barcode }}</div></div>
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<script>JsBarcode("#barcode", @json($product->barcode ?: $product->sku), {format:"CODE128", height:50, displayValue:false});</script>
</div>
<div class="card-body border-top"><dl class="row mb-0">
<dt class="col-5">Harga beli</dt><dd class="col-7 text-end">Rp {{ number_format($product->purchase_price,0,',','.') }}</dd>
<dt class="col-5">Harga jual</dt><dd class="col-7 text-end">Rp {{ number_format($product->selling_price,0,',','.') }}</dd>
<dt class="col-5">RAM / Storage</dt><dd class="col-7 text-end">{{ $product->ram }} / {{ $product->storage }}</dd>
<dt class="col-5">Warna</dt><dd class="col-7 text-end">{{ $product->warna ?: '-' }}</dd>
<dt class="col-5">Serialized</dt><dd class="col-7 text-end">{{ $product->is_serialized ? 'Ya (IMEI)' : 'Tidak' }}</dd>
<dt class="col-5">Status</dt><dd class="col-7 text-end">{{ $product->is_active ? 'Aktif' : 'Nonaktif' }}</dd>
</dl>
<form method="POST" action="{{ route('products.destroy',$product) }}" onsubmit="return confirm('Hapus produk ini?')">@csrf @method('DELETE')<button class="btn btn-outline-danger btn-sm w-100">Hapus Produk</button></form>
</div></div></div>
<div class="col-lg-7">
<div class="card"><div class="card-header"><h3 class="card-title">Stok per toko</h3></div>
<div class="table-responsive"><table class="table mb-0"><thead><tr><th>Toko</th><th>Lokasi / Rak</th><th class="text-end">Qty</th></tr></thead>
<tbody>@foreach($product->stocks as $s)<tr><td>{{ $s->store->name ?? '' }}</td><td>{{ $s->location ?: '—' }}</td><td class="text-end">{{ $s->qty }}</td></tr>@endforeach</tbody></table></div></div>
<div class="card mt-3" id="kartuStokCard">
    <div class="card-header">
        <h3 class="card-title">
            Kartu Stok — 20 mutasi terakhir {{ session('active_store_id') ? '(toko aktif)' : '' }}
        </h3>
        <span class="ms-auto text-secondary small">
            Stok kini: <b>{{ $current ?? 0 }}</b>
        </span>
        <button
            type="button"
            class="btn btn-outline-primary btn-sm ms-2 no-print"
            onclick="cetakKartuStok()"
        >
            <i class="ti ti-printer me-1"></i> Cetak Kartu
        </button>
    </div>
    <div class="table-responsive" id="kartuStokArea">
        <table class="table table-sm mb-0"><thead><tr><th>Waktu</th><th>Tipe</th><th class="text-end">Masuk</th><th class="text-end">Keluar</th><th class="text-end">Saldo*</th><th>Catatan</th></tr></thead>
<tbody>@forelse($rows ?? [] as $m)<tr><td class="small text-nowrap">{{ $m->created_at->format('d/m/Y H:i') }}</td>
<td><span class="badge bg-{{ in_array($m->type,['masuk','mutasi_masuk'])?'green':(in_array($m->type,['keluar','mutasi_keluar'])?'red':'yellow') }}-lt">{{ $m->type }}</span></td>
<td class="text-end">{{ in_array($m->type,['masuk','mutasi_masuk']) ? $m->qty : '' }}</td>
<td class="text-end">{{ in_array($m->type,['keluar','mutasi_keluar']) ? $m->qty : '' }}</td>
<td class="text-end"><b>{{ $m->saldo }}</b></td>
<td class="small text-secondary">{{ $m->note }}@if($m->user) • {{ $m->user->name }}@endif</td></tr>
@empty<tr><td colspan="6" class="text-center text-secondary">Belum ada mutasi.</td></tr>@endforelse</tbody></table></div>
<div class="card-footer text-secondary small">*Saldo berjalan perkiraan dari stok kini ditarik mundur (opname dihitung sebagai penyesuaian tanpa efek saldo, termasuk pindah lokasi yang tercatat qty 0).</div></div>
@if($product->is_serialized)<div class="card mt-3"><div class="card-header"><h3 class="card-title">Unit IMEI (100 terbaru)</h3></div>
<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>IMEI</th><th>Toko</th><th>Kondisi</th><th>Status</th></tr></thead>
<tbody>@foreach($product->items as $u)<tr><td class="font-monospace">{{ $u->imei ?: '-' }}</td><td>{{ $u->store_id }}</td><td>{{ $u->condition }}</td><td><span class="badge bg-{{ $u->status==='tersedia'?'green':'secondary' }}-lt">{{ $u->status }}</span></td></tr>@endforeach</tbody></table></div></div>@endif
<div class="card mt-3 no-print"><div class="card-header"><h3 class="card-title">Label Rak</h3></div><div class="card-body row g-2 align-items-end">
<div class="col-4"><label class="form-label">Jumlah label</label><input id="labelQty" type="number" min="1" max="60" value="9" class="form-control"></div>
<div class="col-8"><button class="btn btn-primary" onclick="cetakLabel()">Cetak / Pratinjau</button>
<div class="text-secondary small mt-1">Nama + harga + barcode SKU, 3 kolom A4, cetak via browser.</div></div></div></div>
</div></div>
<div id="labelSheet" class="d-none"><div id="labelGrid" class="label-grid"></div></div>
<style>@media print{body *{visibility:hidden}#labelSheet,#labelSheet *{visibility:visible}#labelSheet{display:block!important;position:absolute;inset:0}.no-print,.page-header,.card{display:none!important}#labelSheet.card{display:block!important}.label-grid{display:grid!important;grid-template-columns:1fr 1fr 1fr;gap:8mm;padding:10mm}.label-cell{border:1px dashed #888;padding:4mm;text-align:center;page-break-inside:avoid}}</style>
<style>.label-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px}.label-cell{border:1px dashed #aaa;padding:6px;text-align:center}</style>
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<script>
const P_NAME = @json($product->name), P_PRICE = @json('Rp ' . number_format($product->selling_price,0,',','.')), P_CODE = @json($product->barcode ?: $product->sku);
function cetakLabel(){
  const n = Math.max(1, Math.min(60, parseInt(document.getElementById('labelQty').value || '9', 10)));
  const grid = document.getElementById('labelGrid'); grid.innerHTML = '';
  for(let i=0;i<n;i++){
    const d = document.createElement('div'); d.className='label-cell';
    d.innerHTML = '<div><b></b></div><div class="lbl-price"></div><svg class="lbl-bc"></svg><div class="font-monospace small"></div>';
    d.querySelector('b').textContent = P_NAME; d.querySelector('.lbl-price').textContent = P_PRICE;
    d.lastElementChild.textContent = P_CODE; grid.appendChild(d);
    JsBarcode(d.querySelector('.lbl-bc'), P_CODE, {format:'CODE128', height:38, displayValue:false, width:1.4});
  }
  document.getElementById('labelSheet').classList.remove('d-none');
  document.getElementById('labelSheet').scrollIntoView();
  setTimeout(()=>window.print(), 300);
}
</script>
<script>
function cetakKartuStok() {
  var area = document.getElementById('kartuStokArea');
  if (!area) {
    return;
  }
  var w = window.open('', '_blank', 'width=900,height=650');
  if (!w) {
    return;
  }
  w.document.write(
    '<html><head><title>Kartu Stok - ' +
    @json($product->name) +
    '</title>'
  );
  w.document.write(
    '<style>' +
    'body{font-family:Arial,sans-serif;padding:16px;color:#000;}' +
    'table{border-collapse:collapse;width:100%;font-size:12px;}' +
    'th,td{border:1px solid #999;padding:4px 6px;text-align:left;}' +
    'th{background:#eee;}' +
    '.text-end{text-align:right;}' +
    '</style></head><body>'
  );
  w.document.write(
    '<h3>Kartu Stok — ' +
    @json($product->name) +
    '</h3>'
  );
  w.document.write(
    '<p>' +
    @json($product->sku) +
    ' • Stok kini: ' +
    @json((string) ($current ?? 0)) +
    '</p>'
  );
  w.document.write(area.innerHTML.replace('<table', '<table border="1"'));
  w.document.write('</body></html>');
  w.document.close();
  w.focus();
  setTimeout(function () {
    w.print();
  }, 300);
}
</script>
@endsection
