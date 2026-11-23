@extends('layouts.tabler')
@section('title', 'Unit / IMEI')
@section('content')
<div class="page-header"><div class="row align-items-center"><div class="col"><div class="page-pretitle">Inventory</div><h2 class="page-title">Unit / IMEI @if($showAll)<span class="badge bg-purple-lt">Semua toko</span>@endif</h2></div></div></div>
@if(($lowSpareparts ?? collect())->count())
<div class="alert alert-danger"><strong>Sparepart menipis ({{ $lowSpareparts->count() }}):</strong>
@foreach($lowSpareparts as $lp)<span class="badge bg-red-lt mt-1 me-1">{{ $lp->name }} — stok {{ $lp->stock_total ?? 0 }}/min {{ $lp->min_stock }}</span>@endforeach</div>
@endif
<div class="row g-3">
<div class="col-lg-4"><div class="card"><div class="card-header"><h3 class="card-title">Tambah Unit</h3></div><div class="card-body"><form method="POST" action="{{ route('units.store') }}" onsubmit="return confirm('Simpan unit ini ke stok?')">@csrf
<label class="form-label">Produk serialized</label><select name="product_id" class="form-select" required>@foreach($products as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select>
<label class="form-label mt-2">IMEI</label><input name="imei" class="form-control font-monospace" placeholder="35…">
<label class="form-label mt-2">Serial</label><input name="serial" class="form-control">
<div class="row g-2 mt-2"><div class="col-6"><label class="form-label">Kondisi</label><select name="condition" class="form-select"><option value="baru">baru</option><option value="bekas">bekas</option><option value="refurbished">refurbished</option><option value="konsinyasi">konsinyasi</option></select></div>
<div class="col-6"><label class="form-label">Supplier</label><select name="supplier_id" class="form-select"><option value="">—</option>@foreach($suppliers as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select></div>
<div class="col-6"><label class="form-label">Harga beli</label><input name="purchase_price" type="number" min="0" value="0" class="form-control" required></div>
<div class="col-6"><label class="form-label">Harga jual</label><input name="selling_price" type="number" min="0" value="0" class="form-control" required></div>
<div class="col-6"><label class="form-label">Garansi (bln)</label><input name="warranty_months" type="number" min="0" value="0" class="form-control"></div>
<div class="col-6"><label class="form-label">Status</label><select name="status" class="form-select"><option value="tersedia">tersedia</option><option value="service">service</option><option value="konsinyasi">konsinyasi</option></select></div></div>
<button class="btn btn-primary w-100 mt-3">Simpan Unit</button></form></div></div></div>
<div class="col-lg-8">
    @if (session('import_fail'))
        <div class="alert alert-warning">
            <strong>
                Rincian baris import gagal / dilewati ({{ count(session('import_fail')) }}):
            </strong>
            <ul class="mb-0">
                @foreach (session('import_fail') as $f)
                    <li>{{ $f }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    <div class="card mb-3">
        <div class="card-body">
            <form
                method="POST"
                action="{{ route('units.import') }}"
                enctype="multipart/form-data"
                class="row g-2 align-items-end"
                onsubmit="return confirm('Import CSV unit/IMEI sekarang?')"
            >
                @csrf
                <div class="col-md-6">
                    <label class="form-label">
                        Import CSV unit / IMEI
                        <span class="text-secondary">
                            (kolom: sku,nama_produk,imei,serial,kondisi,harga_beli,harga_jual,supplier)
                        </span>
                    </label>
                    <input
                        type="file"
                        name="file"
                        accept=".csv,.txt"
                        class="form-control"
                        required
                    >
                </div>
                <div class="col-md-3">
                    <button class="btn btn-outline-primary w-100">
                        Import
                    </button>
                </div>
                <div class="col-md-3">
                    <a
                        href="{{ route('units.template') }}"
                        class="btn btn-outline-success w-100"
                    >
                        <i class="ti ti-download me-1"></i> Unduh Template
                    </a>
                </div>
            </form>
            <div class="small text-secondary mt-2">
                IMEI wajib &amp; unik — duplikat dilewati tanpa mengubah unit existing.
                Produk tak dikenal ditolak (buat dulu di menu Produk).
            </div>
        </div>
    </div>
    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" class="row g-2">
                <div class="col-md-5">
                    <input
                        name="q"
                        value="{{ request('q') }}"
                        class="form-control"
                        placeholder="Cari IMEI / serial / produk…"
                    >
                </div>
                <div class="col-md-3">
                    <select name="status" class="form-select">
                        <option value="">
                            Semua status
                        </option>
                        @foreach (['tersedia', 'terjual', 'service', 'mutasi', 'retur', 'konsinyasi'] as $s)
                            <option
                                value="{{ $s }}"
                                {{ request('status') === $s ? 'selected' : '' }}
                            >
                                {{ $s }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="sort" class="form-select" title="Urutkan">
                        <option value="">
                            Terbaru
                        </option>
                        <option value="age" {{ request('sort') === 'age' ? 'selected' : '' }}>
                            Umur tertua
                        </option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-secondary w-100">
                        Cari
                    </button>
                </div>
                <div class="col-md-3">
                    <input
                        type="number"
                        min="0"
                        step="1000"
                        name="price_min"
                        value="{{ request('price_min') }}"
                        class="form-control"
                        placeholder="Harga jual min (Rp)"
                        title="Harga jual minimum"
                    >
                </div>
                <div class="col-md-3">
                    <input
                        type="number"
                        min="0"
                        step="1000"
                        name="price_max"
                        value="{{ request('price_max') }}"
                        class="form-control"
                        placeholder="Harga jual maks (Rp)"
                        title="Harga jual maksimum"
                    >
                </div>
                <div class="col-md-4">
                    <a
                        href="{{ route('units.index') }}"
                        class="btn btn-link btn-sm"
                    >
                        Reset filter
                    </a>
                </div>
                <div class="col-md-12">
                    <a
                        href="{{ route('units.index', array_merge(request()->query(), ['export' => 'csv'])) }}"
                        class="btn btn-outline-success btn-sm"
                    >
                        <i class="ti ti-download me-1"></i> Export CSV (filter aktif)
                    </a>
                    @if (request('price_min') || request('price_max'))
                        <span class="small text-secondary ms-2">
                            Harga:
                            {{ request('price_min') ? '≥ Rp ' . number_format((float) request('price_min'), 0, ',', '.') : '' }}
                            {{ request('price_max') ? '≤ Rp ' . number_format((float) request('price_max'), 0, ',', '.') : '' }}
                        </span>
                    @endif
                </div>
            </form>
        </div>
    </div>
    <div class="card mb-3 no-print">
        <div class="card-body">
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <button
                    type="button"
                    class="btn btn-primary"
                    id="btnCetakTerpilih"
                    onclick="cetakLabelTerpilih()"
                >
                    <i class="ti ti-printer me-1"></i>Cetak Terpilih
                    (<span id="cetakCount">0</span>)
                </button>
                <button
                    type="button"
                    class="btn btn-outline-secondary"
                    onclick="togglePilihSemua(true)"
                >
                    Pilih semua di halaman
                </button>
                <button
                    type="button"
                    class="btn btn-outline-secondary"
                    onclick="togglePilihSemua(false)"
                >
                    Bersihkan
                </button>
                <span class="small text-secondary ms-auto">
                    Centang baris → Cetak Terpilih → N label tampil di sheet lalu print otomatis.
                </span>
            </div>
        </div>
    </div>
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th class="no-print" style="width:38px;">
                        <input
                            type="checkbox"
                            id="checkSemua"
                            class="form-check-input"
                            title="Pilih semua"
                            onclick="togglePilihSemua(this.checked)"
                        >
                    </th>
                    <th>
                        IMEI
                    </th>
                    <th>
                        Produk
                    </th>
                    <th>
                        Toko
                    </th>
                    <th>
                        Kondisi
                    </th>
                    <th>
                        Status
                    </th>
                    <th>
                        <a
                            href="{{ request()->fullUrlWithQuery(['sort' => request('sort') === 'age' ? null : 'age']) }}"
                            title="Urutkan umur stok tertua"
                        >
                            Umur stok
                            @if (request('sort') === 'age')
                                ▲
                            @endif
                        </a>
                    </th>
                    <th class="text-end">
                        Jual
                    </th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($units as $u)
                    @php
                        $ageDays = $u->created_at
                            ? (int) now()->diffInDays($u->created_at)
                            : 0;
                        $ageBadge = $ageDays > 180
                            ? 'bg-red-lt'
                            : ($ageDays > 90 ? 'bg-yellow-lt' : 'bg-green-lt');
                    @endphp
                    <tr>
                        <td class="no-print">
                            <input
                                type="checkbox"
                                class="form-check-input check-unit"
                                data-produk="{{ $u->product->name ?? '-' }}"
                                data-imei="{{ $u->imei ?: ($u->serial ?: ('UNIT-' . $u->id)) }}"
                                data-serial="{{ $u->serial ?? '' }}"
                                data-harga="Rp {{ number_format($u->selling_price, 0, ',', '.') }}"
                                data-toko="{{ $u->store->name ?? '' }}"
                                onchange="updateCetakCount()"
                            >
                        </td>
                        <td class="font-monospace">
                            {{ $u->imei ?: '-' }}
                            <div class="small text-secondary">
                                {{ $u->serial }}
                            </div>
                            <div class="small text-secondary">
                                {{ $u->created_at?->format('d/m/Y') }}
                            </div>
                        </td>
                        <td>
                            {{ $u->product->name ?? '' }}
                        </td>
                        <td class="small">
                            {{ $u->store->name ?? '' }}
                        </td>
                        <td>
                            {{ $u->condition }}
                        </td>
                        <td>
                            <span class="badge bg-{{ $u->status === 'tersedia' ? 'green' : ($u->status === 'terjual' ? 'secondary' : 'yellow') }}-lt">
                                {{ $u->status }}
                            </span>
                        </td>
                        <td>
                            <span class="badge {{ $ageBadge }}">
                                {{ $ageDays }} hari
                            </span>
                            @if ($ageDays > 180)
                                <div class="small text-danger">
                                    Stok sangat lama
                                </div>
                            @elseif ($ageDays > 90)
                                <div class="small text-warning">
                                    Stok lama
                                </div>
                            @endif
                        </td>
                        <td class="text-end">
                            {{ number_format($u->selling_price, 0, ',', '.') }}
                        </td>
                        <td>
    <div class="d-flex gap-1 flex-wrap">
        <button
            type="button"
            class="btn btn-sm btn-outline-info"
            onclick="showTimeline({{ $u->id }})"
        >Riwayat</button>
        <button
            type="button"
            class="btn btn-sm btn-outline-primary"
            data-produk="{{ $u->product->name ?? '-' }}"
            data-imei="{{ $u->imei ?: ($u->serial ?: ('UNIT-' . $u->id)) }}"
            data-serial="{{ $u->serial ?? '' }}"
            data-harga="Rp {{ number_format($u->selling_price, 0, ',', '.') }}"
            data-toko="{{ $u->store->name ?? '' }}"
            onclick="cetakLabelUnit(this)"
        >Cetak Label</button>
        @if($u->status === 'tersedia' && in_array(auth()->user()->role ?? '', ['superadmin', 'owner', 'admin', 'gudang']))
            <button
                type="button"
                class="btn btn-sm btn-outline-warning"
                data-unit-id="{{ $u->id }}"
                data-unit-label="{{ ($u->imei ?: ($u->serial ?: ('UNIT-' . $u->id))) . ' — ' . ($u->product->name ?? '-') }}"
                onclick="openMoveUnit(this)"
            >Pindah</button>
        @endif
    </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9">
                            <div class="empty py-3">
                                <div class="empty-icon">
                                    <i class="ti ti-barcode fs-1 text-secondary"></i>
                                </div>
                                <p class="empty-title">
                                    Tidak ada unit
                                </p>
                                <p class="empty-subtitle text-secondary">
                                    Tambahkan unit lewat formulir di samping — isi produk, IMEI & harga lalu Simpan Unit.
                                </p>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer">
        {{ $units->links() }}
    </div>
</div></div></div>
<div class="modal fade" id="tlModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Riwayat Unit / IMEI</h5>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="tlBody">
                <div class="text-secondary">Memuat…</div>
            </div>
        </div>
    </div>
</div>
@if(in_array(auth()->user()->role ?? '', ['superadmin', 'owner', 'admin', 'gudang']))
    <div class="modal fade" id="moveUnitModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="{{ route('stocks.mutate') }}">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title">Pindah Unit 1-Klik</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-2">
                            Unit:
                            <strong id="moveUnitLabel" class="font-monospace">-</strong>
                        </p>
                        <p class="small text-secondary mb-3">
                            Unit pindah toko + tercatat 2 movement
                            (keluar di toko asal, masuk di toko tujuan).
                            Hanya unit <span class="badge bg-green-lt">tersedia</span> yang bisa dipindah.
                        </p>
                        <input type="hidden" name="unit_ids[]" id="moveUnitId" value="">
                        <label class="form-label">Toko tujuan</label>
                        <select name="to_store_id" class="form-select" required>
                            @forelse(($moveStores ?? collect()) as $s)
                                <option value="{{ $s->id }}">{{ $s->name }}</option>
                            @empty
                                <option value="" disabled>Tidak ada toko tujuan aktif</option>
                            @endforelse
                        </select>
                        <label class="form-label mt-3">Catatan (opsional)</label>
                        <input name="note" class="form-control" maxlength="500" placeholder="Mutasi unit antar toko">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button
                            type="submit"
                            class="btn btn-warning"
                            onclick="return confirm('Pindahkan unit ini ke toko tujuan?')"
                        >
                            Pindahkan Unit
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endif
<div id="labelSheet" class="d-none">
    <div id="labelGrid" class="label-grid"></div>
</div>
<style>
.label-grid {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 4mm;
}
.label-cell {
    border: 1px dashed #aaa;
    padding: 4mm;
    text-align: center;
    width: 52mm;
    flex: 0 0 auto;
}
.label-cell .lbl-produk {
    font-weight: 700;
    font-size: 12px;
    line-height: 1.25;
    word-break: break-word;
}
.label-cell .lbl-harga {
    font-size: 12px;
    margin-top: 1mm;
}
.label-cell .lbl-toko {
    font-size: 10px;
    color: #555;
}
.label-cell .lbl-imei {
    font-family: monospace;
    font-size: 10px;
    margin-top: 1mm;
}
@media print {
    body * {
        visibility: hidden;
    }
    #labelSheet,
    #labelSheet * {
        visibility: visible;
    }
    #labelSheet {
        display: block !important;
        position: absolute;
        inset: 0;
        padding: 5mm;
    }
    .no-print,
    .page-header,
    .card,
    .modal,
    form {
        display: none !important;
    }
    #labelSheet.card {
        display: block !important;
    }
    .label-grid {
        display: flex !important;
        flex-wrap: wrap;
        justify-content: center;
        gap: 4mm;
    }
    .label-cell {
        border: 1px dashed #888;
        page-break-inside: avoid;
    }
}
</style>
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<script>
function buildLabelCell(data) {
    const produk = data.produk || '-';
    const imei = (data.imei || '').trim() || '-';
    const harga = data.harga || '';
    const toko = data.toko || '';
    const cell = document.createElement('div');
    cell.className = 'label-cell';
    const elProduk = document.createElement('div');
    elProduk.className = 'lbl-produk';
    elProduk.textContent = produk;
    const elHarga = document.createElement('div');
    elHarga.className = 'lbl-harga';
    elHarga.textContent = harga;
    const elToko = document.createElement('div');
    elToko.className = 'lbl-toko';
    elToko.textContent = toko;
    const elSvg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    elSvg.setAttribute('class', 'lbl-bc');
    const elImei = document.createElement('div');
    elImei.className = 'lbl-imei';
    elImei.textContent = imei;
    cell.appendChild(elProduk);
    cell.appendChild(elHarga);
    cell.appendChild(elSvg);
    cell.appendChild(elImei);
    cell.appendChild(elToko);
    return { cell, svg: elSvg, imei };
}
function renderBarcode(svg, imei) {
    try {
        JsBarcode(svg, imei, {
            format: 'CODE128',
            height: 42,
            width: 1.4,
            displayValue: false,
            margin: 2
        });
    } catch (e) {
        svg.outerHTML = '<div class="small text-danger">Barcode tidak valid: ' + imei + '</div>';
    }
}
function cetakLabelUnit(btn) {
    const grid = document.getElementById('labelGrid');
    grid.innerHTML = '';
    const built = buildLabelCell(btn.dataset);
    grid.appendChild(built.cell);
    renderBarcode(built.svg, built.imei);
    document.getElementById('labelSheet').classList.remove('d-none');
    document.getElementById('labelSheet').scrollIntoView();
    setTimeout(function () { window.print(); }, 300);
}
function updateCetakCount() {
    const n = document.querySelectorAll('.check-unit:checked').length;
    const el = document.getElementById('cetakCount');
    if (el) el.textContent = n;
    const master = document.getElementById('checkSemua');
    if (master) {
        const all = document.querySelectorAll('.check-unit');
        master.checked = all.length > 0 && n === all.length;
        master.indeterminate = n > 0 && n < all.length;
    }
}
function togglePilihSemua(centang) {
    document.querySelectorAll('.check-unit').forEach(function (c) {
        c.checked = !!centang;
    });
    updateCetakCount();
}
function cetakLabelTerpilih() {
    const checked = Array.from(document.querySelectorAll('.check-unit:checked'));
    if (!checked.length) {
        alert('Centang minimal 1 unit dulu.');
        return;
    }
    const grid = document.getElementById('labelGrid');
    grid.innerHTML = '';
    checked.forEach(function (box) {
        const built = buildLabelCell(box.dataset);
        grid.appendChild(built.cell);
        renderBarcode(built.svg, built.imei);
    });
    document.getElementById('labelSheet').classList.remove('d-none');
    document.getElementById('labelSheet').scrollIntoView();
    setTimeout(function () { window.print(); }, 400);
}
function openMoveUnit(btn) {
    const id = btn.dataset.unitId;
    const label = btn.dataset.unitLabel || ('UNIT-' + id);
    document.getElementById('moveUnitId').value = id;
    document.getElementById('moveUnitLabel').textContent = label;
    new bootstrap.Modal(document.getElementById('moveUnitModal')).show();
}
function showTimeline(id){
  const body = document.getElementById('tlBody');
  body.innerHTML = '<div class="text-secondary">Memuat…</div>';
  new bootstrap.Modal(document.getElementById('tlModal')).show();
  fetch('{{ url('/units') }}/' + id + '/timeline').then(r => r.json()).then(d => {    const u = d.unit;
    let h = '<div class="card mb-2"><div class="card-body"><b>' + (u.imei || '-') + '</b> — ' + u.produk
      + '<div class="small text-secondary">Toko: ' + u.toko + ' • Kondisi: ' + u.kondisi + ' • Status: ' + u.status
      + '<br>Beli Rp ' + Number(u.beli).toLocaleString('id-ID') + ' • Jual Rp ' + Number(u.jual).toLocaleString('id-ID')
      + ' • Supplier: ' + u.supplier + ' • Masuk: ' + u.masuk + '</div></div></div>';
    const sec = (t, rows, cols) => {
      h += '<h4 class="mt-3">' + t + ' (' + rows.length + ')</h4>';
      if(!rows.length){ h += '<div class="text-secondary small">— kosong —</div>'; return; }
      h += '<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr>' + cols.map(c => '<th>' + c + '</th>').join('') + '</tr></thead><tbody>';
      rows.forEach(r => { h += '<tr>' + cols.map(c => '<td class="small">' + (r[c] ?? '') + '</td>').join('') + '</tr>'; });
      h += '</tbody></table></div>';
    };
    sec('Asal pembelian', d.origins.map(o => ({Waktu:o.waktu, Sumber:o.sumber, Detail:o.detail})), ['Waktu','Sumber','Detail']);
    sec('Mutasi stok', d.movements.map(m => ({Waktu:m.waktu, Tipe:m.tipe, Toko:m.toko, Dari:m.dari, Ke:m.ke, Catatan:(m.catatan||'') + (m.oleh ? ' • '+m.oleh : '')})), ['Waktu','Tipe','Toko','Dari','Ke','Catatan']);
    sec('Penjualan', d.sales.map(s => ({Waktu:s.waktu, Invoice:s.invoice, Pelanggan:s.pelanggan, Harga:Number(s.harga).toLocaleString('id-ID'), Qty:s.qty})), ['Waktu','Invoice','Pelanggan','Harga','Qty']);
    sec('Service (by IMEI)', d.services.map(s => ({Waktu:s.waktu, Tiket:s.tiket, Keluhan:s.keluhan, Status:s.status, Pelanggan:s.pelanggan, Biaya:Number(s.biaya).toLocaleString('id-ID')})), ['Waktu','Tiket','Keluhan','Status','Pelanggan','Biaya']);
    body.innerHTML = h;
  }).catch(() => body.innerHTML = '<div class="alert alert-danger">Gagal memuat riwayat.</div>');
}
</script>
@endsection
