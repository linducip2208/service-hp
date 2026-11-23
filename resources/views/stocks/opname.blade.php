@extends('layouts.tabler')
@section('title', 'Stok Opname')
@section('content')
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h2 class="page-title">Stok Opname</h2>
            <div class="text-secondary">
                Sesuaikan qty fisik → tercatat sebagai movement opname.
                <a href="{{ route('stocks.deadstock') }}">Lihat stok mati →</a>
                ·
                <a href="{{ route('stocks.valuation') }}">Nilai persediaan →</a>
                ·
                <a href="{{ route('stocks.mutations') }}">Mutasi →</a>
            </div>
        </div>
        <div class="col-auto d-print-none">
            <button
                type="button"
                class="btn btn-outline-primary"
                onclick="document.getElementById('labelLokasiCard')?.scrollIntoView({ behavior: 'smooth' })"
            >
                <i class="ti ti-tag me-1"></i>️ Cetak Label Lokasi
            </button>
        </div>
    </div>
</div>

@if(session('import_fail'))
    <div class="alert alert-warning">
        <strong>Rincian baris gagal ({{ count(session('import_fail')) }}):</strong>
        <ul class="mb-0">
            @foreach(session('import_fail') as $f)
                <li>{{ $f }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Sesuaikan Stok</h3>
            </div>
            <div class="card-body">
                <form method="GET" action="{{ route('stocks.opname') }}" class="row g-2 mb-2">
                    <div class="col-5">
                        <input
                            name="q"
                            value="{{ request('q') }}"
                            class="form-control"
                            placeholder="Cari produk…"
                        >
                    </div>
                    <div class="col-4">
                        <select name="location" class="form-select">
                            <option value="">Semua lokasi</option>
                            <option value="__empty" {{ request('location') === '__empty' ? 'selected' : '' }}>
                                — Tanpa lokasi —
                            </option>
                            @foreach($locations ?? [] as $loc)
                                <option value="{{ $loc }}" {{ request('location') === $loc ? 'selected' : '' }}>
                                    {{ $loc }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-3">
                        <button class="btn btn-secondary w-100">Filter</button>
                    </div>
                </form>

                <form method="POST" action="{{ route('stocks.opname.store') }}">
                    @csrf
                    <div class="mb-2">
                        <input
                            name="note"
                            class="form-control"
                            placeholder="Catatan opname (mis. opname mingguan)"
                        >
                    </div>
                    <div class="input-group mb-2">
                        <input
                            id="scanOpname"
                            class="form-control"
                            placeholder="Scan/ketik SKU / barcode / nama → Enter"
                            autocomplete="off"
                            autofocus
                        >
                        <button type="button" class="btn btn-outline-secondary" id="scanOpnameBtn">
                            Cari
                        </button>
                    </div>
                    <div id="scanOpnameMsg" class="small text-danger mb-2" style="display:none"></div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0" id="opnameTable">
                            <thead>
                                <tr>
                                    <th>Produk</th>
                                    <th>Lokasi / Rak</th>
                                    <th class="text-end">Sistem</th>
                                    <th width="110">Fisik</th>
                                    <th width="90">Min toko</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($stocks as $s)
                                    <tr
                                        data-search="{{ strtolower(($s->product->sku ?? '') . ' ' . ($s->product->barcode ?? '') . ' ' . $s->product->name) }}"
                                        data-sku="{{ strtolower($s->product->sku ?? '') }}"
                                    >
                                        <td>
                                            {{ $s->product->name }}
                                            <div class="small text-secondary font-monospace">
                                                {{ $s->product->sku }}
                                                @if(!empty($s->product->barcode))
                                                    · {{ $s->product->barcode }}
                                                @endif
                                            </div>
                                        </td>
                                        <td>
                                            <input
                                                type="text"
                                                name="items[{{ $loop->index }}][location]"
                                                value="{{ $s->location }}"
                                                class="form-control form-control-sm"
                                                placeholder="Etalase A"
                                                maxlength="100"
                                                list="locationList"
                                            >
                                        </td>
                                        <td class="text-end">{{ $s->qty }}</td>
                                        <td>
                                            <input
                                                type="hidden"
                                                name="items[{{ $loop->index }}][product_id]"
                                                value="{{ $s->product_id }}"
                                            >
                                            <input
                                                type="number"
                                                min="0"
                                                name="items[{{ $loop->index }}][qty]"
                                                value="{{ $s->qty }}"
                                                class="form-control qty-input"
                                            >
                                        </td>
                                        <td title="Kosongkan = ikut global ({{ $s->product->min_stock ?? 5 }})">
                                            <input
                                                type="number"
                                                min="0"
                                                max="1000000"
                                                name="items[{{ $loop->index }}][min_stock]"
                                                value="{{ $s->min_stock }}"
                                                placeholder="G:{{ $s->product->min_stock ?? 5 }}"
                                                class="form-control form-control-sm"
                                            >
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <datalist id="locationList">
                        @foreach($locations ?? [] as $loc)
                            <option value="{{ $loc }}">
                        @endforeach
                    </datalist>
                    <button class="btn btn-primary mt-2">Simpan Opname</button>
                </form>
                <div class="mt-2">{{ $stocks->links() }}</div>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header">
                <h3 class="card-title">Import CSV Opname</h3>
            </div>
            <div class="card-body">
                <p class="text-secondary small mb-2">
                    Format: <code>sku,qty[,location]</code> — header wajib
                    <code>sku</code> + <code>qty</code>, <code>location</code> opsional.
                    Delimiter otomatis <code>;</code> atau <code>,</code>
                    (ala Excel Indonesia). qty bilangan bulat ≥ 0.
                    Kolom <code>location</code> yang dikosongkan = lokasi tidak diubah.
                    SKU tidak dikenal / duplikat dilaporkan per baris (duplikat: baris terakhir menang).
                    Berlaku untuk toko aktif saja, langsung diterapkan + movement opname per baris berubah.
                </p>
                <p class="small mb-2">
                    Contoh: <code>SKU-001;12;Etalase A</code>
                    · <a href="{{ route('stocks.opname', ['template' => 'csv']) }}">Unduh template CSV</a>
                </p>
                <form
                    method="POST"
                    action="{{ route('stocks.opname.import') }}"
                    enctype="multipart/form-data"
                    class="row g-2"
                >
                    @csrf
                    <div class="col-12">
                        <input
                            name="note"
                            class="form-control"
                            placeholder="Catatan opname (mis. opname via CSV 23/11)"
                            maxlength="500"
                        >
                    </div>
                    <div class="col-8">
                        <input
                            type="file"
                            name="file"
                            class="form-control"
                            accept=".csv,.txt"
                            required
                        >
                    </div>
                    <div class="col-4">
                        <button class="btn btn-dark w-100">Import &amp; Terapkan</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card mb-3" id="labelLokasiCard">
            <div class="card-header">
                <h3 class="card-title"><i class="ti ti-tag me-1"></i>️ Label Lokasi Gudang</h3>
            </div>
            <div class="card-body">
                <p class="text-secondary small mb-2">
                    Daftar lokasi unik dari stok toko aktif
                    (sama seperti datalist lokasi di form opname).
                    Centang lokasi lalu cetak label besar
                    untuk ditempel di rak. Barcode = teks lokasi (CODE128).
                </p>
                @if(($locations ?? collect())->isNotEmpty())
                    <div class="mb-2 d-flex gap-2">
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-secondary"
                            id="locCheckAll"
                        >
                            Pilih semua
                        </button>
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-secondary"
                            id="locUncheckAll"
                        >
                            Kosongkan
                        </button>
                    </div>
                    <div
                        id="locList"
                        class="d-flex flex-column gap-1 mb-2"
                        style="max-height: 220px; overflow-y: auto;"
                    >
                        @foreach($locations ?? [] as $loc)
                            <label class="form-check">
                                <input
                                    type="checkbox"
                                    class="form-check-input loc-check"
                                    value="{{ $loc }}"
                                    checked
                                >
                                <span class="form-check-label font-monospace">
                                    {{ $loc }}
                                </span>
                            </label>
                        @endforeach
                    </div>
                    <div class="d-flex gap-2">
                        <button
                            type="button"
                            class="btn btn-primary"
                            id="btnPrintLoc"
                        >
                            <i class="ti ti-printer me-1"></i> Cetak Label Terpilih
                        </button>
                        <button
                            type="button"
                            class="btn btn-outline-primary"
                            id="btnPreviewLoc"
                        >
                            Pratinjau
                        </button>
                    </div>
                @else
                    <p class="text-secondary small mb-0">
                        Belum ada lokasi tersimpan. Isi kolom
                        “Lokasi / Rak” di form opname lalu simpan —
                        lokasi baru otomatis muncul di sini.
                    </p>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h3 class="card-title mb-0">Riwayat Opname</h3>
                <button
                    type="button"
                    class="btn btn-sm btn-outline-primary d-print-none"
                    id="btnPrintBA"
                    @if(($movements ?? collect())->isEmpty()) disabled @endif
                >
                    <i class="ti ti-printer me-1"></i> Berita Acara Terakhir
                </button>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>Waktu</th>
                            <th>Produk</th>
                            <th>Selisih</th>
                            <th>Catatan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($movements as $m)
                            <tr>
                                <td class="small">{{ $m->created_at->format('d/m/Y H:i') }}</td>
                                <td class="small">{{ $m->product->name ?? '' }}</td>
                                <td>{{ $m->qty }}</td>
                                <td class="small">{{ $m->note }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center text-secondary">Belum ada opname.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
    var inp = document.getElementById('scanOpname');
    if (!inp) return;
    var msg = document.getElementById('scanOpnameMsg');
    var btn = document.getElementById('scanOpnameBtn');
    var last = null;

    function clearHi() {
        if (last) {
            last.classList.remove('table-warning');
            last = null;
        }
    }

    function showMsg(t) {
        if (!msg) return;
        msg.textContent = t;
        msg.style.display = t ? 'block' : 'none';
    }

    function norm(s) {
        return (s || '').toString().trim().toLowerCase();
    }

    function findRow(key) {
        key = norm(key);
        if (!key) return null;
        var rows = document.querySelectorAll('#opnameTable tbody tr[data-search]');
        // 1. exact SKU match dulu (umum untuk barcode scanner)
        for (var i = 0; i < rows.length; i++) {
            if (norm(rows[i].getAttribute('data-sku')) === key) return rows[i];
        }
        // 2. substring di sku/barcode/nama
        for (var j = 0; j < rows.length; j++) {
            if ((rows[j].getAttribute('data-search') || '').indexOf(key) !== -1) return rows[j];
        }
        return null;
    }

    function doScan() {
        clearHi();
        var row = findRow(inp.value);
        if (!row) {
            showMsg('Tidak ditemukan di halaman ini, tambah manual di Produk.');
            inp.select();
            return;
        }
        showMsg('');
        row.classList.add('table-warning');
        last = row;
        row.scrollIntoView({ behavior: 'smooth', block: 'center' });
        var qty = row.querySelector('.qty-input');
        if (qty) {
            qty.focus();
            qty.select();
        }
        inp.select();
    }

    inp.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            doScan();
        }
    });
    if (btn) btn.addEventListener('click', doScan);
})();
</script>

