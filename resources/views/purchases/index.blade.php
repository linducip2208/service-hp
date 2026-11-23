@extends('layouts.tabler')
@section('title','Pembelian Supplier')
@section('content')
@if(session('import_fail'))
    <div
        class="alert alert-warning"
        role="alert"
    >
        <strong>
            Rincian baris import gagal ({{ count(session('import_fail')) }}):
        </strong>
        <ul class="mb-0">
            @foreach(session('import_fail') as $f)
                <li>{{ $f }}</li>
            @endforeach
        </ul>
    </div>
@endif
<div class="card mb-3">
    <div class="card-body">
        <form
            method="POST"
            action="{{ route('purchases.import') }}"
            enctype="multipart/form-data"
            class="row g-2 align-items-end"
            onsubmit="return confirm('Import CSV pembelian sekarang? Setiap baris menambah stok + kas/hutang otomatis.')"
        >
            @csrf
            <div class="col-md-6">
                <label class="form-label">
                    Import CSV pembelian
                    <span class="text-secondary">
                        (kolom: supplier,tanggal,jatuh_tempo,dibayar,akun_kas,items)
                    </span>
                </label>
                <input
                    type="file"
                    name="file"
                    accept=".csv,.txt"
                    class="form-control"
                    required
                >
                <div class="form-hint mt-1">
                    supplier = nama supplier terdaftar; tanggal kosong = hari ini; jatuh_tempo kosong = tanggal+14 hari.
                    items = <code>SKU:qty:harga</code> dipisah <code>;</code>, mis. <code>SKU001:2:150000;SKU002:1:75000</code>.
                    Setiap baris = 1 pembelian (stok + kas keluar + hutang otomatis, sama dengan input manual).
                    Aturan foto nota &gt;Rp5jt tidak berlaku di import (CSV tanpa file).
                </div>
            </div>
            <div class="col-md-3">
                <button class="btn btn-outline-primary w-100">
                    Import
                </button>
            </div>
            <div class="col-md-3">
                <a
                    href="{{ route('purchases.template') }}"
                    class="btn btn-outline-success w-100"
                >
                    <i class="ti ti-download me-1"></i> Unduh Template
                </a>
            </div>
        </form>
    </div>
</div>
<div class="page-header"><div class="row align-items-center"><div class="col">
  <div class="page-pretitle">Keuangan • Stok</div><h2 class="page-title">Pembelian dari Supplier</h2><div class="text-secondary">Stok bertambah otomatis + hutang bila belum lunas</div>
</div></div></div>
<div class="row row-cards">
<div class="col-12"><div class="card"><div class="card-header"><h3 class="card-title">Pembelian Baru</h3></div>
<div class="card-body">
    <form
        method="POST"
        action="{{ route('purchases.store') }}"
        id="formBeli"
        enctype="multipart/form-data"
        data-loading
        onsubmit="return confirm('Simpan pembelian? Stok & hutang tercatat otomatis.')"
    >
        @csrf
<div class="row">
  <div class="col-md-3 mb-2"><label class="form-label">Supplier</label><select name="supplier_id" class="form-select" required>@foreach($suppliers as $s)<option value="{{ $s->id }}" {{ ($prefill['supplier_id'] ?? '')==$s->id?'selected':'' }}>{{ $s->name }}</option>@endforeach</select></div>
  <div class="col-md-3 mb-2"><label class="form-label">Akun Kas (untuk DP/lunas)</label><select name="cash_account_id" class="form-select" required>@foreach(\App\Models\CashAccount::where('store_id',$storeId)->orderBy('name')->get() as $a)<option value="{{ $a->id }}">{{ $a->name }} ({{ number_format($a->balance,0,',','.') }})</option>@endforeach</select></div>
  <div class="col-md-2 mb-2"><label class="form-label">Dibayar (Rp)</label><input type="number" name="paid" class="form-control" min="0" value="0"></div>
  <div class="col-md-2 mb-2"><label class="form-label">Jatuh tempo</label><input type="date" name="due_date" class="form-control"></div>
  <div class="col-md-2 mb-2"><label class="form-label">Catatan</label><input name="note" class="form-control"></div>
  <div class="col-md-4 mb-2">
      <label
          class="form-label"
          for="nota_foto"
      >Foto Nota Supplier (opsional, JPG/PNG maks 2MB)</label>
      <input
          id="nota_foto"
          name="nota_foto"
          type="file"
          accept="image/*"
          class="form-control"
      >
      <div class="form-text">Foto tersimpan di storage + path dicatat [BUKTI:path] pada catatan pembelian ini.</div>
  </div>
</div>
<div class="table-responsive"><table class="table" id="tblItems"><thead><tr><th>Produk</th><th width="120">Qty</th><th width="180">Harga Beli</th><th></th></tr></thead><tbody></tbody></table></div>
<button type="button" class="btn btn-outline-secondary btn-sm" onclick="tambahBaris()">+ Tambah Item</button>
<button class="btn btn-primary btn-sm">Simpan Pembelian</button>
</form></div></div></div>
<div class="col-12"><div class="card"><div class="card-header"><h3 class="card-title">Riwayat Pembelian</h3>
<form class="ms-auto d-flex gap-2" method="GET">
<input
    name="q"
    value="{{ request('q') }}"
    class="form-control form-control-sm"
    placeholder="Cari invoice"
