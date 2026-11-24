@extends('layouts.tabler')
@section('title','Pengeluaran')
@section('content')
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <div class="page-pretitle">Keuangan</div>
            <h2 class="page-title">Pengeluaran Operasional</h2>
            <div class="text-secondary">Total periode: <b>Rp {{ number_format($total,0,',','.') }}</b></div>
        </div>
    </div>
</div>
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
            action="{{ route('expenses.import') }}"
            enctype="multipart/form-data"
            class="row g-2 align-items-end"
            onsubmit="return confirm('Import CSV pengeluaran sekarang? Setiap baris memotong saldo kas.')"
        >
            @csrf
            <div class="col-md-6">
                <label class="form-label">
                    Import CSV pengeluaran
                    <span class="text-secondary">
                        (kolom: tanggal,kategori,nominal,keterangan,akun_kas)
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
                    tanggal kosong = hari ini; akun_kas opsional (cocok nama akun, default akun pertama).
                    Setiap baris = 1 pengeluaran + 1 kas keluar normal (transaksi berjalan, BUKAN saldo awal).
                    Aturan bukti foto &gt;Rp1jt tidak berlaku di import (CSV tanpa file).
                </div>
            </div>
            <div class="col-md-3">
                <button class="btn btn-outline-primary w-100">
                    Import
                </button>
            </div>
            <div class="col-md-3">
                <a
                    href="{{ route('expenses.template') }}"
                    class="btn btn-outline-success w-100"
                >
                    <i class="ti ti-download me-1"></i> Unduh Template
                </a>
            </div>
        </form>
    </div>