<div id="locSheet" class="d-none">
    <div id="locGrid"></div>
</div>

@php
    $baAll = ($movements ?? collect());
    $baFirst = $baAll->first();
    $baDayKey = $baFirst ? $baFirst->created_at->format('Y-m-d') : null;
    $baSession = $baDayKey
        ? $baAll->filter(fn($m) => $m->created_at->format('Y-m-d') === $baDayKey)->values()
        : collect();
    $baDateLabel = $baFirst ? $baFirst->created_at->translatedFormat('l, d F Y') : '-';
    $baTimeLabel = $baDayKey
        ? $baSession->min(fn($m) => $m->created_at->format('H:i'))
            . ' – '
            . $baSession->max(fn($m) => $m->created_at->format('H:i'))
            . ' WIB'
        : '-';
    $baParsed = $baSession->map(function ($m) {
        $old = null;
        $new = null;
        if (preg_match('/\(\s*(\d+)\s*→\s*(\d+)\s*\)/u', (string) $m->note, $mm)) {
            $old = (int) $mm[1];
            $new = (int) $mm[2];
        }
        $diff = ($old !== null && $new !== null) ? ($new - $old) : 0;
        $ket = trim((string) preg_replace('/\(\s*\d+\s*→\s*\d+\s*\)/u', '', (string) $m->note, 1));
        $ket = trim($ket, " —–-.\t\n\r\0\x0B");
        return [
            'm' => $m,
            'old' => $old,
            'new' => $new,
            'diff' => $diff,
            'ket' => $ket,
        ];
    });
    $baCount = $baParsed->count();
    $baPlus = $baParsed->where(fn($r) => $r['diff'] > 0)->count();
    $baMinus = $baParsed->where(fn($r) => $r['diff'] < 0)->count();
    $baSame = $baParsed->where(fn($r) => $r['diff'] === 0)->count();
    $baTotalDiff = $baParsed->sum(fn($r) => $r['diff']);
    $baAbsDiff = $baParsed->sum(fn($r) => abs($r['diff']));
    $baStoreName = \App\Models\Store::whereKey((int) session('active_store_id'))->value('name')
        ?? ('Toko #' . session('active_store_id'));
