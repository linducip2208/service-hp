@extends('layouts.tabler')
@section('title', 'Tukar Tambah')
@section('content')
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <div class="page-pretitle">Penjualan</div>
            <h2 class="page-title">Tukar Tambah</h2>
            <div class="text-secondary">Appraisal tercatat & bisa dipakai sebagai potongan di POS.</div>
        </div>
        <div class="col-auto ms-auto d-flex gap-2">
            <a
                href="{{ route('tradeins.index', array_merge(request()->query(), ['export' => 'csv'])) }}"
                class="btn btn-outline-success"
            >
                <i class="ti ti-download me-1"></i> Export CSV
            </a>
        </div>
    </div>
</div>
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Appraisal Baru</h3>
            </div>
            <div class="card-body">
                <form
                    method="POST"
                    action="{{ route('tradeins.store') }}"
                    enctype="multipart/form-data"
                    onsubmit="return confirm('Simpan hasil appraisal ini?')"
                >
                    @csrf
                    <label class="form-label">Pelanggan</label>
                    <select name="customer_id" class="form-select">
                        <option value="">— Umum —</option>
                        @foreach($customers as $c)
                            <option value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                    <div class="row g-2 mt-2">
                        <div class="col-6">
                            <label class="form-label">Merek lama</label>
                            <input name="old_brand" class="form-control" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Tipe lama</label>
                            <input name="old_type" class="form-control" required>
                        </div>
                    </div>
                    <label class="form-label mt-2">IMEI HP lama</label>
                    <input name="imei" class="form-control font-monospace">
                    <label class="form-label mt-2">Foto HP Lama (maks 2, tiap max 2 MB)</label>
                    <input
                        type="file"
                        name="photos[]"
                        class="form-control"
                        multiple
                        accept="image/*"
                    >
                    <div class="form-text">Foto tersimpan di storage + path dicatat [FOTO:path] pada catatan kondisi.</div>
                    <hr>
                    <b>Penilaian terpandu</b>
                    <div class="text-secondary small">Skor → grade A/B/C/D × % harga pasar = saran taksiran (bisa dioverride).</div>
                    <label class="form-label mt-2">Harga pasar wajar (Rp)</label>
                    <input
                        id="tt_market"
                        name="market_price"
                        type="number"
                        min="0"
                        value="0"
                        class="form-control"
                    >
                    <div class="row g-2 mt-1">
                        <div class="col-6">
                            <label class="form-label">Layar</label>
                            <select name="ck_layar" class="form-select ck">
                                <option value="baik">Baik</option>
                                <option value="kurang" selected>Kurang</option>
                                <option value="rusak">Rusak</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Baterai</label>
                            <select name="ck_baterai" class="form-select ck">
                                <option value="baik">Baik</option>
                                <option value="kurang" selected>Kurang</option>
                                <option value="rusak">Rusak</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Tombol</label>
                            <select name="ck_tombol" class="form-select ck">
                                <option value="baik">Baik</option>
                                <option value="kurang" selected>Kurang</option>
                                <option value="rusak">Rusak</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Sinyal</label>
                            <select name="ck_sinyal" class="form-select ck">
                                <option value="baik">Baik</option>
                                <option value="kurang" selected>Kurang</option>
                                <option value="rusak">Rusak</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Kelengkapan (dus/charger)</label>
                            <select name="ck_kelengkapan" class="form-select ck">
                                <option value="baik">Lengkap</option>
                                <option value="kurang" selected>Sebagian</option>
                                <option value="rusak">Unit saja</option>
                            </select>
                        </div>
                    </div>
                    <div id="tt_saran" class="alert alert-info mt-2 mb-0 small">Skor 50/100 • Grade C • Saran Rp 0</div>
                    <label class="form-label mt-2">Kondisi (catatan tambahan)</label>
                    <textarea
                        name="condition_note"
                        class="form-control"
                        rows="2"
                        placeholder="Mulus / lecet / minus…"
                    ></textarea>
                    <label class="form-label mt-2">Harga taksiran final (Rp, bisa override)</label>
                    <input
                        id="tt_price"
                        name="appraisal_price"
                        type="number"
                        min="0"
                        value="0"
                        class="form-control"
                        required
                    >
                    <script>
                    (function(){
                      const pct = {A:.8,B:.65,C:.50,D:.35}, w = {baik:20,kurang:10,rusak:0};
                      const cks = [...document.querySelectorAll('select.ck')], m = document.getElementById('tt_market'), p = document.getElementById('tt_price'), s = document.getElementById('tt_saran');
                      function calc(){ const sc = cks.reduce((a,e)=>a+(w[e.value]??10),0);
                        const g = sc>=90?'A':sc>=75?'B':sc>=55?'C':'D';
                        const sug = Math.round((parseFloat(m.value)||0)*pct[g]);
                        s.textContent = 'Skor '+sc+'/100 • Grade '+g+' ('+(pct[g]*100)+'%) • Saran Rp '+sug.toLocaleString('id-ID');
                        p.value = sug; }
                      cks.forEach(e=>e.addEventListener('change',calc)); m.addEventListener('input',calc);
                    })();
                    </script>
                    <label class="form-check mt-2">
                        <input type="checkbox" name="jadikan_stok" value="1" class="form-check-input" checked>
                        Jadikan stok bekas
                    </label>
                    <button class="btn btn-primary w-100 mt-2">Simpan</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card">
            <div class="card-body border-bottom d-print-none">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <button
                        type="button"
                        class="btn btn-sm btn-outline-primary"
                        id="printSelectedTtBtn"
                    >
                        <i class="ti ti-printer me-1"></i> Cetak Terpilih (maks 50)
                    </button>
                    <span
                        class="text-secondary small"
                        id="ttBulkCount"
                    >
                        Centang baris tukar tambah lalu cetak — bukti digabung 1 halaman cetak.
                    </span>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th class="no-print w-1"><input type="checkbox" class="form-check-input" id="checkAllTt" title="Pilih semua di halaman ini"></th>
                            <th>Waktu</th>
                            <th>HP Lama</th>
                            <th>IMEI</th>
                            <th>Pelanggan</th>
                            <th class="text-end">Taksiran</th>
                            <th>Status</th>
                            <th class="no-print">Bukti</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($tradeIns as $t)
                            <tr>
                                <td class="no-print">
                                    <input
                                        type="checkbox"
                                        class="form-check-input tt-check"
                                        value="{{ $t->id }}"
                                        title="Pilih bukti TT-{{ str_pad($t->id, 6, '0', STR_PAD_LEFT) }}"
                                    >
                                </td>
                                <td class="small">{{ $t->created_at->format('d/m/Y H:i') }}</td>
                                <td>
                                    {{ $t->old_brand }} {{ $t->old_type }}
                                    <div class="small text-secondary">{{ trim(preg_replace('/\s*\[FOTO:[^\]]+\]/', '', (string) $t->condition_note)) }}</div>
                                    @php(preg_match_all('/\[FOTO:([^\]]+)\]/', (string) $t->condition_note, $mmFoto))
                                    @if(!empty($mmFoto[1]))
                                        <div class="d-flex gap-1 mt-1">
                                            @foreach($mmFoto[1] as $fp)
                                                <a href="{{ asset('storage/'.trim($fp)) }}" target="_blank">
                                                    <img
                                                        src="{{ asset('storage/'.trim($fp)) }}"
                                                        class="rounded"
                                                        style="height:40px;width:40px;object-fit:cover"
                                                        alt="Foto tukar tambah"
                                                    >
                                                </a>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                                <td class="font-monospace small">{{ $t->imei ?: '-' }}</td>
                                <td>{{ $t->customer->name ?? 'Umum' }}</td>
                                <td class="text-end">{{ number_format($t->appraisal_price,0,',','.') }}</td>
                                <td>
                                    @if($t->sale_id)
                                        <span class="badge bg-green-lt">dipakai {{ $t->sale->invoice_no ?? '' }}</span>
                                    @else
                                        <span class="badge bg-yellow-lt">tersedia utk POS</span>
                                    @endif
                                </td>
                                <td class="no-print">
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-success"
                                        onclick="printStruk('bukti-tt-{{ $t->id }}')"
                                        title="Cetak bukti tukar tambah #{{ $t->id }}"
                                    >
                                        <i class="ti ti-printer me-1"></i> Bukti
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8">
                                    <div class="empty py-3">
                                        <div class="empty-icon"><i class="ti ti-repeat fs-1 text-secondary"></i></div>
                                        <p class="empty-title">Belum ada tukar tambah</p>
                                        <p class="empty-subtitle text-secondary">Isi formulir Appraisal Baru — nilai taksiran bisa langsung dipakai sebagai potongan di POS.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer">{{ $tradeIns->links() }}</div>
        </div>
    </div>