</div>
<div class="row row-cards">
    <div class="col-lg-4">
        <div
            class="card"
            id="formPengeluaran"
        >
            <div class="card-header">
                <h3 class="card-title">Tambah Pengeluaran</h3>
            </div>
            <div class="card-body">
                @if(request()->filled('category') || request()->filled('amount'))
                    <div
                        class="alert alert-info py-2"
                        role="alert"
                    >
                        <div class="fw-bold">Mode ulangi pengeluaran</div>
                        <div class="small">Form terisi dari riwayat — periksa nominal lalu Simpan.</div>
                        <div class="mt-2">
                            <a
                                href="{{ route('expenses.index', request()->only(['kategori', 'bulan'])) }}"
                                class="btn btn-sm btn-outline-secondary"
                            >
                                Batalkan prefill
                            </a>
                        </div>
                    </div>
                @endif
                <form
                    method="POST"
                    action="{{ route('expenses.store') }}"
                    enctype="multipart/form-data"
                    onsubmit="return confirm('Simpan pengeluaran? Saldo kas dipotong.')"
                >
                    @csrf
                    <div class="mb-2">
                        <label class="form-label">Akun Kas (dipotong)</label>
                        <select
                            name="cash_account_id"
                            class="form-select"
                            required
                        >
                            @foreach($accounts as $a)
                                <option
                                    value="{{ $a->id }}"
                                    @selected((string) request('cash_account_id', old('cash_account_id')) === (string) $a->id)
                                >{{ $a->name }} (Rp {{ number_format($a->balance,0,',','.') }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Kategori</label>
                        <input
                            name="category"
                            class="form-control"
                            list="kategoriHistori"
                            placeholder="cth: listrik, sewa, gaji, ATK"
                            value="{{ old('category', request('category')) }}"
                            required
                        >
                        <datalist id="kategoriHistori">
                            @foreach($categoryList as $k)
                                <option value="{{ $k }}"></option>
                            @endforeach
                        </datalist>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-2">
                            <label class="form-label">Nominal</label>
                            <input
                                type="number"
                                name="amount"
                                class="form-control"
                                min="1"
                                value="{{ old('amount', request('amount')) }}"
                                required
                            >
                        </div>
                        <div class="col-6 mb-2">
                            <label class="form-label">Tanggal</label>
                            <input
                                type="date"
                                name="expense_date"
                                value="{{ old('expense_date', date('Y-m-d')) }}"
                                class="form-control"
                                required
                            >
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Keterangan</label>
                        <textarea
                            name="description"
                            class="form-control"
                            rows="2"
                        >{{ old('description', request('description')) }}</textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">
                            Foto nota (opsional, JPG/PNG maks 2MB)
                        </label>
                        <input
                            name="bukti"
                            type="file"
                            accept="image/*"
                            class="form-control"
                        >
                        <div class="form-hint mt-1">
                            Disimpan sebagai marker [BUKTI:path] di keterangan, tanpa kolom baru.
                        </div>
                    </div>
                    <button class="btn btn-primary w-100">Simpan</button>
                </form>
            </div>
        </div>
        @if($canSetBudget)
            <div class="card mt-3">
                <div class="card-header">
                    <h3 class="card-title">Set Budget Kategori — {{ $bulan }}</h3>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('expenses.budget') }}">
                        @csrf
                        <input type="hidden" name="bulan" value="{{ $bulan }}">
                        <div class="mb-2">
                            <label class="form-label">Kategori</label>
                            <input name="kategori" class="form-control" list="kategoriHistori2" placeholder="cth: listrik, sewa, gaji" required>
                            <datalist id="kategoriHistori2">
                                @foreach($categoryList as $k)
                                    <option value="{{ $k }}"></option>
                                @endforeach
                            </datalist>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Nominal Budget (Rp)</label>
                            <input type="number" name="nominal" class="form-control" min="0" step="5000" required>
                        </div>
                        <button class="btn btn-outline-primary w-100">Simpan Budget</button>
                        <div class="form-hint mt-1">Tersimpan per toko + bulan + kategori di storage/app/budget.json.</div>
                    </form>
                </div>
            </div>
        @endif
    </div>
    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-header">
                <h3 class="card-title">Ringkasan per Kategori — {{ $bulan }}</h3>
            </div>
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-md-5">
                        <canvas id="chartExpense" height="180"></canvas>
                    </div>
                    <div class="col-md-7">
                        <div class="table-responsive">
                            <table class="table table-sm card-table">
                                <thead>
                                    <tr>
                                        <th>Kategori</th>
                                        <th class="text-end">Transaksi</th>
                                        <th class="text-end">Total</th>
                                        <th class="text-end">Budget</th>
                                        <th style="min-width:140px">Realisasi</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($perCategory as $p)
                                        @php
                                            $bgt = (float) ($expenseBudgets[$p->category] ?? 0);
                                            $pct = $bgt > 0 ? round(((float) $p->total / $bgt) * 100, 1) : 0;
                                            $bar = (int) min(100, $pct);
                                            $over = $bgt > 0 && $pct > 90;
                                        @endphp
                                        <tr>
                                            <td>{{ $p->category }}</td>
                                            <td class="text-end">{{ $p->jml }}×</td>
                                            <td class="text-end">Rp {{ number_format($p->total,0,',','.') }}</td>
                                            <td class="text-end">
                                                @if($bgt > 0)
                                                    Rp {{ number_format($bgt,0,',','.') }}
                                                @else
                                                    <span class="text-secondary">—</span>
                                                @endif
                                            </td>
                                            <td>
                                                @if($bgt > 0)
                                                    <div class="progress progress-sm">
                                                        <div class="progress-bar {{ $over ? 'bg-danger' : 'bg-primary' }}" style="width: {{ $bar }}%"></div>
                                                    </div>
                                                    <small class="text-secondary">{{ $pct }}%</small>
                                                @else
                                                    <small class="text-secondary">Tanpa budget</small>
                                                @endif
                                            </td>
                                            <td>
                                                @if($bgt > 0)
                                                    @if($over)
                                                        <span class="badge bg-danger">Kritis &gt;90%</span>
                                                    @else
                                                        <span class="badge bg-success">Aman</span>
                                                    @endif
                                                @else
                                                    <span class="badge bg-secondary">No budget</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="6" class="text-center text-secondary">Belum ada data bulan ini.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="card mb-3">
            <div class="card-header">
                <h3 class="card-title">
                    Budget Tahunan — {{ $tahunAnnual }}
                </h3>
                <form
                    class="ms-auto d-flex gap-2 align-items-end"
                    method="GET"
                >
                    @if(request()->filled('kategori'))
                        <input
                            type="hidden"
                            name="kategori"
                            value="{{ request('kategori') }}"
                        >
                    @endif
                    @if(request()->filled('bulan'))
                        <input
                            type="hidden"
                            name="bulan"
                            value="{{ request('bulan') }}"
                        >
                    @endif
                    @if(request()->filled('q'))
                        <input
                            type="hidden"
                            name="q"
                            value="{{ request('q') }}"
                        >
                    @endif
                    <div>
                        <label class="form-label mb-0 small text-secondary">
                            Tahun
                        </label>
                        <input
                            type="number"
                            name="tahun"
                            class="form-control form-control-sm"
                            min="2020"
                            max="2100"
                            value="{{ $tahunAnnual }}"
                        >
                    </div>
                    <button class="btn btn-sm btn-outline-primary">
                        Tampilkan
                    </button>
                </form>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-vcenter card-table mb-0">
                        <thead>
                            <tr>
                                <th>
                                    Kategori
                                </th>
                                <th>
                                    Bulan
                                </th>
                                <th class="text-end">
                                    Budget
                                </th>
                                <th class="text-end">
                                    Realisasi
                                </th>
                                <th class="text-end">
                                    Sisa
                                </th>
                                <th class="text-end">
                                    %
                                </th>
                                <th>
                                    Status
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            @php
                                $namaBulan = [
                                    1 => 'Jan', 2 => 'Feb', 3 => 'Mar',
                                    4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
                                    7 => 'Jul', 8 => 'Agu', 9 => 'Sep',
                                    10 => 'Okt', 11 => 'Nov', 12 => 'Des',
                                ];
                            @endphp
                            @forelse($annualRows as $row)
                                @foreach($row['months'] as $m => $cell)
                                    <tr @if($m === 12) class="table-group-divider" @endif>
                                        @if($m === 1)
                                            <td
                                                rowspan="13"
                                                class="fw-bold align-top"
                                            >
                                                <div>
                                                    {{ $row['kategori'] }}
                                                </div>
                                                <div class="small text-secondary mt-1">
                                                    Setahun:
                                                    Rp {{ number_format($row['total_budget'], 0, ',', '.') }}
                                                    /
                                                    Rp {{ number_format($row['total_realisasi'], 0, ',', '.') }}
                                                    ({{ $row['total_pct'] }}%)
                                                </div>
                                                <div class="mt-1">
                                                    @if($row['total_status'] === 'over')
                                                        <span class="badge bg-danger">
                                                            Over budget
                                                        </span>
                                                    @elseif($row['total_status'] === 'kritis')
                                                        <span class="badge bg-warning">
                                                            Kritis &gt;90%
                                                        </span>
                                                    @elseif($row['total_status'] === 'aman')
                                                        <span class="badge bg-success">
                                                            Aman
                                                        </span>
                                                    @else
                                                        <span class="badge bg-secondary">
                                                            No budget
                                                        </span>
                                                    @endif
                                                </div>
                                            </td>
                                        @endif
                                        <td>
                                            {{ $namaBulan[$m] ?? $m }}
                                        </td>
                                        <td class="text-end">
                                            @if($cell['budget'] > 0)
                                                Rp {{ number_format($cell['budget'], 0, ',', '.') }}
                                            @else
                                                <span class="text-secondary">
                                                    —
                                                </span>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            Rp {{ number_format($cell['realisasi'], 0, ',', '.') }}
                                        </td>
                                        <td class="text-end">
                                            @if($cell['budget'] > 0)
                                                Rp {{ number_format($cell['selisih'], 0, ',', '.') }}
                                            @else
                                                <span class="text-secondary">
                                                    —
                                                </span>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            @if($cell['budget'] > 0)
                                                {{ $cell['pct'] }}%
                                            @else
                                                <span class="text-secondary">
                                                    —
                                                </span>
                                            @endif
                                        </td>
                                        <td>
                                            @if($cell['status'] === 'over')
                                                <span class="badge bg-danger">
                                                    Over
                                                </span>
                                            @elseif($cell['status'] === 'kritis')
                                                <span class="badge bg-warning">
                                                    Kritis
                                                </span>
                                            @elseif($cell['status'] === 'aman')
                                                <span class="badge bg-success">
                                                    Aman
                                                </span>
                                            @else
                                                <span class="badge bg-secondary">
                                                    No budget
                                                </span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                                <tr class="table-light fw-bold">
                                    <td colspan="2">
                                        Total {{ $row['kategori'] }} setahun
                                    </td>
                                    <td class="text-end">
                                        Rp {{ number_format($row['total_budget'], 0, ',', '.') }}
                                    </td>
                                    <td class="text-end">
                                        Rp {{ number_format($row['total_realisasi'], 0, ',', '.') }}
                                    </td>
                                    <td class="text-end">
                                        Rp {{ number_format($row['total_selisih'], 0, ',', '.') }}
                                    </td>
                                    <td class="text-end">
                                        {{ $row['total_pct'] }}%
                                    </td>
                                    <td></td>
                                </tr>
                            @empty
                                <tr>
                                    <td
                                        colspan="7"
                                        class="text-center text-secondary"
                                    >
                                        Belum ada budget maupun realisasi tahun {{ $tahunAnnual }}.
                                        Set budget per bulan lewat formulir di samping.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        @if(!empty($annualRows))
                            <tfoot>
                                <tr class="fw-bold">
                                    <td colspan="2">
                                        Grand total {{ $tahunAnnual }}
                                    </td>
                                    <td class="text-end">
                                        Rp {{ number_format($annualTotalBudget, 0, ',', '.') }}
                                    </td>
                                    <td class="text-end">
                                        Rp {{ number_format($annualTotalReal, 0, ',', '.') }}
                                    </td>
                                    <td class="text-end">
                                        Rp {{ number_format($annualTotalBudget - $annualTotalReal, 0, ',', '.') }}
                                    </td>
                                    <td class="text-end">
                                        @if($annualTotalBudget > 0)
                                            {{ round($annualTotalReal / $annualTotalBudget * 100, 1) }}%
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
                <div class="px-3 py-2 text-secondary small">
                    Sumber budget: storage/app/budget.json per toko + bulan + kategori
                    (pola sama seperti targets.json dashboard per bulan, diagregat Jan–Des).
                    Status: Aman (&le;90%), Kritis (&gt;90%), Over (&gt;100%), No budget (belum diset).
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Riwayat</h3>
                <form
                    class="ms-auto d-flex gap-2"
                    method="GET"
                >
                    <input
                        type="hidden"
                        name="tahun"
                        value="{{ $tahunAnnual }}"
                    >
                    <input
                        name="q"
                        value="{{ request('q') }}"
                        class="form-control form-control-sm"
                        placeholder="Cari keterangan"
                    >
                    <input
                        name="kategori"
                        value="{{ request('kategori') }}"
                        class="form-control form-control-sm"
                        placeholder="Kategori"
                    >
                    <input
                        type="month"
                        name="bulan"
                        value="{{ request('bulan') }}"
                        class="form-control form-control-sm"
                    >
                    <button class="btn btn-sm btn-outline-primary">
                        Filter
                    </button>
                    <a
                        href="{{ route('expenses.index', array_merge(request()->only(['q', 'kategori', 'bulan', 'tahun']), ['export' => 'csv'])) }}"
                        class="btn btn-sm btn-outline-success"
                    >
                        Export CSV
                    </a>
                </form>
            </div>
            <div
                class="px-3 pt-3"
            >
                <form
                    id="bulkCategoryForm"
                    method="POST"
                    action="{{ route('expenses.bulkCategory') }}"
                    onsubmit="return confirmBulkCategory()"
                >
                    @csrf
                    <div
                        class="d-flex flex-wrap gap-2 align-items-end"
                    >
                        <div>
                            <label
                                class="form-label mb-0 small text-secondary"
                                for="bulkCategorySelect"
                            >
                                Ubah kategori terpilih
                            </label>
                            <select
                                id="bulkCategorySelect"
                                name="category"
                                class="form-select form-select-sm"
                                list="kategoriHistoriBulk"
                                required
                            >
                                <option
                                    value=""
                                >
                                    — Pilih kategori —
                                </option>
                                @foreach($categoryList as $k)
                                    <option
                                        value="{{ $k }}"
                                    >
                                        {{ $k }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <button
                            type="submit"
                            class="btn btn-sm btn-outline-primary"
                        >
                            Ubah Kategori Terpilih
                        </button>
                        <span
                            id="bulkCategoryCount"
                            class="small text-secondary"
                        >
                            0 dipilih (maks 100)
                        </span>
                    </div>
                </form>
            </div>
            <div class="table-responsive">
                @if(($unusualCount ?? 0) > 0)
                    <div
                        class="alert alert-warning mx-3 mt-3 mb-0"
                        role="alert"
                    >
                        <div class="fw-bold">
                            <i class="ti ti-alert-triangle me-1"></i> Pengeluaran tak biasa: {{ $unusualCount }} transaksi &gt; {{ $unusualThreshold }}× rata-rata kategorinya ({{ $unusualWindowDays }} hari)
                        </div>
                        <ul class="mb-1 ps-3 small">
                            @foreach($expenses as $ringkas)
                                @if(isset($unusualMap[$ringkas->id]))
                                    <li>
                                        <b>{{ $ringkas->category }}</b>
                                        — Rp {{ number_format($ringkas->amount, 0, ',', '.') }}
                                        ({{ $ringkas->expense_date?->format('d/m/Y') }})
                                        vs rata-rata Rp {{ number_format($unusualMap[$ringkas->id]['avg'], 0, ',', '.') }}
                                        ({{ $unusualMap[$ringkas->id]['ratio'] }}×, {{ $unusualMap[$ringkas->id]['count'] }} transaksi baseline).
                                    </li>
                                @endif
                            @endforeach
                        </ul>
                        <div class="small text-secondary">
                            Ambang: nominal &gt; {{ $unusualThreshold }}× rata-rata kategori {{ $unusualWindowDays }} hari terakhir
                            (min {{ $unusualMinSample }} transaksi baseline). Badge “Tak biasa” muncul di kolom Nominal.
                            Keputusan: ringkasan tampil di halaman ini saja, tidak di widget dashboard “Perlu Perhatian”
                            (menghindari query agregat tambahan di dashboard + widget sudah memuat 6 jenis alert).
                        </div>
                    </div>
                @endif
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th>
                                <input
                                    id="checkAllExpenses"
                                    type="checkbox"
                                    class="form-check-input"
                                    title="Pilih semua di halaman ini"
                                >
                            </th>
                            <th>Tgl</th>
                            <th>Kategori</th>
                            <th class="text-end">Nominal</th>
                            <th>Keterangan</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($expenses as $e)
                            @php
                                $buktiPath = null;
                                if (preg_match('/\[BUKTI:(.*?)\]/', $e->description ?? '', $m)) {
                                    $buktiPath = trim($m[1]);
                                }
                                $descBersih = trim(preg_replace('/\[BUKTI:.*?\]/', '', $e->description ?? ''));
                            @endphp
                            <tr>
                                <td>
                                    <input
                                        type="checkbox"
                                        name="ids[]"
                                        value="{{ $e->id }}"
                                        form="bulkCategoryForm"
                                        class="form-check-input expense-check"
                                    >
                                </td>
                                <td>{{ $e->expense_date?->format('d/m/Y') }}</td>
                                <td>{{ $e->category }}</td>
                                <td class="text-end">
                                    <div>
                                        Rp {{ number_format($e->amount,0,',','.') }}
                                    </div>
                                    @if(isset($unusualMap[$e->id]))
                                        <div class="mt-1">
                                            <span
                                                class="badge bg-warning"
                                                title="Rata-rata {{ $e->category }} 90 hari: Rp {{ number_format($unusualMap[$e->id]['avg'], 0, ',', '.') }} ({{ $unusualMap[$e->id]['count'] }} transaksi)"
                                            >
                                                Tak biasa {{ $unusualMap[$e->id]['ratio'] }}×
                                            </span>
                                        </div>
                                        <div class="small text-secondary">
                                            &gt; {{ $unusualThreshold }}× rata-rata (Rp {{ number_format($unusualMap[$e->id]['avg'], 0, ',', '.') }})
                                        </div>
                                    @endif
                                </td>
                                <td class="text-secondary">
                                    @if($descBersih)
                                        <div>{{ $descBersih }}</div>
                                    @endif
                                    @if($buktiPath)
                                        <div class="mt-1">
                                            <a
                                                href="{{ asset('storage/' . $buktiPath) }}"
                                                target="_blank"
                                            >
                                                <img
                                                    src="{{ asset('storage/' . $buktiPath) }}"
                                                    alt="Bukti nota pengeluaran"
                                                    class="rounded border"
                                                    style="width: 72px; height: 72px; object-fit: cover;"
                                                    loading="lazy"
                                                >
                                            </a>
                                            <div>
                                                <a
                                                    href="{{ asset('storage/' . $buktiPath) }}"
                                                    target="_blank"
                                                    class="small"
                                                >
                                                    Lihat nota
                                                </a>
                                            </div>
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <a
                                            href="{{ route('expenses.index', array_merge(request()->only(['kategori', 'bulan']), ['category' => $e->category, 'amount' => $e->amount, 'description' => $descBersih, 'cash_account_id' => $expenseAccounts[$e->id] ?? null])) }}#formPengeluaran"
                                            class="btn btn-sm btn-outline-primary"
                                            title="Ulangi pengeluaran ini (prefill form)"
                                        >
                                            Ulangi
                                        </a>
                                        <form
                                            method="POST"
                                            action="{{ route('expenses.destroy',$e) }}"
                                            onsubmit="return confirm('Hapus & kembalikan saldo kas?')"
                                        >
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-sm btn-outline-danger">Hapus</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <div class="empty py-3">
                                        <div class="empty-icon"><i class="ti ti-coin fs-1 text-secondary"></i></div>
                                        <p class="empty-title">Belum ada pengeluaran</p>
                                        <p class="empty-subtitle text-secondary">Tambahkan lewat formulir di samping — ringkasan per kategori tampil otomatis.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer">
                {{ $expenses->links() }}
                <div class="small text-secondary mt-2">
                    Badge “Tak biasa” = nominal &gt; {{ $unusualThreshold }}× rata-rata kategorinya
                    {{ $unusualWindowDays }} hari terakhir (min {{ $unusualMinSample }} transaksi baseline per kategori).
                </div>
            </div>
        </div>
    </div>
</div>
@push('js')
<script src="/vendor/chart/chart.umd.min.js"></script>
<script>try{
const ec=document.getElementById('chartExpense');
if(ec) new Chart(ec,{type:'doughnut',data:{labels:@json($perCategory->pluck('category')),datasets:[{data:@json($perCategory->pluck('total')->map(fn($v)=>(float)$v)),backgroundColor:['#206bc4','#79a6dc','#e74c3c','#f59f00','#2fb344','#5b5bd6','#e83e8c','#6c757d','#20c997','#fd7e14']}]},options:{plugins:{legend:{position:'bottom'}}}});
}catch(e){console.error(e);}</script>
<script>try{
const bulkForm=document.getElementById('bulkCategoryForm');
const checkAll=document.getElementById('checkAllExpenses');
const countEl=document.getElementById('bulkCategoryCount');
const catSel=document.getElementById('bulkCategorySelect');
function expenseChecks(){return Array.from(document.querySelectorAll('.expense-check'));}
function refreshBulkCount(){
    const n=expenseChecks().filter(c=>c.checked).length;
    if(countEl) countEl.textContent=n+' dipilih (maks 100)';
    if(checkAll){
        const all=expenseChecks();
        checkAll.checked=all.length>0&&all.every(c=>c.checked);
    }
}
if(checkAll) checkAll.addEventListener('change',()=>{
    expenseChecks().forEach(c=>{c.checked=checkAll.checked;});
    refreshBulkCount();
});
document.querySelectorAll('.expense-check').forEach(c=>c.addEventListener('change',refreshBulkCount));
window.confirmBulkCategory=function(){
    const checked=expenseChecks().filter(c=>c.checked);
    if(checked.length===0){alert('Pilih minimal 1 pengeluaran.');return false;}
    if(checked.length>100){alert('Maksimal 100 pengeluaran per aksi ('+checked.length+' dipilih).');return false;}
    if(bulkForm&&!bulkForm.category.value){alert('Pilih kategori tujuan.');return false;}
    return confirm('Ubah kategori '+checked.length+' pengeluaran ke "'+(catSel?catSel.value:'')+'"?');
};
refreshBulkCount();
}catch(e){console.error(e);}</script>
@endpush
@endsection
