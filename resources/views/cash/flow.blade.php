@extends('layouts.tabler')

@section('title', 'Arus Kas')

@section('content')
@php
    $mode = $mode ?? 'bulan';
    $isTahun = $mode === 'tahun';
    $judulPeriode = $isTahun
        ? ('Tahun ' . ($tahun ?? date('Y')))
        : date('F Y', strtotime(($bulan ?? date('Y-m')) . '-01'));
@endphp

<div class="page-header d-print-none">
    <div class="row align-items-center">
        <div class="col">
            <div class="page-pretitle">Keuangan</div>
            <h2 class="page-title">Arus Kas — {{ $judulPeriode }}</h2>
            <div class="text-secondary">
                @if($isTahun)
                    Agregat per bulan Jan–Des (kumulatif dihitung dalam tahun ini)
                @else
                    Rekap harian + ringkasan per kategori (kumulatif dihitung dalam bulan ini)
                @endif
            </div>
        </div>
        <div class="col-auto d-flex gap-2 flex-wrap">
            <a href="{{ route('cash.index') }}" class="btn btn-outline-secondary">
                ← Kas
            </a>
            <button class="btn btn-outline-secondary" onclick="window.print()">
                <i class="ti ti-printer me-1"></i> Cetak
            </button>
            @if($isTahun)
                <a
                    href="{{ route('cash.flow', ['tahun' => $tahun, 'export' => 'csv']) }}"
                    class="btn btn-outline-success"
                >
                    <i class="ti ti-download me-1"></i> CSV
                </a>
            @else
                <a
                    href="{{ route('cash.flow', ['bulan' => $bulan, 'export' => 'csv']) }}"
                    class="btn btn-outline-success"
                >
                    <i class="ti ti-download me-1"></i> CSV
                </a>
            @endif
        </div>
    </div>
</div>

{{-- Filter: dua mode dalam satu kartu, masing-masing form GET terpisah --}}
<div class="card mb-3 d-print-none">
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-md-5">
                <form method="GET" action="{{ route('cash.flow') }}" class="row g-2 align-items-end">
                    <div class="col-8">
                        <label class="form-label">Mode bulan</label>
                        <input
                            type="month"
                            name="bulan"
                            value="{{ $isTahun ? date('Y-m') : $bulan }}"
                            class="form-control"
                        >
                    </div>
                    <div class="col-4">
                        <button class="btn btn-primary w-100">Bulan</button>
                    </div>
                </form>
            </div>
            <div class="col-md-5">
                <form method="GET" action="{{ route('cash.flow') }}" class="row g-2 align-items-end">
                    <div class="col-8">
                        <label class="form-label">Mode tahun</label>
                        <input
                            type="number"
                            name="tahun"
                            min="2000"
                            max="2100"
                            value="{{ $isTahun ? $tahun : date('Y') }}"
                            class="form-control"
                            placeholder="YYYY"
                        >
                    </div>
                    <div class="col-4">
                        <button class="btn btn-outline-primary w-100">Tahun</button>
                    </div>
                </form>
            </div>
            <div class="col-md-2">
                @if($isTahun)
                    <a href="{{ route('cash.flow') }}" class="btn btn-ghost-secondary w-100">
                        ← Mode bulan
                    </a>
                @else
                    <a
                        href="{{ route('cash.flow', ['tahun' => substr($bulan ?? date('Y-m'), 0, 4)]) }}"
                        class="btn btn-ghost-secondary w-100"
                    >
                        Mode tahun →
                    </a>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- Kartu ringkasan --}}