>
<select name="status" class="form-select form-select-sm">
    <option value="">Semua</option>
    @foreach(['lunas','sebagian','belum'] as $s)
        <option {{ request('status')==$s?'selected':'' }} value="{{ $s }}">{{ $s }}</option>
    @endforeach
</select>
<input
    type="month"
    name="bulan"
    value="{{ request('bulan') }}"
    class="form-control form-control-sm"
    title="Filter bulan pembelian (created_at)"
>
<button class="btn btn-sm btn-outline-primary">Cari</button>
@if(request('bulan') || request('status') || request('q'))
    <a
        href="{{ route('purchases.index') }}"
        class="btn btn-sm btn-outline-secondary"
        title="Reset filter"
    >
        Reset
    </a>
@endif
<a
    href="{{ route('purchases.index', array_merge(request()->query(), ['export' => 'csv'])) }}"
    class="btn btn-sm btn-outline-success"
><i class="ti ti-download me-1"></i> Export CSV</a>
<a
    href="{{ route('purchases.index', array_merge(request()->query(), ['export' => 'items'])) }}"
    class="btn btn-sm btn-outline-success"
><i class="ti ti-download me-1"></i> Export Rincian (Item)</a>
<button
    type="button"
    class="btn btn-sm btn-outline-primary"
    id="printSelectedPoBtn"
>
    <i class="ti ti-printer me-1"></i> Cetak Terpilih
</button></form></div>
<div class="table-responsive"><table class="table table-vcenter card-table"><thead><tr><th class="w-1"><input
                    type="checkbox"
                    class="form-check-input"
                    id="checkAllPurchases"
                    title="Pilih semua di halaman ini"
                ></th><th>Invoice</th><th>Supplier</th><th class="text-end">Total</th><th class="text-end">Dibayar</th><th>Status</th><th></th></tr></thead>
<tbody>@forelse($purchases as $p)<tr><td><input
                type="checkbox"
                class="form-check-input po-check"
                value="{{ $p->id }}"
            ></td><td>{{ $p->invoice_no }}</td><td>{{ $p->supplier->name ?? '-' }}</td>
<td class="text-end">{{ number_format($p->total,0,',','.') }}</td><td class="text-end">{{ number_format($p->paid,0,',','.') }}</td>
<td><span class="badge bg-{{ $p->status==='lunas'?'green':($p->status==='belum'?'red':'yellow') }}-lt">{{ $p->status }}</span></td><td><a href="{{ route('purchases.show',$p) }}" class="btn btn-sm btn-outline-primary">Detail</a></td></tr>@empty<tr><td colspan="7"><div class="empty py-3"><div class="empty-icon"><i class="ti ti-shopping-cart fs-1 text-secondary"></i></div><p class="empty-title">Belum ada pembelian</p><p class="empty-subtitle text-secondary">Buat pembelian lewat formulir Pembelian Baru di atas — stok & hutang tercatat otomatis.</p></div></td></tr>@endforelse</tbody></table></div>
<div class="card-footer">{{ $purchases->links() }}</div></div></div>
</div>
<script>
(function () {
    var checkAll = document.getElementById('checkAllPurchases');
    if (checkAll) {
        checkAll.addEventListener('change', function () {
            document.querySelectorAll('.po-check').forEach(function (cb) {
                cb.checked = checkAll.checked;
            });
        });
    }
    var btn = document.getElementById('printSelectedPoBtn');
    if (btn) {
        btn.addEventListener('click', function () {
            var ids = Array.from(document.querySelectorAll('.po-check:checked')).map(function (cb) {
                return cb.value;
            });
            if (!ids.length) {
                alert('Pilih minimal 1 pembelian dulu (centang baris tabel).');
                return;
            }
            if (ids.length > 50) {
                alert('Maksimal 50 PO per cetak massal — kurangi pilihan Anda.');
                return;
            }
            var q = ids.map(function (id) {
                return 'ids[]=' + encodeURIComponent(id);
            }).join('&');
            window.open("{{ route('purchases.bulkPrint') }}?" + q, '_blank');
        });
    }
})();
</script>
@push('js')<script>
const produkOptions = @json($products->map(fn($p)=>['id'=>$p->id,'name'=>$p->name,'price'=>$p->purchase_price]));
function tambahBaris(selPid=null, qty=1, price=null){
  const tb=document.querySelector('#tblItems tbody'); const tr=document.createElement('tr');
  let opt=produkOptions.map(p=>`<option value="${p.id}"${String(p.id)===String(selPid)?' selected':''}>${p.name} — Rp ${Number(p.price||0).toLocaleString('id-ID')}</option>`).join('');
  const defPrice = price ?? (produkOptions.find(p=>String(p.id)===String(selPid))?.price || 0);
  tr.innerHTML=`<td><select name="product_id[]" class="form-select" required>${opt}</select></td>
  <td><input type="number" name="qty[]" class="form-control" min="1" value="${qty}" required></td>
  <td><input type="number" name="price[]" class="form-control" min="0" value="${defPrice}" required></td>
  <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()">×</button></td>`;
  tb.appendChild(tr);
}
const prefillItems = @json($prefill['items'] ?? []);
if(prefillItems.length){ prefillItems.forEach(it=>tambahBaris(it.product_id, it.qty, it.price)); }
else { tambahBaris(); }
</script>@endpush
@endsection