@endphp

<div id="baSheet" class="d-none">
    <div class="ba-paper">
        <div class="ba-kop">
            <div class="ba-kop-title">BERITA ACARA STOCK OPNAME</div>
            <div class="ba-kop-sub">
                {{ $baStoreName }}
            </div>
        </div>
        <table class="ba-meta">
            <tr>
                <td class="ba-meta-k">Tanggal opname</td>
                <td class="ba-meta-v">: {{ $baDateLabel }} ({{ $baTimeLabel }})</td>
            </tr>
            <tr>
                <td class="ba-meta-k">Jumlah baris sesi ini</td>
                <td class="ba-meta-v">: {{ $baCount }} baris (riwayat 30 terakhir, sesi hari terakhir)</td>
            </tr>
            <tr>
                <td class="ba-meta-k">Dicetak pada</td>
                <td class="ba-meta-v">: {{ now()->translatedFormat('d F Y H:i') }} WIB oleh {{ auth()->user()->name ?? '-' }}</td>
            </tr>
        </table>
        <p class="ba-p">
            Pada tanggal tersebut telah dilakukan perhitungan fisik persediaan
            dengan hasil sebagai berikut:
        </p>
        <table class="ba-table">
            <thead>
                <tr>
                    <th width="32">No</th>
                    <th>Produk / SKU</th>
                    <th width="60" class="text-end">Sistem</th>
                    <th width="60" class="text-end">Fisik</th>
                    <th width="70" class="text-end">Selisih</th>
                    <th>Keterangan</th>
                </tr>
            </thead>
            <tbody>
                @forelse($baParsed as $i => $r)
                    <tr>
                        <td class="text-center">{{ $i + 1 }}</td>
                        <td>
                            <strong>{{ $r['m']->product->name ?? '-' }}</strong>
                            <div class="ba-sku font-monospace">
                                {{ $r['m']->product->sku ?? '-' }}
                                · {{ $r['m']->created_at->format('H:i') }}
                            </div>
                        </td>
                        <td class="text-end">{{ $r['old'] !== null ? $r['old'] : '-' }}</td>
                        <td class="text-end">{{ $r['new'] !== null ? $r['new'] : '-' }}</td>
                        <td class="text-end">
                            @if($r['diff'] > 0)
                                +{{ $r['diff'] }}
                            @elseif($r['diff'] < 0)
                                {{ $r['diff'] }}
                            @else
                                0
                            @endif
                        </td>
                        <td class="ba-ket">{{ $r['ket'] !== '' ? $r['ket'] : '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center">Belum ada opname.</td>
                    </tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="4" class="text-end">Total selisih (fisik − sistem)</th>
                    <th class="text-end">
                        @if($baTotalDiff > 0)
                            +{{ $baTotalDiff }}
                        @else
                            {{ $baTotalDiff }}
                        @endif
                    </th>
                    <th class="ba-ket">{{ $baAbsDiff }} pcs gerak absolut</th>
                </tr>
                <tr>
                    <th colspan="6" class="ba-foot-note">
                        Rincian: {{ $baPlus }} item surplus · {{ $baMinus }} item minus · {{ $baSame }} item tetap / pindah lokasi
                    </th>
                </tr>
            </tfoot>
        </table>
        <p class="ba-p">
            Demikian berita acara ini dibuat dengan sebenarnya untuk
            penyesuaian stok dan arsip. Selisih di atas telah disesuaikan
            ke sistem sebagai movement opname.
        </p>
        <table class="ba-sign">
            <tr>
                <td>
                    <div>Mengetahui,</div>
                    <div class="ba-sign-role">Owner / Penanggung jawab</div>
                    <div class="ba-sign-space"></div>
                    <div class="ba-sign-name">( ........................................ )</div>
                </td>
                <td>
                    <div>{{ $baStoreName }}, {{ $baFirst ? $baFirst->created_at->format('d/m/Y') : '-' }}</div>
                    <div class="ba-sign-role">Penghitung</div>
                    <div class="ba-sign-space"></div>
                    <div class="ba-sign-name">( {{ auth()->user()->name ?? '........................................' }} )</div>
                </td>
            </tr>
        </table>
    </div>
</div>

<style>
#locSheet {
    margin-top: 12px;
}
#baSheet {
    margin-top: 12px;
}
.ba-paper {
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 20px 22px;
    background: #fff;
}
.ba-kop {
    text-align: center;
    border-bottom: 2px solid #000;
    padding-bottom: 10px;
    margin-bottom: 12px;
}
.ba-kop-title {
    font-size: 20px;
    font-weight: 800;
    letter-spacing: 0.5px;
}
.ba-kop-sub {
    font-size: 14px;
    color: #444;
}
.ba-meta {
    width: 100%;
    font-size: 13px;
    margin-bottom: 8px;
}
.ba-meta-k {
    width: 190px;
    color: #555;
    vertical-align: top;
}
.ba-meta-v {
    vertical-align: top;
}
.ba-p {
    font-size: 13px;
    margin: 8px 0;
}
.ba-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12.5px;
}
.ba-table th,
.ba-table td {
    border: 1px solid #999;
    padding: 5px 7px;
    vertical-align: top;
}
.ba-table thead th {
    background: #f1f1f1;
}
.ba-table .text-end {
    text-align: right;
}
.ba-table .text-center {
    text-align: center;
}
.ba-sku {
    font-size: 11px;
    color: #666;
}
.ba-ket {
    font-size: 11.5px;
}
.ba-foot-note {
    font-weight: 400;
    font-size: 11.5px;
    text-align: center;
}
.ba-sign {
    width: 100%;
    margin-top: 26px;
    font-size: 13px;
    text-align: center;
}
.ba-sign td {
    width: 50%;
    vertical-align: top;
}
.ba-sign-role {
    color: #555;
}
.ba-sign-space {
    height: 72px;
}
.ba-sign-name {
    font-weight: 600;
}
.loc-label {
    border: 2px dashed #888;
    border-radius: 8px;
    padding: 24px 16px;
    margin-bottom: 12px;
    text-align: center;
    page-break-inside: avoid;
}
.loc-label .loc-name {
    font-size: 48px;
    font-weight: 800;
    line-height: 1.1;
    word-break: break-word;
}
.loc-label .loc-sub {
    color: #666;
    font-size: 13px;
    margin-top: 4px;
}
.loc-label svg {
    max-width: 100%;
}
@media print {
    body * {
        visibility: hidden;
    }
    body.printing-ba #baSheet,
    body.printing-ba #baSheet * {
        visibility: visible;
    }
    body.printing-loc #locSheet,
    body.printing-loc #locSheet * {
        visibility: visible;
    }
    body.printing-ba #baSheet {
        display: block !important;
        position: absolute;
        inset: 0;
        margin: 0;
    }
    body.printing-loc #locSheet {
        display: block !important;
        position: absolute;
        inset: 0;
        margin: 0;
    }
    .ba-paper {
        border: none;
        border-radius: 0;
        padding: 0;
    }
    .ba-table thead th {
        background: #eee !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .loc-label {
        border: 3px solid #000;
        border-radius: 0;
        margin: 0;
        padding: 30mm 10mm;
        page-break-after: always;
    }
    .loc-label .loc-name {
        font-size: 72px;
    }
}
</style>