</div>
{{-- Bukti tukar tambah per baris: area cetak tersembunyi, dibuka via printStruk() (tanpa route baru).
     Cermin buyback receipt di secondhand/index: kop, tabel rincian HP lama + IMEI + appraisal,
     potongan ke invoice baru bila dipakai, foto dari marker [FOTO:] di condition_note,
     kolom tanda tangan. --}}
@foreach($tradeIns as $t)
    @php
        preg_match_all('/\[FOTO:([^\]]+)\]/', (string) $t->condition_note, $mmBukti);
        $fotosBukti = array_map('trim', $mmBukti[1] ?? []);
        $kondisiBersih = trim(preg_replace('/\s*\[FOTO:[^\]]+\]/', '', (string) $t->condition_note));
    @endphp
    <div id="bukti-tt-{{ $t->id }}" style="display:none">
        <div style="font-family:Arial,Helvetica,sans-serif;color:#000;max-width:640px;margin:0 auto">
            <div style="text-align:center;margin-bottom:12px">
                <div style="font-size:20px;font-weight:bold">BUKTI TUKAR TAMBAH</div>
                <div style="font-size:12px">No. TT-{{ str_pad($t->id, 6, '0', STR_PAD_LEFT) }}</div>
                <div style="font-size:12px">{{ $t->store->name ?? 'Toko' }}</div>
            </div>
            <table style="width:100%;border-collapse:collapse;font-size:13px" border="1" cellpadding="6">
                <tbody>
                    <tr>
                        <th style="width:190px;text-align:left">No. Bukti</th>
                        <td>TT-{{ str_pad($t->id, 6, '0', STR_PAD_LEFT) }}</td>
                    </tr>
                    <tr>
                        <th style="text-align:left">Tanggal</th>
                        <td>{{ $t->created_at->format('d/m/Y H:i') }}</td>
                    </tr>
                    <tr>
                        <th style="text-align:left">Pelanggan</th>
                        <td>{{ $t->customer->name ?? 'Umum' }}</td>
                    </tr>
                    <tr>
                        <th style="text-align:left">HP Lama Diserahkan</th>
                        <td>{{ $t->old_brand }} {{ $t->old_type }}</td>
                    </tr>
                    <tr>
                        <th style="text-align:left">IMEI HP Lama</th>
                        <td style="font-family:monospace">{{ $t->imei ?: '—' }}</td>
                    </tr>
                    <tr>
                        <th style="text-align:left">Kondisi</th>
                        <td>{{ $kondisiBersih !== '' ? $kondisiBersih : '—' }}</td>
                    </tr>
                    <tr>
                        <th style="text-align:left">Nilai Appraisal</th>
                        <td><b>Rp {{ number_format($t->appraisal_price, 0, ',', '.') }}</b></td>
                    </tr>
                    <tr>
                        <th style="text-align:left">Potongan ke Invoice Baru</th>
                        <td>
                            @if($t->sale_id)
                                Dipakai pada {{ $t->sale->invoice_no ?? ('penjualan #'.$t->sale_id) }}
                            @else
                                Belum dipakai — tersedia sebagai potongan di POS
                            @endif
                        </td>
                    </tr>
                </tbody>
            </table>
            @if(!empty($fotosBukti))
                <div style="margin-top:10px;font-size:13px">
                    <b>Foto HP lama:</b>
                </div>
                <div style="margin-top:6px">
                    @foreach($fotosBukti as $fp)
                        <img
                            src="{{ asset('storage/'.$fp) }}"
                            alt="Foto tukar tambah TT-{{ str_pad($t->id, 6, '0', STR_PAD_LEFT) }}"
                            style="max-width:300px;width:100%;height:auto;border:1px solid #000;margin:0 6px 6px 0"
                        >
                    @endforeach
                </div>
            @endif
            <p style="font-size:12px;margin-top:10px">
                Pelanggan menyatakan HP lama di atas adalah milik sendiri, bukan barang curian/sengketa,
                dan nilai appraisal di atas disepakati sebagai potongan pembelian HP baru.
            </p>
            <table style="width:100%;font-size:13px;margin-top:24px;text-align:center;border:0">
                <tbody>
                    <tr>
                        <td style="border:0">
                            <div>Pelanggan,</div>
                            <div style="height:64px"></div>
                            <div>( {{ $t->customer->name ?? '....................' }} )</div>
                        </td>
                        <td style="border:0">
                            <div>Pihak Toko,</div>
                            <div style="height:64px"></div>
                            <div>( {{ auth()->user()->name ?? '....................' }} )</div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
