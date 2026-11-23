@extends('layouts.tabler')
@section('title', 'Mutasi & Transfer')
@section('content')
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h2 class="page-title">Mutasi / Transfer Antar Toko</h2>
            <div class="text-secondary">
                Transfer massal banyak produk sekaligus + unit IMEI ikut pindah + movement keluar &amp; masuk per baris (satu batch).
                <a href="{{ route('stocks.deadstock') }}">Lihat stok mati →</a>
                ·
                <a href="{{ route('stocks.opname') }}">Opname →</a>
                ·
                <a href="{{ route('units.index') }}">Kelola unit 1-klik per baris →</a>
            </div>
        </div>
    </div>
</div>
@if($errors->any())
    <div class="alert alert-danger">
        <strong>Gagal validasi transfer:</strong>
        <ul class="mb-0 ps-3">
            @foreach($errors->all() as $e)
                <li>{{ $e }}</li>
            @endforeach
        </ul>
    </div>
@endif
<div class="row g-3">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Transfer Massal</h3>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('stocks.mutate') }}" id="mutateForm">
                    @csrf
                    <label class="form-label">Toko tujuan</label>
                    <select name="to_store_id" class="form-select" required>
                        @forelse($stores as $s)
                            <option value="{{ $s->id }}">{{ $s->name }}</option>
                        @empty
                            <option value="" disabled>Tidak ada toko tujuan aktif</option>
                        @endforelse
                    </select>
                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <label class="form-label mb-0">Produk non-serial (banyak baris)</label>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="addRow">+ Tambah baris</button>
                    </div>
                    <p class="small text-secondary mb-2" id="rowSummary">1 baris · total 1 pcs</p>
                    <div id="rows"></div>
                    <template id="productOptions">
                        @foreach($stocks as $s)
                            <option value="{{ $s->product_id }}" data-sisa="{{ $s->qty }}">{{ $s->product->name }} (sisa {{ $s->qty }})</option>
                        @endforeach
                    </template>
                    <label class="form-label mt-3">Unit IMEI (centang yang dipindah)</label>
                    <input type="search" id="unitFilter" class="form-control form-control-sm mb-1" placeholder="Cari IMEI / nama produk…">
                    <div style="max-height:220px;overflow:auto" class="border rounded p-2" id="unitList">
                        @forelse($units as $u)
                            <label class="form-check unit-row" data-key="{{ strtolower(($u->imei ?: '#'.$u->id).' '.$u->product->name) }}">
                                <input
                                    type="checkbox"
                                    name="unit_ids[]"
                                    value="{{ $u->id }}"
                                    class="form-check-input"
                                >
                                <span class="font-monospace">{{ $u->imei ?: '#'.$u->id }}</span>
                                —
                                {{ $u->product->name }}
                            </label>
                        @empty
                            <p class="small text-secondary mb-0">Tidak ada unit tersedia di toko ini.</p>
                        @endforelse
                    </div>
                    <p class="small text-secondary mt-2 mb-0">
                        Butuh pindah 1 unit spesifik?
                        Buka
                        <a href="{{ route('units.index') }}">daftar unit</a>
                        lalu klik <strong>Pindah</strong> pada barisnya.
                    </p>
                    <label class="form-label mt-2">Catatan (dicatat per movement + kode batch)</label>
                    <input name="note" class="form-control" maxlength="500" placeholder="cth: pemerataan stok cabang B">
                    <button class="btn btn-primary w-100 mt-3">Kirim Mutasi Massal</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header">
                <h3 class="card-title">Surat Jalan per Batch</h3>
            </div>
            <div class="card-body">
                @forelse($batches as $b)
                    <div class="d-flex flex-wrap align-items-center gap-2 py-2 border-bottom">
                        <span class="font-monospace fw-bold">{{ $b['code'] }}</span>
                        <span class="small text-secondary">
                            {{ $b['created_at'] ? $b['created_at']->format('d/m/Y H:i') : '-' }}
                            · {{ $b['from'] }} → {{ $b['to'] }}
                            · {{ $b['count'] }} baris movement
                        </span>
                        <a
                            class="btn btn-sm btn-outline-primary ms-auto"
                            href="{{ route('stocks.mutations.surat', ['batch' => $b['code']]) }}"
                            target="_blank"
                        >
                            Cetak surat jalan
                        </a>
                    </div>
                @empty
                    <p class="small text-secondary mb-0">Belum ada batch pada 50 riwayat terakhir.</p>
                @endforelse
                <form method="GET" action="{{ route('stocks.mutations.surat') }}" target="_blank" class="d-flex gap-2 mt-3">
                    <input
                        name="batch"
                        class="form-control form-control-sm font-monospace"
                        placeholder="Cetak batch lama: TRM-YYYYMMDDHHMMSS-XXXXXX"
                        pattern="TRM-[0-9]{14}-[A-Z0-9]{6}"
                        title="Format: TRM-YYYYMMDDHHMMSS-XXXXXX"
                    >
                    <button class="btn btn-sm btn-primary text-nowrap" type="submit">Cetak</button>
                </form>
                <p class="small text-secondary mb-0 mt-1">Batch lama (di luar 50 terakhir) tetap bisa dicetak via kode di atas.</p>
            </div>
        </div>
        <div class="card mb-3">
            <div class="card-header">
                <h3 class="card-title">
                    Filter Riwayat
                </h3>
            </div>
            <div class="card-body">
                <form
                    method="GET"
                    action="{{ route('stocks.mutations') }}"
                    class="row g-2"
                >
                    <div class="col-md-3">
                        <label class="form-label">
                            Toko lawan
                        </label>
                        <select
                            name="store_id"
                            class="form-select form-select-sm"
                        >
                            <option value="">
                                Semua toko
                            </option>
                            @foreach(($filterStores ?? $stores) as $s)
                                <option
                                    value="{{ $s->id }}"
                                    @selected((string) request('store_id') === (string) $s->id)
                                >
                                    {{ $s->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">
                            Tipe
                        </label>
                        <select
                            name="type"
                            class="form-select form-select-sm"
                        >
                            <option value="">
                                Semua tipe
                            </option>
                            <option
                                value="mutasi_keluar"
                                @selected(request('type') === 'mutasi_keluar')
                            >
                                mutasi_keluar
                            </option>
                            <option
                                value="mutasi_masuk"
                                @selected(request('type') === 'mutasi_masuk')
                            >
                                mutasi_masuk
                            </option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">
                            Dari tanggal
                        </label>
                        <input
                            type="date"
                            name="from"
                            value="{{ request('from') }}"
                            class="form-control form-control-sm"
                        >
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">
                            Sampai tanggal
                        </label>
                        <input
                            type="date"
                            name="to"
                            value="{{ request('to') }}"
                            class="form-control form-control-sm"
                        >
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">
                            Cari produk / SKU / batch
                        </label>
                        <input
                            type="search"
                            name="q"
                            value="{{ request('q') }}"
                            class="form-control form-control-sm"
                            placeholder="nama / sku / TRM-…"
                        >
                    </div>
                    <div class="col-12 d-flex flex-wrap gap-2">
                        <button
                            type="submit"
                            class="btn btn-sm btn-primary"
                        >
                            Terapkan filter
                        </button>
                        <a
                            href="{{ route('stocks.mutations') }}"
                            class="btn btn-sm btn-outline-secondary"
                        >
                            Reset
                        </a>
                        <a
                            href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}"
                            class="btn btn-sm btn-outline-success ms-auto"
                        >
                            Export CSV
                        </a>
                    </div>
                </form>
                <p class="small text-secondary mb-0 mt-2">
                    Export CSV mengikuti filter toko / periode / tipe / cari yang sedang aktif (maks 5000 baris).
                </p>
            </div>
        </div>
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Riwayat Mutasi (50 terakhir)</h3>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>Waktu</th>
                            <th>Produk</th>
                            <th>Tipe</th>
                            <th>Dari → Ke</th>
                            <th>Qty</th>
                            <th>Batch</th>
                            <th>Catatan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($movements as $m)
                            @php
                                $batchCode = null;
                                if (preg_match('/\[batch\s+(TRM-\d{14}-[A-Z0-9]{6})\]/', (string) ($m->note ?? ''), $found)) {
                                    $batchCode = $found[1];
                                }
                            @endphp
                            <tr>
                                <td class="small">{{ $m->created_at->format('d/m/Y H:i') }}</td>
                                <td class="small">{{ $m->product->name ?? '' }}</td>
                                <td>
                                    <span class="badge {{ $m->type === 'mutasi_keluar' ? 'bg-orange-lt' : 'bg-green-lt' }}">{{ $m->type }}</span>
                                </td>
                                <td class="small">{{ $m->fromStore->name ?? '?' }} → {{ $m->toStore->name ?? '?' }}</td>
                                <td>{{ $m->qty }}</td>
                                <td class="small">
                                    @if($batchCode)
                                        <a
                                            href="{{ route('stocks.mutations.surat', ['batch' => $batchCode]) }}"
                                            target="_blank"
                                            title="Cetak surat jalan {{ $batchCode }}"
                                            class="font-monospace"
                                        >
                                            {{ $batchCode }}
                                        </a>
                                    @else
                                        <span class="text-secondary">-</span>
                                    @endif
                                </td>
                                <td class="small text-secondary" title="{{ $m->note }}">{{ \Str::limit($m->note ?? '', 60) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-secondary">Belum ada mutasi.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    const rowsEl = document.getElementById('rows');
    const tpl = document.getElementById('productOptions');
    const summary = document.getElementById('rowSummary');
    const optionsHtml = tpl ? tpl.innerHTML : '';
    let idx = 0;

    function refreshSummary() {
        const qtys = rowsEl.querySelectorAll('input[type="number"]');
        let total = 0;
        qtys.forEach(i => { total += parseInt(i.value || '0', 10) || 0; });
        summary.textContent = qtys.length + ' baris · total ' + total + ' pcs';
    }

    function clampQty(input, select) {
        const opt = select.options[select.selectedIndex];
        const sisa = opt ? parseInt(opt.dataset.sisa || '0', 10) : 0;
        const max = Math.max(sisa, 1);
        input.max = max;
        input.title = 'Sisa di toko asal: ' + sisa;
        if ((parseInt(input.value || '0', 10) || 0) > sisa) {
            input.setCustomValidity('Qty melebihi sisa (' + sisa + '). Kurangi qty atau pilih produk lain.');
        } else {
            input.setCustomValidity('');
        }
        refreshSummary();
    }

    function addRow() {
        const wrap = document.createElement('div');
        wrap.className = 'row g-1 mb-1 align-items-center tr-row';
        wrap.innerHTML =
            '<div class="col-7"><select name="items[' + idx + '][product_id]" class="form-select form-select-sm tr-product" required>' + optionsHtml + '</select></div>' +
            '<div class="col-3"><input name="items[' + idx + '][qty]" type="number" min="1" max="100000" value="1" class="form-control form-control-sm tr-qty" required></div>' +
            '<div class="col-2"><button type="button" class="btn btn-sm btn-outline-danger w-100 tr-del" title="Hapus baris">×</button></div>';
        rowsEl.appendChild(wrap);
        idx++;
        const select = wrap.querySelector('.tr-product');
        const qty = wrap.querySelector('.tr-qty');
        select.addEventListener('change', () => clampQty(qty, select));
        qty.addEventListener('input', () => clampQty(qty, select));
        wrap.querySelector('.tr-del').addEventListener('click', () => {
            if (rowsEl.querySelectorAll('.tr-row').length <= 1) {
                qty.value = '1';
                clampQty(qty, select);
                return;
            }
            wrap.remove();
            refreshSummary();
        });
        clampQty(qty, select);
    }

    document.getElementById('addRow').addEventListener('click', addRow);
    addRow();

    const filter = document.getElementById('unitFilter');
    if (filter) {
        filter.addEventListener('input', () => {
            const q = filter.value.toLowerCase();
            document.querySelectorAll('#unitList .unit-row').forEach(el => {
                el.style.display = (!q || (el.dataset.key || '').includes(q)) ? '' : 'none';
            });
        });
    }
})();
</script>
@endsection