<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<script>
(function () {
    var grid = document.getElementById('locGrid');
    var sheet = document.getElementById('locSheet');
    var btnPrint = document.getElementById('btnPrintLoc');
    var btnPreview = document.getElementById('btnPreviewLoc');
    if (!grid || !sheet) return;

    function selectedLocs() {
        var out = [];
        document.querySelectorAll('.loc-check:checked').forEach(function (c) {
            if (c.value && c.value.trim() !== '') out.push(c.value.trim());
        });
        return out;
    }

    function renderLocLabels(locs) {
        grid.innerHTML = '';
        locs.forEach(function (loc) {
            var d = document.createElement('div');
            d.className = 'loc-label';
            var h = document.createElement('div');
            h.className = 'loc-name';
            h.textContent = loc;
            var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            var sub = document.createElement('div');
            sub.className = 'loc-sub';
            sub.textContent = 'LOKASI GUDANG • ' + loc;
            d.appendChild(h);
            d.appendChild(svg);
            d.appendChild(sub);
            grid.appendChild(d);
            try {
                JsBarcode(svg, loc, {
                    format: 'CODE128',
                    height: 70,
                    width: 2,
                    fontSize: 16,
                    displayValue: true,
                });
            } catch (e) {
                sub.textContent = 'LOKASI GUDANG • ' + loc + ' (barcode gagal)';
            }
        });
        sheet.classList.remove('d-none');
        sheet.scrollIntoView({ behavior: 'smooth' });
    }

    var checkAll = document.getElementById('locCheckAll');
    var uncheckAll = document.getElementById('locUncheckAll');
    if (checkAll) checkAll.addEventListener('click', function () {
        document.querySelectorAll('.loc-check').forEach(function (c) { c.checked = true; });
    });
    if (uncheckAll) uncheckAll.addEventListener('click', function () {
        document.querySelectorAll('.loc-check').forEach(function (c) { c.checked = false; });
    });

    if (btnPreview) btnPreview.addEventListener('click', function () {
        var locs = selectedLocs();
        if (!locs.length) {
            alert('Pilih minimal satu lokasi.');
            return;
        }
        renderLocLabels(locs);
    });

    if (btnPrint) btnPrint.addEventListener('click', function () {
        var locs = selectedLocs();
        if (!locs.length) {
            alert('Pilih minimal satu lokasi.');
            return;
        }
        document.body.classList.remove('printing-ba');
        document.body.classList.add('printing-loc');
        renderLocLabels(locs);
        setTimeout(function () { window.print(); }, 400);
    });
    window.addEventListener('afterprint', function () {
        document.body.classList.remove('printing-loc');
    });
})();
</script>

<script>
(function () {
    var btnBA = document.getElementById('btnPrintBA');
    var sheet = document.getElementById('baSheet');
    if (!btnBA || !sheet) return;
    btnBA.addEventListener('click', function () {
        var rows = sheet.querySelectorAll('.ba-table tbody tr');
        if (!rows.length) {
            alert('Belum ada opname.');
            return;
        }
        document.body.classList.remove('printing-loc');
        document.body.classList.add('printing-ba');
        sheet.classList.remove('d-none');
        setTimeout(function () { window.print(); }, 150);
    });
    window.addEventListener('afterprint', function () {
        document.body.classList.remove('printing-ba');
    });
})();
@endsection