<div class="row row-cards mb-3">
    <div class="col-sm-6 col-lg-3">
        <div class="card bg-green-lt">
            <div class="card-body">
                <div class="text-secondary">Total Masuk</div>
                <div class="h2 mt-1">
                    Rp {{ number_format($totalMasuk, 0, ',', '.') }}
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card bg-red-lt">
            <div class="card-body">
                <div class="text-secondary">Total Keluar</div>
                <div class="h2 mt-1">
                    Rp {{ number_format($totalKeluar, 0, ',', '.') }}
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card">
            <div class="card-body">
                <div class="text-secondary">
                    {{ $isTahun ? 'Net Tahun Ini' : 'Net Bulan Ini' }}
                </div>
                <div class="h2 mt-1 text-{{ $netTotal >= 0 ? 'success' : 'danger' }}">
                    {{ ($netTotal >= 0 ? '+' : '') . 'Rp ' . number_format($netTotal, 0, ',', '.') }}
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card">
            <div class="card-body">
                <div class="text-secondary">
                    {{ $isTahun ? 'Kumulatif Akhir Tahun' : 'Kumulatif Akhir Bulan' }}
                </div>
                @php
                    $kumAkhir = $isTahun
                        ? (!empty($months) ? end($months)['kumulatif'] : 0)
                        : (!empty($days) ? end($days)['kumulatif'] : 0);
                @endphp
                <div class="h2 mt-1">
                    {{ ($kumAkhir >= 0 ? '+' : '') . 'Rp ' . number_format($kumAkhir, 0, ',', '.') }}
                </div>
                <div class="text-secondary small">
                    = net {{ $isTahun ? 'tahun' : 'bulan' }} ini (mulai dari 0 tiap awal periode)
                </div>
            </div>
        </div>
    </div>
</div>

