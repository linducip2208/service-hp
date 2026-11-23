@extends('layouts.tabler')
@section('title', 'Konsinyasi')
@section('content')
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <div class="page-pretitle">Penjualan</div>
            <h2 class="page-title">Titipan / Konsinyasi</h2>
        </div>
        <div class="col-auto ms-auto d-flex gap-2">
            <a
                href="{{ route('consignments.index', array_merge(request()->query(), ['export' => 'csv'])) }}"
                class="btn btn-outline-success"
            >
                <i class="ti ti-download me-1"></i> Export CSV
            </a>
        </div>
    </div>
</div>
<div class="row g-3">
<div class="col-lg-4"><div class="card"><div class="card-header"><h3 class="card-title">Titipan Baru</h3></div><div class="card-body"><form method="POST" action="{{ route('consignments.store') }}" enctype="multipart/form-data" onsubmit="return confirm('Simpan titipan ini?')">@csrf
<label class="form-label">Supplier penitip</label><select name="supplier_id" class="form-select"><option value="">— perorangan —</option>@foreach($suppliers as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select>
<label class="form-label mt-2">Nama penitip (perorangan)</label><input name="customer_name" class="form-control">
<label class="form-label mt-2">Produk</label><select name="product_id" class="form-select" required>@foreach($products as $p)<option value="{{ $p->id }}">{{ $p->name }} — Rp {{ number_format($p->selling_price,0,',','.') }}</option>@endforeach</select>
<label class="form-label mt-2">IMEI (bila serialized)</label><input name="imei" class="form-control font-monospace">
<label class="form-label mt-2">Foto Titipan (maks 2, tiap max 2 MB)</label>
<input
    type="file"
    name="photos[]"
    class="form-control"
    multiple
    accept="image/*"
>
<div class="form-text">Foto tersimpan di storage + path dicatat [FOTO:path] pada mutasi stok titipan ini.</div>
<div class="row g-2 mt-2"><div class="col-4"><label class="form-label">Harga titip</label><input name="titip_price" type="number" min="0" value="0" class="form-control" required></div>
<div class="col-4"><label class="form-label">Harga jual</label><input name="jual_price" type="number" min="0" value="0" class="form-control" required></div>
<div class="col-4"><label class="form-label">Komisi</label><input name="commission" type="number" min="0" value="0" class="form-control"></div></div>
<button class="btn btn-primary w-100 mt-3">Simpan Titipan</button></form></div></div></div>
<div class="col-lg-8"><div class="card mb-3"><div class="card-body"><div class="row g-2">
<div class="col-md-3"><div class="card bg-green-lt"><div class="card-body p-2"><div class="small text-secondary">Terjual ({{ $margin['count'] }})</div><b>Rp {{ number_format($margin['jual'],0,',','.') }}</b></div></div></div>
<div class="col-md-3"><div class="card"><div class="card-body p-2"><div class="small text-secondary">Hak penitip</div><b>Rp {{ number_format($margin['titip'],0,',','.') }}</b></div></div></div>
<div class="col-md-3"><div class="card"><div class="card-body p-2"><div class="small text-secondary">Komisi</div><b>Rp {{ number_format($margin['komisi'],0,',','.') }}</b></div></div></div>
<div class="col-md-3"><div class="card bg-blue-lt"><div class="card-body p-2"><div class="small text-secondary">Margin toko</div><b>Rp {{ number_format($margin['toko'],0,',','.') }}</b></div></div></div>
</div><form method="GET" class="row g-2 align-items-end mt-2">
<div class="col-auto"><label class="form-label">Dari</label><input type="date" name="from" value="{{ $from }}" class="form-control"></div>
<div class="col-auto"><label class="form-label">Sampai</label><input type="date" name="to" value="{{ $to }}" class="form-control"></div>
<div class="col-auto"><label class="form-label">Status</label><select name="status" class="form-select"><option value="">Semua</option>@foreach(['aktif','terjual','kembali'] as $s)<option value="{{ $s }}" {{ request('status')===$s?'selected':'' }}>{{ $s }}</option>@endforeach</select></div>
<div class="col-auto"><button class="btn btn-secondary">Filter</button></div>
<div class="col small text-secondary align-self-end">Margin per periode (terjual by tgl update).</div></form></div></div>
<div class="card">
<div class="card-body border-bottom d-print-none">
    <form
        id="bulkTitipBar"
        class="d-flex flex-wrap align-items-end gap-2"
        onsubmit="return false;"
    >
        <button
            type="button"
            class="btn btn-sm btn-outline-primary"
            id="printBulkTitipBtn"
        >
            <i class="ti ti-printer me-1"></i> Cetak Bukti Titip Terpilih (maks 50)
        </button>
        <span class="form-hint mt-1 w-100">
            Centang baris tabel lalu cetak — bukti titip terpilih digabung dalam 1 halaman cetak (1 bukti per halaman).
        </span>
    </form>
</div>
<div class="table-responsive"><table class="table table-hover mb-0">
<thead>
    <tr>
        <th class="w-1 d-print-none">
            <input
                type="checkbox"
                class="form-check-input"
                id="checkAllTitip"
                title="Pilih semua di halaman ini"
            >
        </th>
        <th>Kode</th>
        <th>Produk / IMEI</th>
        <th>Penitip</th>
        <th class="text-end">Titip / Jual / Komisi</th>
        <th>Status</th>
        <th></th>
    </tr>
</thead>
<tbody>
@forelse($consignments as $c)
    <tr>
        <td class="d-print-none">
            <input
                type="checkbox"
                class="form-check-input titip-check"
                value="{{ $c->id }}"
            >
        </td>
        <td class="font-monospace small">{{ $c->code }}</td>
<td>
    {{ $c->product->name ?? '' }}
    <div class="small font-monospace text-secondary">{{ $c->productItem->imei ?? '' }}</div>
    @php($fotosTitip = $photosByConsignment[$c->id] ?? [])
    @if(!empty($fotosTitip))
        <div class="d-flex gap-1 mt-1">
            @foreach($fotosTitip as $fp)
                <a href="{{ asset('storage/'.$fp) }}" target="_blank">
                    <img
                        src="{{ asset('storage/'.$fp) }}"
                        class="rounded"
                        style="height:40px;width:40px;object-fit:cover"
                        alt="Foto titipan"
                    >
                </a>
            @endforeach
        </div>
    @endif
</td>
<td class="small">{{ $c->supplier->name ?? $c->customer_name ?? '-' }}</td>
<td class="text-end small">{{ number_format($c->titip_price,0,',','.') }} / {{ number_format($c->jual_price,0,',','.') }} / {{ number_format($c->commission,0,',','.') }}</td>
<td><span class="badge bg-{{ $c->status==='terjual'?'green':($c->status==='kembali'?'red':'yellow') }}-lt">{{ $c->status }}</span>
@if(in_array($c->id, $settledIds ?? []))<span class="badge bg-green-lt">lunas</span>@endif</td>
<td class="text-nowrap"><form method="POST" action="{{ route('consignments.update',$c) }}" class="d-inline-flex gap-1" onsubmit="return confirm('Ubah status titipan ini?')">@csrf @method('PUT')
<select name="status" class="form-select form-select-sm"><option value="aktif" {{ $c->status==='aktif'?'selected':'' }}>aktif</option><option value="terjual" {{ $c->status==='terjual'?'selected':'' }}>terjual</option><option value="kembali" {{ $c->status==='kembali'?'selected':'' }}>kembali</option></select>
<button class="btn btn-sm btn-outline-primary">OK</button></form>
@if($c->status !== 'kembali' && !in_array($c->id, $settledIds ?? []))
    <button
        class="btn btn-sm btn-success mt-1"
        onclick="settleModal({{ $c->id }},'{{ $c->code }}',{{ (float) $c->jual_price }},{{ (float) $c->commission }})"
    >
        Settle
    </button>
@endif
@if($c->status === 'terjual' || in_array($c->id, $settledIds ?? []))
    <button
        type="button"
        class="btn btn-sm btn-outline-secondary mt-1"
        data-code="{{ $c->code }}"
        data-product="{{ $c->product->name ?? '' }}"
        data-imei="{{ $c->productItem->imei ?? '' }}"
        data-mitra="{{ $c->supplier->name ?? $c->customer_name ?? '-' }}"
        data-titip="{{ (float) $c->titip_price }}"
        data-jual="{{ (float) $c->jual_price }}"
        data-komisi="{{ (float) $c->commission }}"
        data-tanggal="{{ $c->updated_at?->format('d/m/Y H:i') ?? '-' }}"
        data-toko="{{ $c->store->name ?? '' }}"
        data-bukti="{{ $settleBuktiByConsignment[$c->id] ?? '' }}"
        onclick="settleReceipt(this)"
    >
        <i class="ti ti-receipt me-1"></i> Kwitansi
    </button>
@endif
<button
    type="button"
    class="btn btn-sm btn-outline-primary mt-1 titip-btn"
    data-code="{{ $c->code }}"
    data-product="{{ $c->product->name ?? '' }}"
    data-imei="{{ $c->productItem->imei ?? '' }}"
    data-mitra="{{ $c->supplier->name ?? $c->customer_name ?? '-' }}"
    data-titip="{{ (float) $c->titip_price }}"
    data-jual="{{ (float) $c->jual_price }}"
    data-tanggal="{{ $c->created_at?->format('d/m/Y H:i') ?? '-' }}"
    data-toko="{{ $c->store->name ?? '' }}"
    data-fotos="{{ implode('|', $photosByConsignment[$c->id] ?? []) }}"
    onclick="titipReceipt(this)"
>
    <i class="ti ti-receipt me-1"></i> Bukti Titip
</button>
</td></tr>
@empty
    <tr>
        <td colspan="7">
            <div class="empty py-3"><div class="empty-icon"><i class="ti ti-archive fs-1 text-secondary"></i></div><p class="empty-title">Belum ada titipan</p><p class="empty-subtitle text-secondary">Catat lewat formulir Titipan Baru — settle & bagi hasil dilakukan dari tabel ini.</p></div></td></tr>@endforelse</tbody></table></div>
<div class="card-footer">{{ $consignments->links() }}</div></div>
<div class="modal fade" id="settleModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
<div class="modal-header"><h5 class="modal-title">Settle <span id="stCode"></span></h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
<form
    id="stForm"
    method="POST"
    enctype="multipart/form-data"
    onsubmit="return confirm('Settle & bayar ke penitip? (kas keluar)')"
>
<div class="modal-body">@csrf
<div class="alert alert-info small">Kosongkan komisi = auto 10% dari margin (jual − titip); isi 0 = tanpa komisi. Kas keluar ke penitip = titip + komisi; status jadi terjual + badge lunas.</div>
<label class="form-label">Harga jual aktual</label><input id="stJual" name="jual_price" type="number" min="0" class="form-control" required>
<label class="form-label mt-2">Komisi mitra (Rp, kosongkan = auto 10% margin)</label><input id="stKom" name="commission" type="number" min="0" class="form-control" placeholder="auto">
<label class="form-label mt-2">Akun kas (keluar)</label><select name="cash_account_id" class="form-select" required>@foreach($cashAccounts as $a)<option value="{{ $a->id }}">{{ $a->name }} (Rp {{ number_format($a->balance,0,',','.') }})</option>@endforeach</select>
<label class="form-label mt-2">Bukti settle (opsional, maks 2 MB)</label>
<input
    type="file"
    name="bukti"
    class="form-control"
    accept="image/*"
>
<div class="form-text">Foto bukti bayar ke penitip (transfer/kwitansi). Tersimpan di storage + path dicatat [BUKTI:path] pada kas keluar settle ini.</div>
</div><div class="modal-footer"><button class="btn btn-success">Settle & Bayar Mitra</button></div></form>
</div></div></div>
<div class="modal fade" id="settleReceiptModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header d-print-none">
                <h5 class="modal-title">
                    Bukti Settle <span id="srCodeTitle"></span>
                </h5>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="settleReceiptPrint">
                    <div class="text-center mb-3">
                        <div class="fw-bold fs-3">BUKTI SETTLE KONSINYASI</div>
                        <div class="font-monospace text-secondary" id="srCode"></div>
                        <div class="small text-secondary" id="srToko"></div>
                    </div>
                    <table class="table table-sm table-bordered mb-3">
                        <tbody>
                            <tr>
                                <th class="w-25">No. Bukti</th>
                                <td class="font-monospace" id="srCodeRow"></td>
                            </tr>
                            <tr>
                                <th>Mitra Penitip</th>
                                <td id="srMitra"></td>
                            </tr>
                            <tr>
                                <th>Produk</th>
                                <td>
                                    <span id="srProduk"></span>
                                    <div class="small font-monospace text-secondary" id="srImei"></div>
                                </td>
                            </tr>
                            <tr>
                                <th>Harga Titip</th>
                                <td id="srTitip"></td>
                            </tr>
                            <tr>
                                <th>Harga Jual</th>
                                <td id="srJual"></td>
                            </tr>
                            <tr>
                                <th>Komisi Mitra</th>
                                <td id="srKomisi"></td>
                            </tr>
                            <tr>
                                <th>Diterima Mitra</th>
                                <td>
                                    <b id="srDiterima"></b>
                                    <span class="badge bg-green-lt ms-1">LUNAS</span>
                                </td>
                            </tr>
                            <tr>
                                <th>Tanggal Settle</th>
                                <td id="srTanggal"></td>
                            </tr>
                            <tr id="srBuktiRow" style="display:none">
                                <th>Bukti Settle</th>
                                <td>
                                    <a
                                        id="srBuktiLink"
                                        href="#"
                                        target="_blank"
                                    >
                                        <img
                                            id="srBuktiImg"
                                            src=""
                                            class="rounded border"
                                            style="max-height:220px;max-width:100%;object-fit:contain"
                                            alt="Bukti settle"
                                        >
                                    </a>
                                    <div class="small text-secondary mt-1">Klik gambar untuk perbesar.</div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div class="row mt-4 text-center">
                        <div class="col-6">
                            <div class="small text-secondary">Kasir / Toko,</div>
                            <div style="height: 64px;"></div>
                            <div>( .............................. )</div>
                        </div>
                        <div class="col-6">
                            <div class="small text-secondary">Mitra Penerima,</div>
                            <div style="height: 64px;"></div>
                            <div>( .............................. )</div>
                        </div>
                    </div>
                    <p class="small text-secondary mt-3 mb-0">
                        Diterima mitra = harga titip + komisi.
                        Simpan sebagai arsip serah terima settle konsinyasi.
                    </p>
                </div>
            </div>
            <div class="modal-footer d-print-none">
                <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Tutup</button>
                <button class="btn btn-success" onclick="window.print()"><i class="ti ti-printer me-1"></i> Cetak</button>
            </div>
        </div>
    </div>
</div>
<div
    class="modal fade"
    id="titipReceiptModal"
    tabindex="-1"
>
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header d-print-none">
                <h5 class="modal-title">
                    Bukti Titip <span id="trCodeTitle"></span>
                </h5>
                <button
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>
            </div>
            <div class="modal-body">
                <div id="titipReceiptPrint">
                    <div class="text-center mb-3">
                        <div class="fw-bold fs-3">BUKTI TITIP KONSINYASI</div>
                        <div
                            class="font-monospace text-secondary"
                            id="trCode"
                        ></div>
                        <div
                            class="small text-secondary"
                            id="trToko"
                        ></div>
                    </div>
                    <table class="table table-sm table-bordered mb-3">
                        <tbody>
                            <tr>
                                <th class="w-25">No. Bukti</th>
                                <td
                                    class="font-monospace"
                                    id="trCodeRow"
                                ></td>
                            </tr>
                            <tr>
                                <th>Mitra Penitip</th>
                                <td id="trMitra"></td>
                            </tr>
                            <tr>
                                <th>Barang</th>
                                <td>
                                    <span id="trProduk"></span>
                                    <div
                                        class="small font-monospace text-secondary"
                                        id="trImei"
                                    ></div>
                                </td>
                            </tr>
                            <tr>
                                <th>Harga Titip</th>
                                <td id="trTitip"></td>
                            </tr>
                            <tr>
                                <th>Harga Jual Acuan</th>
                                <td id="trJual"></td>
                            </tr>
                            <tr>
                                <th>Tanggal Titip</th>
                                <td id="trTanggal"></td>
                            </tr>
                            <tr
                                id="trFotoRow"
                                style="display:none"
                            >
                                <th>Foto Barang</th>
                                <td>
                                    <div
                                        id="trFotos"
                                        class="d-flex gap-2 flex-wrap"
                                    ></div>
                                    <div class="small text-secondary mt-1">
                                        Kondisi barang sesuai foto terlampir.
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div class="small text-secondary mb-1">Syarat singkat:</div>
                    <ol class="small text-secondary ps-3 mb-3">
                        <li>Harga titip = hak penitip saat barang terjual.</li>
                        <li>Pengambilan kembali hanya bila status masih aktif &amp; barang belum terjual.</li>
                        <li>Komisi / bagi hasil diselesaikan saat settle.</li>
                    </ol>
                    <div class="row mt-4 text-center">
                        <div class="col-6">
                            <div class="small text-secondary">Penitip,</div>
                            <div style="height: 64px;"></div>
                            <div>( .............................. )</div>
                        </div>
                        <div class="col-6">
                            <div class="small text-secondary">Penerima / Toko,</div>
                            <div style="height: 64px;"></div>
                            <div>( .............................. )</div>
                        </div>
                    </div>
                    <p class="small text-secondary mt-3 mb-0">
                        Simpan sebagai arsip serah terima titipan konsinyasi.
                    </p>
                </div>
            </div>
            <div class="modal-footer d-print-none">
                <button
                    class="btn btn-outline-secondary"
                    data-bs-dismiss="modal"
                >Tutup</button>
                <button
                    class="btn btn-primary"
                    onclick="window.print()"
                ><i class="ti ti-printer me-1"></i> Cetak</button>
            </div>
        </div>
    </div>
</div>
<div
    id="bulkTitipPrint"
    style="display:none"
></div>
<style>
#bulkTitipPrint .titip-bulk-block {
    border: 1px solid #dee2e6;
    border-radius: 6px;
    padding: 18px;
    margin-bottom: 18px;
}
@media print {
    body * {
        visibility: hidden;
    }
    #settleReceiptPrint,
    #settleReceiptPrint *,
    #titipReceiptPrint,
    #titipReceiptPrint *,
    #bulkTitipPrint,
    #bulkTitipPrint * {
        visibility: visible;
    }
    #settleReceiptPrint,
    #titipReceiptPrint {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
    }
    #bulkTitipPrint {
        display: block !important;
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
    }
    #bulkTitipPrint .titip-bulk-block {
        border: none;
        border-radius: 0;
        padding: 0;
        margin-bottom: 0;
        page-break-after: always;
    }
    #bulkTitipPrint .titip-bulk-block:last-child {
        page-break-after: auto;
    }
    @page {
        size: A4;
        margin: 12mm;
    }
}
</style>
<script>
var titipStorageBase = "{{ asset('storage') }}/";
document.getElementById('checkAllTitip')?.addEventListener('change', function () {
    var checked = document.getElementById('checkAllTitip').checked;
    document.querySelectorAll('.titip-check').forEach(function (cb) {
        cb.checked = checked;
    });
});
function escBulkTitip(s) {
    return String(s ?? '').replace(/[&<>"']/g, function (ch) {
        return {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        }[ch];
    });
}
function titipBulkBlock(d, idx, total) {
    var fotos = String(d.fotos || '')
        .split('|')
        .map(function (p) { return (p || '').trim(); })
        .filter(Boolean)
        .slice(0, 2);
    var fotoHtml = '';
    if (fotos.length) {
        fotoHtml = '<tr>'
            + '<th>Foto Barang</th>'
            + '<td><div class="d-flex gap-2 flex-wrap">';
        fotos.forEach(function (p) {
            var url = titipStorageBase + p;
            fotoHtml += '<img'
                + ' src="' + escBulkTitip(url) + '"'
                + ' class="rounded border"'
                + ' style="max-height:220px;max-width:100%;object-fit:contain"'
                + ' alt="Foto titipan">';
        });
        fotoHtml += '</div>'
            + '<div class="small text-secondary mt-1">'
            + 'Kondisi barang sesuai foto terlampir.'
            + '</div></td></tr>';
    }
    var imeiHtml = d.imei
        ? '<div class="small font-monospace text-secondary">IMEI: ' + escBulkTitip(d.imei) + '</div>'
        : '';
    return '<section class="titip-bulk-block">'
        + '<div class="text-center mb-3">'
        + '<div class="fw-bold fs-3">BUKTI TITIP KONSINYASI</div>'
        + '<div class="font-monospace text-secondary">' + escBulkTitip(d.code) + '</div>'
        + '<div class="small text-secondary">' + escBulkTitip(d.toko) + '</div>'
        + '<div class="mt-1"><span class="badge bg-blue-lt">' + (idx + 1) + ' / ' + total + '</span></div>'
        + '</div>'
        + '<table class="table table-sm table-bordered mb-3"><tbody>'
        + '<tr><th class="w-25">No. Bukti</th>'
        + '<td class="font-monospace">' + escBulkTitip(d.code) + '</td></tr>'
        + '<tr><th>Mitra Penitip</th><td>' + escBulkTitip(d.mitra || '-') + '</td></tr>'
        + '<tr><th>Barang</th><td>' + escBulkTitip(d.product || '-') + imeiHtml + '</td></tr>'
        + '<tr><th>Harga Titip</th><td>' + escBulkTitip(rupiah(d.titip || 0)) + '</td></tr>'
        + '<tr><th>Harga Jual Acuan</th><td>' + escBulkTitip(rupiah(d.jual || 0)) + '</td></tr>'
        + '<tr><th>Tanggal Titip</th><td>' + escBulkTitip(d.tanggal || '-') + '</td></tr>'
        + fotoHtml
        + '</tbody></table>'
        + '<div class="small text-secondary mb-1">Syarat singkat:</div>'
        + '<ol class="small text-secondary ps-3 mb-3">'
        + '<li>Harga titip = hak penitip saat barang terjual.</li>'
        + '<li>Pengambilan kembali hanya bila status masih aktif &amp; barang belum terjual.</li>'
        + '<li>Komisi / bagi hasil diselesaikan saat settle.</li>'
        + '</ol>'
        + '<div class="row mt-4 text-center">'
        + '<div class="col-6">'
        + '<div class="small text-secondary">Penitip,</div>'
        + '<div style="height: 64px;"></div>'
        + '<div>( .............................. )</div>'
        + '</div>'
        + '<div class="col-6">'
        + '<div class="small text-secondary">Penerima / Toko,</div>'
        + '<div style="height: 64px;"></div>'
        + '<div>( .............................. )</div>'
        + '</div>'
        + '</div>'
        + '<p class="small text-secondary mt-3 mb-0">'
        + 'Simpan sebagai arsip serah terima titipan konsinyasi.'
        + '</p>'
        + '</section>';
}
document.getElementById('printBulkTitipBtn')?.addEventListener('click', function () {
    var checked = Array.from(document.querySelectorAll('.titip-check:checked'));
    if (!checked.length) {
        alert('Pilih minimal 1 titipan dulu (centang baris tabel).');
        return;
    }
    if (checked.length > 50) {
        alert('Maksimal 50 bukti titip per cetak massal. Kurangi pilihan Anda.');
        return;
    }
    var rows = [];
    checked.forEach(function (cb) {
        var tr = cb.closest('tr');
        var btn = tr ? tr.querySelector('.titip-btn') : null;
        if (btn && btn.dataset) {
            rows.push(btn.dataset);
        }
    });
    if (!rows.length) {
        alert('Data baris terpilih tidak terbaca. Muat ulang halaman lalu coba lagi.');
        return;
    }
    var box = document.getElementById('bulkTitipPrint');
    box.innerHTML = rows
        .map(function (d, i) { return titipBulkBlock(d, i, rows.length); })
        .join('');
    window.print();
});
function settleModal(id, code, jual, kom){
  document.getElementById('stCode').textContent = code;
  document.getElementById('stJual').value = jual;
  document.getElementById('stKom').value = kom || '';
  document.getElementById('stForm').action = '{{ url('/consignments') }}/' + id + '/settle';
  new bootstrap.Modal(document.getElementById('settleModal')).show();
}
function rupiah(n) {
  return 'Rp ' + Number(n || 0).toLocaleString('id-ID', { maximumFractionDigits: 0 });
}
function settleReceipt(btn) {
  var d = btn.dataset || {};
  var titip = parseFloat(d.titip || 0);
  var jual = parseFloat(d.jual || 0);
  var komisi = parseFloat(d.komisi || 0);
  document.getElementById('srCodeTitle').textContent = d.code || '';
  document.getElementById('srCode').textContent = d.code || '';
  document.getElementById('srCodeRow').textContent = d.code || '';
  document.getElementById('srToko').textContent = d.toko || '';
  document.getElementById('srMitra').textContent = d.mitra || '-';
  document.getElementById('srProduk').textContent = d.product || '-';
  document.getElementById('srImei').textContent = d.imei || '';
  document.getElementById('srTitip').textContent = rupiah(titip);
  document.getElementById('srJual').textContent = rupiah(jual);
  document.getElementById('srKomisi').textContent = rupiah(komisi);
  document.getElementById('srDiterima').textContent = rupiah(titip + komisi);
  document.getElementById('srTanggal').textContent = d.tanggal || '-';
  var bukti = (d.bukti || '').trim();
  var row = document.getElementById('srBuktiRow');
  if (row) {
    if (bukti) {
      var url = "{{ asset('storage') }}/" + bukti;
      var img = document.getElementById('srBuktiImg');
      var link = document.getElementById('srBuktiLink');
      if (img) img.src = url;
      if (link) link.href = url;
      row.style.display = '';
    } else {
      row.style.display = 'none';
    }
  }
  new bootstrap.Modal(document.getElementById('settleReceiptModal')).show();
}
function titipReceipt(btn) {
  var d = btn.dataset || {};
  document.getElementById('trCodeTitle').textContent = d.code || '';
  document.getElementById('trCode').textContent = d.code || '';
  document.getElementById('trCodeRow').textContent = d.code || '';
  document.getElementById('trToko').textContent = d.toko || '';
  document.getElementById('trMitra').textContent = d.mitra || '-';
  document.getElementById('trProduk').textContent = d.product || '-';
  document.getElementById('trImei').textContent = d.imei ? ('IMEI: ' + d.imei) : '';
  document.getElementById('trTitip').textContent = rupiah(d.titip || 0);
  document.getElementById('trJual').textContent = rupiah(d.jual || 0);
  document.getElementById('trTanggal').textContent = d.tanggal || '-';
  var fotos = (d.fotos || '').split('|').map(function (p) { return (p || '').trim(); }).filter(Boolean);
  var fotoRow = document.getElementById('trFotoRow');
  var fotoBox = document.getElementById('trFotos');
  if (fotoBox) fotoBox.innerHTML = '';
  if (fotoRow) {
    if (fotos.length && fotoBox) {
      var base = "{{ asset('storage') }}/";
      fotos.slice(0, 2).forEach(function (p) {
        var url = base + p;
        var a = document.createElement('a');
        a.href = url;
        a.target = '_blank';
        var img = document.createElement('img');
        img.src = url;
        img.alt = 'Foto titipan';
        img.className = 'rounded border';
        img.style.cssText = 'max-height:220px;max-width:100%;object-fit:contain';
        a.appendChild(img);
        fotoBox.appendChild(a);
      });
      fotoRow.style.display = '';
    } else {
      fotoRow.style.display = 'none';
    }
  }
  new bootstrap.Modal(document.getElementById('titipReceiptModal')).show();
}
</script></div></div>
@endsection
