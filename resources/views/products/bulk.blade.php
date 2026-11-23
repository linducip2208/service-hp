@extends('layouts.tabler')
@section('title', 'Ubah Harga Massal')
@section('content')
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <div class="page-pretitle">Inventory</div>
            <h2 class="page-title">Ubah Harga / Status Massal</h2>
        </div>
        <div class="col-auto">
            <a href="{{ route('products.index') }}" class="btn btn-outline-secondary">← Produk</a>
        </div>
    </div>
</div>

@if (session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if ($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">
            @foreach ($errors->all() as $e)
                <li>{{ $e }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2">
            <div class="col-md-3">
                <input
                    name="q"
                    value="{{ request('q') }}"
                    class="form-control"
                    placeholder="Nama / SKU…"
                >
            </div>
            <div class="col-md-2">
                <select name="type" class="form-select">
                    <option value="">Semua tipe</option>
                    @foreach (['hp_baru', 'hp_bekas', 'refurbished', 'aksesoris', 'sparepart', 'pulsa', 'paket_data', 'lain'] as $t)
                        <option value="{{ $t }}" {{ request('type') === $t ? 'selected' : '' }}>
                            {{ $t }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <select name="brand_id" class="form-select">
                    <option value="">Semua merek</option>
                    @foreach ($brands as $b)
                        <option
                            value="{{ $b->id }}"
                            {{ (string) request('brand_id') === (string) $b->id ? 'selected' : '' }}
                        >
                            {{ $b->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <select name="category_id" class="form-select">
                    <option value="">Semua kategori</option>
                    @foreach ($categories as $c)
                        <option
                            value="{{ $c->id }}"
                            {{ (string) request('category_id') === (string) $c->id ? 'selected' : '' }}
                        >
                            {{ $c->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-secondary w-100">Filter</button>
            </div>
        </form>
    </div>
</div>

<form
    method="POST"
    action="{{ route('products.bulk.update') }}"
    id="bulkForm"
    onsubmit="return confirm('Terapkan perubahan ke produk tercentang?')"
>
    @csrf
    <input type="hidden" name="type" value="{{ request('type') }}">
    <input type="hidden" name="brand_id" value="{{ request('brand_id') }}">
    <input type="hidden" name="category_id" value="{{ request('category_id') }}">
    <input type="hidden" name="q" value="{{ request('q') }}">

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label" for="bulkMode">Mode</label>
                    <select name="mode" id="bulkMode" class="form-select" required>
                        <option value="percent_up">Naik %</option>
                        <option value="percent_down">Turun %</option>
                        <option value="nominal_up">Naik nominal (Rp)</option>
                        <option value="nominal_down">Turun nominal (Rp)</option>
                        <option value="margin">Set margin dari harga beli (%)</option>
                        <option value="status">Ubah status (aktif / nonaktif)</option>
                    </select>
                </div>
                <div class="col-md-2" id="priceValueWrap">
                    <label class="form-label" for="bulkValue">Nilai</label>
                    <input
                        type="number"
                        name="value"
                        id="bulkValue"
                        class="form-control"
                        min="0"
                        step="any"
                        value="10"
                    >
                </div>
                <div class="col-md-2 d-none" id="statusValueWrap">
                    <label class="form-label" for="statusValue">Status tujuan</label>
                    <select name="status_value" id="statusValue" class="form-select">
                        <option value="1">Aktifkan</option>
                        <option value="0">Nonaktifkan</option>
                    </select>
                </div>
                <div class="col-md-3" id="allowBelowWrap">
                    <label class="form-check mt-4">
                        <input
                            type="checkbox"
                            name="allow_below"
                            value="1"
                            id="allowBelow"
                            class="form-check-input"
                        >
                        <span class="form-check-label">Izinkan harga di bawah modal</span>
                    </label>
                </div>
                <div class="col-md-2">
                    <div class="small text-secondary" id="bulkSummary">—</div>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-primary w-100">Terapkan</button>
                </div>
            </div>
            <div class="small text-secondary mt-2" id="bulkHint">
                Tanpa centang "izinkan", produk yang hasil barunya &lt; harga beli otomatis
                ditolak server. Perubahan harga bukan mutasi stok (tidak mencatat stock_movements).
                Produk nonaktif otomatis hilang dari POS.
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label
                        class="form-label"
                        for="assignBrand"
                    >
                        Merek tujuan
                    </label>
                    <select
                        name="assign_brand_id"
                        id="assignBrand"
                        class="form-select"
                    >
                        <option value="">
                            — Jangan ubah merek —
                        </option>
                        @foreach ($brands as $b)
                            <option value="{{ $b->id }}">
                                {{ $b->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label
                        class="form-label"
                        for="assignCategory"
                    >
                        Kategori tujuan
                    </label>
                    <select
                        name="assign_category_id"
                        id="assignCategory"
                        class="form-select"
                    >
                        <option value="">
                            — Jangan ubah kategori —
                        </option>
                        @foreach ($categories as $c)
                            <option value="{{ $c->id }}">
                                {{ $c->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <button
                        type="submit"
                        class="btn btn-outline-primary w-100"
                        formaction="{{ route('products.bulk.assign') }}"
                    >
                        Set Merek / Kategori
                    </button>
                    <div class="small text-secondary mt-1">
                        Berlaku untuk produk tercentang (maks 100).
                        Isi salah satu atau keduanya; yang kosong tidak diubah.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label
                        class="form-label"
                        for="assignMinStock"
                    >
                        Min. stok global
                    </label>
                    <input
                        type="number"
                        name="assign_min_stock"
                        id="assignMinStock"
                        class="form-control"
                        min="0"
                        max="1000000"
                        step="1"
                        placeholder="cth: 5"
                    >
                </div>
                <div class="col-md-4">
                    <div
                        class="small text-secondary"
                        id="minGlobalCount"
                    >
                        Berlaku untuk produk tercentang (maks 100).
                    </div>
                </div>
                <div class="col-md-4">
                    <button
                        type="submit"
                        class="btn btn-outline-warning w-100"
                        formaction="{{ route('products.bulk.assign') }}"
                        onclick="return confirmMinGlobal(event)"
                    >
                        Set Min Global
                    </button>
                    <div class="small text-secondary mt-1">
                        Mengubah products.min_stock (global).
                        Min. per-toko yang sudah diset tetap menang
                        (efektif = per-toko ?? global).
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="bulkTable">
                <thead>
                    <tr>
                        <th>
                            <input type="checkbox" id="checkAll" checked>
                        </th>
                        <th>Produk</th>
                        <th class="text-end">Beli</th>
                        <th class="text-end">Jual kini</th>
                        <th class="text-end">Jual baru (preview)</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($products as $p)
                        <tr
                            data-buy="{{ (float) $p->purchase_price }}"
                            data-sell="{{ (float) $p->selling_price }}"
                        >
                            <td>
                                <input
                                    type="checkbox"
                                    name="ids[]"
                                    value="{{ $p->id }}"
                                    class="rowCheck"
                                    checked
                                >
                            </td>
                            <td>
                                {{ $p->name }}
                                <div class="small text-secondary font-monospace">
                                    {{ $p->sku }} · {{ $p->brand->name ?? '-' }} · {{ $p->category->name ?? $p->type }}
                                    @unless ($p->is_active)
                                        <span class="badge bg-red-lt">nonaktif</span>
                                    @endunless
                                </div>
                            </td>
                            <td class="text-end">
                                {{ number_format($p->purchase_price, 0, ',', '.') }}
                            </td>
                            <td class="text-end">
                                {{ number_format($p->selling_price, 0, ',', '.') }}
                            </td>
                            <td class="text-end fw-bold preview">—</td>
                            <td>
                                @if ($p->is_active)
                                    <span class="badge bg-green-lt">aktif</span>
                                @else
                                    <span class="badge bg-red-lt">nonaktif</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-secondary py-4">
                                Tidak ada produk — ubah filter di atas.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</form>

<script>
(function () {
    const mode = document.getElementById('bulkMode');
    const val = document.getElementById('bulkValue');
    const allow = document.getElementById('allowBelow');
    const sum = document.getElementById('bulkSummary');
    const hint = document.getElementById('bulkHint');
    const priceWrap = document.getElementById('priceValueWrap');
    const statusWrap = document.getElementById('statusValueWrap');
    const statusSel = document.getElementById('statusValue');
    const allowWrap = document.getElementById('allowBelowWrap');
    const fmt = (n) => Math.round(n).toLocaleString('id-ID');

    function isStatusMode() {
        return mode.value === 'status';
    }

    function calc(buy, sell) {
        const v = parseFloat(val.value) || 0;
        const m = mode.value;
        let n = sell;
        if (m === 'percent_up') n = sell * (1 + v / 100);
        else if (m === 'percent_down') n = sell * (1 - v / 100);
        else if (m === 'nominal_up') n = sell + v;
        else if (m === 'nominal_down') n = sell - v;
        else if (m === 'margin') n = buy * (1 + v / 100);
        return Math.max(0, Math.round(n));
    }

    function refresh() {
        const statusMode = isStatusMode();
        priceWrap.classList.toggle('d-none', statusMode);
        statusWrap.classList.toggle('d-none', !statusMode);
        allowWrap.classList.toggle('d-none', statusMode);
        val.required = !statusMode;

        if (statusMode) {
            hint.textContent = 'Mode status: produk tercentang akan ' +
                (statusSel.value === '1' ? 'DIAKTIFKAN' : 'DINONAKTIFKAN') +
                ' sekaligus (satu transaction). Produk nonaktif otomatis hilang dari POS.';
            document.querySelectorAll('#bulkTable tbody tr[data-buy]').forEach((tr) => {
                const cell = tr.querySelector('.preview');
                if (!tr.querySelector('.rowCheck').checked) {
                    cell.textContent = '—';
                    return;
                }
                cell.textContent = statusSel.value === '1' ? '→ aktif' : '→ nonaktif';
            });
            const n = document.querySelectorAll('#bulkTable tbody tr[data-buy] .rowCheck:checked').length;
            sum.textContent = n + ' akan diubah statusnya';
            return;
        }

        hint.textContent = 'Tanpa centang "izinkan", produk yang hasil barunya < harga beli ' +
            'otomatis ditolak server. Perubahan harga bukan mutasi stok ' +
            '(tidak mencatat stock_movements).';

        let blocked = 0;
        let ok = 0;
        document.querySelectorAll('#bulkTable tbody tr[data-buy]').forEach((tr) => {
            const cell = tr.querySelector('.preview');
            if (!tr.querySelector('.rowCheck').checked) {
                cell.textContent = '—';
                cell.classList.remove('text-danger', 'text-success');
                return;
            }
            const buy = parseFloat(tr.dataset.buy);
            const sell = parseFloat(tr.dataset.sell);
            const n = calc(buy, sell);
            cell.textContent = 'Rp ' + fmt(n);
            const below = n < buy;
            cell.classList.toggle('text-danger', below);
            cell.classList.toggle('text-success', !below);
            if (below && !allow.checked) blocked++;
            else ok++;
        });
        sum.textContent = ok + ' akan diubah' + (blocked ? ', ' + blocked + ' ditolak (< modal)' : '');
    }

    [mode, val, allow, statusSel].forEach((el) => el.addEventListener('input', refresh));
    statusSel.addEventListener('change', refresh);
    document.getElementById('checkAll').addEventListener('change', (e) => {
        document.querySelectorAll('.rowCheck').forEach((c) => { c.checked = e.target.checked; });
        refresh();
    });
    document.querySelectorAll('.rowCheck').forEach((c) => c.addEventListener('change', refresh));
    refresh();

    const minInput = document.getElementById('assignMinStock');
    const minCount = document.getElementById('minGlobalCount');

    function refreshMinCount() {
        const n = document.querySelectorAll('#bulkTable tbody tr[data-buy] .rowCheck:checked').length;
        minCount.textContent = n + ' produk tercentang akan diset min. globalnya' +
            (minInput.value !== '' ? ' → ' + minInput.value : '.');
    }

    minInput.addEventListener('input', refreshMinCount);
    document.querySelectorAll('.rowCheck').forEach((c) => c.addEventListener('change', refreshMinCount));
    document.getElementById('checkAll').addEventListener('change', refreshMinCount);
    refreshMinCount();

    window.confirmMinGlobal = function (e) {
        const checked = document.querySelectorAll('#bulkTable tbody tr[data-buy] .rowCheck:checked').length;
        if (!checked) {
            alert('Centang dulu minimal 1 produk.');
            e.preventDefault();
            return false;
        }
        if (minInput.value === '' || Number(minInput.value) < 0) {
            alert('Isi min. stok global dulu (angka ≥ 0).');
            e.preventDefault();
            return false;
        }
        return confirm('Set min. global = ' + minInput.value + ' untuk ' + checked + ' produk?');
    };
})();
</script>
@endsection