@if($isTahun)
    {{-- ================= MODE TAHUN ================= --}}

    <div class="card mb-3">
        <div class="card-header">
            <h3 class="card-title">Grafik Arus Kas Bulanan — {{ $tahun }}</h3>
        </div>
        <div class="card-body">
            <canvas id="chartFlowTahun" height="110"></canvas>
            <div class="text-secondary small mt-2">
                Bar = masuk vs keluar per bulan, garis = net kumulatif berjalan Jan–Des.
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">
            <h3 class="card-title">Rincian per Akun Kas — {{ $tahun }}</h3>
        </div>
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th>Akun</th>
                        <th class="text-end">Masuk</th>
                        <th class="text-end">Keluar</th>
                        <th class="text-end">Net</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($perAkun ?? [] as $a)
                        @php
                            $isTopAkun = ($akunTopId ?? null) !== null
                                && (int) $a['id'] === (int) ($akunTopId ?? 0);
                        @endphp
                        <tr class="{{ $isTopAkun ? 'table-primary' : '' }}">
                            <td>
                                {{ $a['name'] }}
                                @if($isTopAkun)
                                    <span class="badge bg-blue-lt ms-1">
                                        ★ Terbesar
                                    </span>
                                @endif
                            </td>
                            <td class="text-end text-success">
                                +{{ number_format($a['masuk'], 0, ',', '.') }}
                            </td>
                            <td class="text-end text-danger">
                                -{{ number_format($a['keluar'], 0, ',', '.') }}
                            </td>
                            <td class="text-end">
                                <span class="badge bg-{{ $a['net'] >= 0 ? 'green' : 'red' }}-lt">
                                    {{ ($a['net'] > 0 ? '+' : '') . number_format($a['net'], 0, ',', '.') }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-secondary">
                                Belum ada transaksi tahun ini.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer text-secondary small">
            Akun terbesar = arus masuk tertinggi tahun ini (baris disorot).
        </div>
    </div>

    <div class="row row-cards">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Tabel Bulanan — {{ $tahun }}</h3>
                </div>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th>Bulan</th>
                                <th class="text-end">Masuk</th>
                                <th class="text-end">Keluar</th>
                                <th class="text-end">Net</th>
                                <th class="text-end">Kumulatif</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($months as $r)
                                <tr>
                                    <td>
                                        <a href="{{ route('cash.flow', ['bulan' => $r['kode']]) }}">
                                            {{ $r['nama'] }}
                                        </a>
                                        <span class="text-secondary small">{{ $r['kode'] }}</span>
                                    </td>
                                    <td class="text-end text-success">
                                        +{{ number_format($r['masuk'], 0, ',', '.') }}
                                    </td>
                                    <td class="text-end text-danger">
                                        -{{ number_format($r['keluar'], 0, ',', '.') }}
                                    </td>
                                    <td class="text-end">
                                        <span class="badge bg-{{ $r['net'] >= 0 ? 'green' : 'red' }}-lt">
                                            {{ ($r['net'] > 0 ? '+' : '') . number_format($r['net'], 0, ',', '.') }}
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <b>{{ ($r['kumulatif'] > 0 ? '+' : '') . number_format($r['kumulatif'], 0, ',', '.') }}</b>
                                    </td>
                                </tr>
                            @endforeach
                            <tr class="table-light">
                                <td><b>TOTAL</b></td>
                                <td class="text-end text-success">
                                    <b>+{{ number_format($totalMasuk, 0, ',', '.') }}</b>
                                </td>
                                <td class="text-end text-danger">
                                    <b>-{{ number_format($totalKeluar, 0, ',', '.') }}</b>
                                </td>
                                <td class="text-end">
                                    <b>{{ ($netTotal > 0 ? '+' : '') . number_format($netTotal, 0, ',', '.') }}</b>
                                </td>
                                <td class="text-end"><b>—</b></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Ringkasan per Kategori — {{ $tahun }}</h3>
                </div>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th>Kategori</th>
                                <th>Tipe</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($perKategori as $k)
                                <tr>
                                    <td>{{ $k->category }}</td>
                                    <td>
                                        <span class="badge bg-{{ $k->type == 'masuk' ? 'green' : 'red' }}-lt">
                                            {{ $k->type }}
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        {{ number_format($k->total, 0, ',', '.') }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="text-center text-secondary">
                                        Belum ada transaksi tahun ini.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    @push('js')
        <script src="/vendor/chart/chart.umd.min.js"></script>
        <script>
            try {
                const rp = (v) => 'Rp ' + Number(v || 0).toLocaleString('id-ID');
                new Chart(document.getElementById('chartFlowTahun'), {
                    data: {
                        labels: @json($chartLabels),
                        datasets: [
                            {
                                type: 'bar',
                                label: 'Masuk',
                                data: @json($chartMasuk ?? []),
                                backgroundColor: 'rgba(47,179,68,.55)',
                            },
                            {
                                type: 'bar',
                                label: 'Keluar',
                                data: @json($chartKeluar ?? []),
                                backgroundColor: 'rgba(214,57,57,.55)',
                            },
                            {
                                type: 'line',
                                label: 'Kumulatif',
                                data: @json($chartKumulatif),
                                borderColor: '#206bc4',
                                backgroundColor: 'rgba(32,107,196,.12)',
                                fill: true,
                                tension: 0.3,
                            },
                        ],
                    },
                    options: {
                        plugins: {
                            legend: { position: 'bottom' },
                            tooltip: {
                                callbacks: {
                                    label: (c) => ' ' + c.dataset.label + ': ' + rp(c.parsed.y),
                                },
                            },
                        },
                        scales: {
                            y: {
                                ticks: {
                                    callback: (v) => rp(v),
                                },
                            },
                        },
                    },
                });
            } catch (e) {
                console.error(e);
            }
        </script>
    @endpush

@else
    {{-- ================= MODE BULAN (EXISTING) ================= --}}

    <div class="card mb-3 border-primary">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                    <div class="text-secondary">Proyeksi Akhir Bulan</div>
                    <div class="h2 mt-1 text-{{ $proyeksiAkhirBulan >= 0 ? 'success' : 'danger' }}">
                        {{ ($proyeksiAkhirBulan >= 0 ? '+' : '') . 'Rp ' . number_format($proyeksiAkhirBulan, 0, ',', '.') }}
                    </div>
                    <div class="text-secondary small">
                        Kumulatif kini Rp {{ number_format($netTotal, 0, ',', '.') }}
                        + (rata-rata net harian Rp {{ number_format($rataNetHarian, 0, ',', '.') }}
                        × sisa {{ $sisaHari }} hari dari {{ $daysInMonth }} hari, hari ke-{{ $hariBerlalu }})
                    </div>
                </div>
                <span class="badge bg-blue-lt">Proyeksi</span>
            </div>
            <div class="text-secondary small mt-2">
                Asumsi: bila pola sama — rata-rata net harian sejauh ini berlanjut hingga akhir bulan.
                Bukan kepastian; abaikan bila bulan sudah lewat (sisa 0 hari) atau bulan depan (belum ada data).
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">
            <h3 class="card-title">Grafik Net Kumulatif Harian</h3>
        </div>
        <div class="card-body">
            <canvas id="chartFlow" height="110"></canvas>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">
            <h3 class="card-title">Rincian per Akun Kas</h3>
        </div>
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th>Akun</th>
                        <th class="text-end">Masuk</th>
                        <th class="text-end">Keluar</th>
                        <th class="text-end">Net</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($perAkun ?? [] as $a)
                        @php
                            $isTopAkun = ($akunTopId ?? null) !== null
                                && (int) $a['id'] === (int) ($akunTopId ?? 0);
                        @endphp
                        <tr class="{{ $isTopAkun ? 'table-primary' : '' }}">
                            <td>
                                {{ $a['name'] }}
                                @if($isTopAkun)
                                    <span class="badge bg-blue-lt ms-1">
                                        ★ Terbesar
                                    </span>
                                @endif
                            </td>
                            <td class="text-end text-success">
                                +{{ number_format($a['masuk'], 0, ',', '.') }}
                            </td>
                            <td class="text-end text-danger">
                                -{{ number_format($a['keluar'], 0, ',', '.') }}
                            </td>
                            <td class="text-end">
                                <span class="badge bg-{{ $a['net'] >= 0 ? 'green' : 'red' }}-lt">
                                    {{ ($a['net'] > 0 ? '+' : '') . number_format($a['net'], 0, ',', '.') }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-secondary">
                                Belum ada transaksi bulan ini.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer text-secondary small">
            Akun terbesar = arus masuk tertinggi bulan ini (baris disorot).
        </div>
    </div>

    <div class="row row-cards">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Tabel Harian</h3>
                </div>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th>Tanggal</th>
                                <th class="text-end">Masuk</th>
                                <th class="text-end">Keluar</th>
                                <th class="text-end">Net</th>
                                <th class="text-end">Kumulatif</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($days as $d)
                                <tr>
                                    <td>{{ date('d/m/Y', strtotime($d['tgl'])) }}</td>
                                    <td class="text-end text-success">
                                        +{{ number_format($d['masuk'], 0, ',', '.') }}
                                    </td>
                                    <td class="text-end text-danger">
                                        -{{ number_format($d['keluar'], 0, ',', '.') }}
                                    </td>
                                    <td class="text-end">
                                        <span class="badge bg-{{ $d['net'] >= 0 ? 'green' : 'red' }}-lt">
                                            {{ ($d['net'] > 0 ? '+' : '') . number_format($d['net'], 0, ',', '.') }}
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <b>{{ ($d['kumulatif'] > 0 ? '+' : '') . number_format($d['kumulatif'], 0, ',', '.') }}</b>
                                    </td>
                                </tr>
                            @endforeach
                            <tr class="table-light">
                                <td><b>TOTAL</b></td>
                                <td class="text-end text-success">
                                    <b>+{{ number_format($totalMasuk, 0, ',', '.') }}</b>
                                </td>
                                <td class="text-end text-danger">
                                    <b>-{{ number_format($totalKeluar, 0, ',', '.') }}</b>
                                </td>
                                <td class="text-end">
                                    <b>{{ ($netTotal > 0 ? '+' : '') . number_format($netTotal, 0, ',', '.') }}</b>
                                </td>
                                <td class="text-end"><b>—</b></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Ringkasan per Kategori</h3>
                </div>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th>Kategori</th>
                                <th>Tipe</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($perKategori as $k)
                                <tr>
                                    <td>{{ $k->category }}</td>
                                    <td>
                                        <span class="badge bg-{{ $k->type == 'masuk' ? 'green' : 'red' }}-lt">
                                            {{ $k->type }}
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        {{ number_format($k->total, 0, ',', '.') }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="text-center text-secondary">
                                        Belum ada transaksi bulan ini.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    @push('js')
        <script src="/vendor/chart/chart.umd.min.js"></script>
        <script>
            try {
                const rp = (v) => 'Rp ' + Number(v || 0).toLocaleString('id-ID');
                new Chart(document.getElementById('chartFlow'), {
                    type: 'line',
                    data: {
                        labels: @json($chartLabels),
                        datasets: [
                            {
                                label: 'Kumulatif',
                                data: @json($chartKumulatif),
                                borderColor: '#206bc4',
                                backgroundColor: 'rgba(32,107,196,.12)',
                                fill: true,
                                tension: 0.3,
                            },
                            {
                                label: 'Net harian',
                                data: @json($chartNet),
                                borderColor: '#2fb344',
                                borderDash: [5, 4],
                                tension: 0.3,
                                pointRadius: 0,
                            },
                        ],
                    },
                    options: {
                        plugins: {
                            legend: { position: 'bottom' },
                            tooltip: {
                                callbacks: {
                                    label: (c) => ' ' + c.dataset.label + ': ' + rp(c.parsed.y),
                                },
                            },
                        },
                        scales: {
                            y: {
                                ticks: {
                                    callback: (v) => rp(v),
                                },
                            },
                        },
                    },
                });
            } catch (e) {
                console.error(e);
            }
        </script>
    @endpush
@endif
@endsection
