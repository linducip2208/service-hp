@extends('layouts.tabler')
@section('title', 'Beli HP Bekas')
@section('content')
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <div class="page-pretitle">Penjualan</div>
            <h2 class="page-title">Beli HP Bekas dari Pelanggan</h2>
        </div>
        <div class="col-auto ms-auto d-flex gap-2">
            <a
                href="{{ route('secondhand.index', array_merge(request()->query(), ['export' => 'csv'])) }}"
                class="btn btn-outline-success"
            >
                <i class="ti ti-download me-1"></i> Export CSV
            </a>
        </div>
    </div>
</div>
<div class="row g-3">
<div class="col-lg-4"><div class="card"><div class="card-header"><h3 class="card-title">Pembelian Baru</h3></div><div class="card-body"><form method="POST" action="{{ route('secondhand.store') }}" enctype="multipart/form-data" onsubmit="return confirm('Simpan pembelian HP bekas? (kas keluar otomatis)')">@csrf
<label class="form-label">Pelanggan (penjual)</label><select name="customer_id" class="form-select"><option value="">— Umum —</option>@foreach($customers as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select>
<label class="form-label mt-2">Produk bekas (opsional)</label><select name="product_id" class="form-select"><option value="">— buat baru dari nama —</option>@foreach($products as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select>
<label class="form-label mt-2">Nama produk (bila baru)</label><input name="product_name" class="form-control" placeholder="cth: iPhone 11 128GB Bekas">
<label class="form-label mt-2">IMEI</label><input name="imei" class="form-control font-monospace">
<label class="form-label mt-2">Foto Barang (maks 2, tiap max 2 MB)</label>
<input
    type="file"
    name="photos[]"
    class="form-control"
    multiple
    accept="image/*"
>
<div class="form-text">Foto tersimpan di storage + path dicatat [FOTO:path] pada mutasi stok pembelian ini.</div>
<hr><b>Penilaian terpandu</b><div class="text-secondary small">Skor → grade otomatis (bisa diubah) × % harga pasar.</div>
<label class="form-label mt-2">Harga pasar wajar (Rp)</label><input id="sh_market" name="market_price" type="number" min="0" value="0" class="form-control">
<div class="row g-2 mt-1">
<div class="col-6"><label class="form-label">Layar</label><select name="ck_layar" class="form-select shck"><option value="baik">Baik</option><option value="kurang" selected>Kurang</option><option value="rusak">Rusak</option></select></div>
<div class="col-6"><label class="form-label">Baterai</label><select name="ck_baterai" class="form-select shck"><option value="baik">Baik</option><option value="kurang" selected>Kurang</option><option value="rusak">Rusak</option></select></div>
<div class="col-6"><label class="form-label">Tombol</label><select name="ck_tombol" class="form-select shck"><option value="baik">Baik</option><option value="kurang" selected>Kurang</option><option value="rusak">Rusak</option></select></div>
<div class="col-6"><label class="form-label">Sinyal</label><select name="ck_sinyal" class="form-select shck"><option value="baik">Baik</option><option value="kurang" selected>Kurang</option><option value="rusak">Rusak</option></select></div>
<div class="col-12"><label class="form-label">Kelengkapan (dus/charger)</label><select name="ck_kelengkapan" class="form-select shck"><option value="baik">Lengkap</option><option value="kurang" selected>Sebagian</option><option value="rusak">Unit saja</option></select></div>
</div>
<div id="sh_saran" class="alert alert-info mt-2 mb-0 small">Skor 50/100 • Grade C • Saran Rp 0</div>
<label class="form-label mt-2">Grade kondisi</label><select id="sh_grade" name="condition_grade" class="form-select"><option>A</option><option selected>B</option><option>C</option><option>D</option></select>
<div class="row g-2 mt-2"><div class="col-6"><label class="form-label">Harga beli (bisa override)</label><input id="sh_buy" name="buy_price" type="number" min="0" value="0" class="form-control" required></div>
<div class="col-6"><label class="form-label">Rencana jual</label><input name="sell_price_plan" type="number" min="0" value="0" class="form-control" required></div></div>
<script>
(function(){
  const pct = {A:.8,B:.65,C:.50,D:.35}, w = {baik:20,kurang:10,rusak:0};
  const cks = [...document.querySelectorAll('select.shck')], m = document.getElementById('sh_market'),
        g = document.getElementById('sh_grade'), b = document.getElementById('sh_buy'), s = document.getElementById('sh_saran');
  function calc(){ const sc = cks.reduce((a,e)=>a+(w[e.value]??10),0);
    const gr = sc>=90?'A':sc>=75?'B':sc>=55?'C':'D';
    const sug = Math.round((parseFloat(m.value)||0)*pct[gr]);
    s.textContent = 'Skor '+sc+'/100 • Grade '+gr+' ('+(pct[gr]*100)+'%) • Saran Rp '+sug.toLocaleString('id-ID');
    g.value = gr; b.value = sug; }
  cks.forEach(e=>e.addEventListener('change',calc)); m.addEventListener('input',calc);
})();
</script>
<button class="btn btn-primary w-100 mt-3">Simpan (kas keluar otomatis)</button></form></div></div></div>
<div class="col-lg-8"><div class="card">
<div class="card-body border-bottom d-print-none">
    <div class="d-flex flex-wrap align-items-center gap-2">
        <button
            type="button"
            class="btn btn-sm btn-outline-primary"
            id="printSelectedShBtn"
        >
            <i class="ti ti-printer me-1"></i> Cetak Terpilih (maks 50)
        </button>
        <span
            class="text-secondary small"
            id="shBulkCount"
        >
            Centang baris pembelian lalu cetak — bukti digabung 1 halaman cetak.
        </span>
    </div>
</div>
<div class="table-responsive"><table class="table table-hover mb-0">
<thead><tr><th class="no-print w-1"><input type="checkbox" class="form-check-input" id="checkAllSh" title="Pilih semua di halaman ini"></th><th>Waktu</th><th>Produk / IMEI</th><th>Foto</th><th>Grade</th><th class="text-end">Beli</th><th class="text-end">Rencana</th><th>Status</th><th>Unit</th><th class="text-end">Jual Aktual</th><th class="text-end">Margin</th><th class="no-print">Bukti</th><th class="no-print"></th></tr></thead>
<tbody>
@forelse($purchases as $p)
<tr>
<td class="no-print">
    <input
        type="checkbox"
        class="form-check-input sh-check"
        value="{{ $p->id }}"
        title="Pilih bukti SH-{{ str_pad($p->id, 6, '0', STR_PAD_LEFT) }}"
    >
</td>
<td class="small">{{ $p->created_at->format('d/m/Y H:i') }}</td>
<td>{{ $p->product->name ?? '' }}<div class="small font-monospace text-secondary">{{ $p->imei }}</div></td>
<td>
    @php($fotosBekas = $photosByPurchase[$p->id] ?? [])
    @if(!empty($fotosBekas))
        <div class="d-flex gap-1">
            @foreach($fotosBekas as $fp)
                <a href="{{ asset('storage/'.$fp) }}" target="_blank">
                    <img
                        src="{{ asset('storage/'.$fp) }}"
                        class="rounded"
                        style="height:40px;width:40px;object-fit:cover"
                        alt="Foto barang bekas"
                    >
                </a>
            @endforeach
        </div>
    @else
        <span class="text-secondary">—</span>
    @endif
</td>
<td><span class="badge bg-blue-lt">{{ $p->condition_grade }}</span></td>
<td class="text-end">{{ number_format($p->buy_price,0,',','.') }}</td><td class="text-end">{{ number_format($p->sell_price_plan,0,',','.') }}</td>
<td><span class="badge bg-{{ $p->status==='dijual'?'green':($p->status==='retur'?'red':'yellow') }}-lt">{{ $p->status }}</span></td>
<td>@if($p->unit_status)<span class="badge bg-{{ $p->unit_status==='terjual'?'green':($p->unit_status==='tersedia'?'blue':'secondary') }}-lt">{{ $p->unit_status }}</span>@else<span class="text-secondary">—</span>@endif</td>
<td class="text-end">@if($p->actual_sell !== null){{ number_format($p->actual_sell,0,',','.') }}@else<span class="text-secondary">—</span>@endif</td>
<td class="text-end">@if($p->actual_margin !== null)<span class="fw-bold text-{{ $p->actual_margin >= 0 ? 'green' : 'red' }}">{{ ($p->actual_margin >= 0 ? '+' : '') . number_format($p->actual_margin,0,',','.') }}</span>@else<span class="text-secondary">—</span>@endif</td>
<td class="no-print">
<button
    type="button"
    class="btn btn-sm btn-outline-success"
    onclick="printStruk('bukti-sh-{{ $p->id }}')"
    title="Cetak bukti pembelian HP bekas #{{ $p->id }}"
>
    <i class="ti ti-printer me-1"></i> Bukti
</button>
</td>
<td class="no-print"><form method="POST" action="{{ route('secondhand.update',$p) }}" class="d-flex gap-1" onsubmit="return confirm('Ubah status pembelian ini?')">@csrf @method('PUT')
<select name="status" class="form-select form-select-sm"><option value="dibeli" {{ $p->status==='dibeli'?'selected':'' }}>dibeli</option><option value="dijual" {{ $p->status==='dijual'?'selected':'' }}>dijual</option><option value="retur" {{ $p->status==='retur'?'selected':'' }}>retur</option></select>
<button class="btn btn-sm btn-outline-primary">OK</button></form></td></tr>
@empty
<tr>
    <td colspan="13">
        <div class="empty py-3">
            <div class="empty-icon">
                <i class="ti ti-device-mobile fs-1 text-secondary"></i>
            </div>
            <p class="empty-title">Belum ada pembelian HP bekas</p>
            <p class="empty-subtitle text-secondary">Isi formulir Pembelian Baru — skor kondisi menghitung saran harga otomatis.</p>
        </div>
    </td>
</tr>
@endforelse</tbody></table></div>
<div class="card-footer">{{ $purchases->links() }}</div></div></div></div>
{{-- Bukti beli per baris: area cetak tersembunyi, dibuka via printStruk() (tanpa route baru).
     Pola mengikuti kwitansi cicilan (sales/payment_receipt) + voucher kas (cash/voucher):
     kop, tabel rincian, foto bila ada, kolom tanda tangan. --}}
@foreach($purchases as $p)
    @php($fotosBukti = $photosByPurchase[$p->id] ?? [])
    <div id="bukti-sh-{{ $p->id }}" style="display:none">
        <div style="font-family:Arial,Helvetica,sans-serif;color:#000;max-width:640px;margin:0 auto">
            <div style="text-align:center;margin-bottom:12px">
                <div style="font-size:20px;font-weight:bold">BUKTI PEMBELIAN HP BEKAS</div>
                <div style="font-size:12px">No. SH-{{ str_pad($p->id, 6, '0', STR_PAD_LEFT) }}</div>
                <div style="font-size:12px">{{ $p->store->name ?? 'Toko' }}</div>
            </div>
            <table style="width:100%;border-collapse:collapse;font-size:13px" border="1" cellpadding="6">
                <tbody>
                    <tr>
                        <th style="width:170px;text-align:left">No. Bukti</th>
                        <td>SH-{{ str_pad($p->id, 6, '0', STR_PAD_LEFT) }}</td>
                    </tr>
                    <tr>
                        <th style="text-align:left">Tanggal Beli</th>
                        <td>{{ $p->created_at->format('d/m/Y H:i') }}</td>
                    </tr>
                    <tr>
                        <th style="text-align:left">Penjual</th>
                        <td>{{ $p->customer->name ?? 'Umum' }}</td>
                    </tr>
                    <tr>
                        <th style="text-align:left">HP / Produk</th>
                        <td>{{ $p->product->name ?? '—' }}</td>
                    </tr>
                    <tr>
                        <th style="text-align:left">IMEI</th>
                        <td style="font-family:monospace">{{ $p->imei ?: '—' }}</td>
                    </tr>
                    <tr>
                        <th style="text-align:left">Grade Kondisi</th>
                        <td>{{ $p->condition_grade }}</td>
                    </tr>
                    <tr>
                        <th style="text-align:left">Harga Beli</th>
                        <td><b>Rp {{ number_format($p->buy_price, 0, ',', '.') }}</b></td>
                    </tr>
                    <tr>
                        <th style="text-align:left">Status</th>
                        <td>{{ $p->status }}</td>
                    </tr>
                </tbody>
            </table>
            @if(!empty($fotosBukti))
                <div style="margin-top:10px;font-size:13px">
                    <b>Foto barang:</b>
                </div>
                <div style="margin-top:6px">
                    @foreach($fotosBukti as $fp)
                        <img
                            src="{{ asset('storage/'.$fp) }}"
                            alt="Foto HP bekas SH-{{ str_pad($p->id, 6, '0', STR_PAD_LEFT) }}"
                            style="max-width:300px;width:100%;height:auto;border:1px solid #000;margin:0 6px 6px 0"
                        >
                    @endforeach
                </div>
            @endif
            <p style="font-size:12px;margin-top:10px">
                Penjual menyatakan HP di atas adalah milik sendiri, bukan barang curian/sengketa,
                dan data IMEI/grade/harga di atas sudah benar.
            </p>
            <table style="width:100%;font-size:13px;margin-top:24px;text-align:center;border:0">
                <tbody>
                    <tr>
                        <td style="border:0">
                            <div>Penjual,</div>
                            <div style="height:64px"></div>
                            <div>( {{ $p->customer->name ?? '....................' }} )</div>
                        </td>
                        <td style="border:0">
                            <div>Pihak Toko (Pembeli),</div>
                            <div style="height:64px"></div>
                            <div>( {{ auth()->user()->name ?? '....................' }} )</div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
@endforeach
{{-- Cetak massal bukti beli (cermin bulk statements/vouchers): gabung area hidden
     terpilih (maks 50) dalam 1 halaman cetak + page-break, tanpa route baru. --}}
<script>
(function () {
    var checkAll = document.getElementById('checkAllSh');
    if (checkAll) {
        checkAll.addEventListener('change', function () {
            document.querySelectorAll('.sh-check').forEach(function (cb) {
                cb.checked = checkAll.checked;
            });
        });
    }
    var btn = document.getElementById('printSelectedShBtn');
    if (!btn) return;
    btn.addEventListener('click', function () {
        var checked = Array.from(document.querySelectorAll('.sh-check:checked'));
        if (!checked.length) {
            alert('Pilih minimal 1 pembelian dulu (centang baris tabel).');
            return;
        }
        if (checked.length > 50) {
            alert('Maksimal 50 bukti per cetak massal. Kurangi pilihan Anda.');
            return;
        }
        var blocks = [];
        checked.forEach(function (cb, idx) {
            var src = document.getElementById('bukti-sh-' + cb.value);
            if (!src) return;
            blocks.push(
                '<section class="sh-bulk-page">' +
                '<div class="sh-bulk-num">Bukti ' + (idx + 1) + ' / ' + checked.length + '</div>' +
                src.innerHTML +
                '</section>'
            );
        });
        if (!blocks.length) {
            alert('Bukti terpilih tidak ditemukan di halaman ini.');
            return;
        }
        var w = window.open('', '_blank', 'width=900,height=700');
        if (!w) {
            alert('Popup diblokir browser. Izinkan popup lalu coba lagi.');
            return;
        }
        w.document.write(
            '<html>' +
            '<head>' +
            '<meta charset="utf-8">' +
            '<title>Cetak Bukti Beli Massal (' + blocks.length + ' bukti)</title>' +
            '<style>' +
            'body{font-family:Arial,Helvetica,sans-serif;background:#f5f5f5;color:#000;margin:0;padding:24px}' +
            '.sh-bulk-bar{background:#e7f1ff;border:1px solid #b6d4fe;padding:10px 14px;margin-bottom:16px;font-size:14px}' +
            '.sh-bulk-page{background:#fff;border:1px solid #dee2e6;border-radius:6px;padding:18px;margin:0 auto 18px;max-width:700px}' +
            '.sh-bulk-num{text-align:center;font-size:12px;color:#666;margin-bottom:10px}' +
            '@media print{' +
            '.no-print{display:none!important}' +
            'body{background:#fff;padding:0}' +
            '.sh-bulk-page{border:0;border-radius:0;padding:0;margin:0;max-width:100%;page-break-after:always}' +
            '.sh-bulk-page:last-child{page-break-after:auto}' +
            '@page{size:A4;margin:12mm}' +
            '}' +
            '</style>' +
            '</head>' +
            '<body>' +
            '<div class="sh-bulk-bar no-print">' +
            blocks.length + ' bukti pembelian HP bekas terpilih ' +
            '<button onclick="window.print()" style="margin-left:8px">Cetak Semua</button>' +
            '</div>' +
            blocks.join('') +
            '</body>' +
            '</html>'
        );
        w.document.close();
        w.focus();
    });
})();
</script>
@endsection