@endforeach
{{-- Cetak massal bukti TT (cermin bulk secondhand/titip: gabung area hidden
     terpilih (maks 50) dalam 1 halaman cetak + page-break, tanpa route baru). --}}
<script>
(function () {
    var checkAll = document.getElementById('checkAllTt');
    if (checkAll) {
        checkAll.addEventListener('change', function () {
            document.querySelectorAll('.tt-check').forEach(function (cb) {
                cb.checked = checkAll.checked;
            });
        });
    }
    var btn = document.getElementById('printSelectedTtBtn');
    if (!btn) return;
    btn.addEventListener('click', function () {
        var checked = Array.from(document.querySelectorAll('.tt-check:checked'));
        if (!checked.length) {
            alert('Pilih minimal 1 tukar tambah dulu (centang baris tabel).');
            return;
        }
        if (checked.length > 50) {
            alert('Maksimal 50 bukti per cetak massal. Kurangi pilihan Anda.');
            return;
        }
        var blocks = [];
        checked.forEach(function (cb, idx) {
            var src = document.getElementById('bukti-tt-' + cb.value);
            if (!src) return;
            blocks.push(
                '<section class="tt-bulk-page">' +
                '<div class="tt-bulk-num">Bukti ' + (idx + 1) + ' / ' + checked.length + '</div>' +
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
            '<title>Cetak Bukti TT Massal (' + blocks.length + ' bukti)</title>' +
            '<style>' +
            'body{font-family:Arial,Helvetica,sans-serif;background:#f5f5f5;color:#000;margin:0;padding:24px}' +
            '.tt-bulk-bar{background:#e7f1ff;border:1px solid #b6d4fe;padding:10px 14px;margin-bottom:16px;font-size:14px}' +
            '.tt-bulk-page{background:#fff;border:1px solid #dee2e6;border-radius:6px;padding:18px;margin:0 auto 18px;max-width:700px}' +
            '.tt-bulk-num{text-align:center;font-size:12px;color:#666;margin-bottom:10px}' +
            '@media print{' +
            '.no-print{display:none!important}' +
            'body{background:#fff;padding:0}' +
            '.tt-bulk-page{border:0;border-radius:0;padding:0;margin:0;max-width:100%;page-break-after:always}' +
            '.tt-bulk-page:last-child{page-break-after:auto}' +
            '@page{size:A4;margin:12mm}' +
            '}' +
            '</style>' +
            '</head>' +
            '<body>' +
            '<div class="tt-bulk-bar no-print">' +
            blocks.length + ' bukti tukar tambah terpilih ' +
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
