@extends('layouts.tabler')
@section('title', 'Stok Mati ≥ ' . $days . ' Hari')
@section('content')
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <div class="page-pretitle">Inventory · {{ $storeName }}</div>
            <h2 class="page-title">Stok Mati ≥ {{ $days }} Hari</h2>
            <div class="text-secondary">
                Produk aktif bervolume (qty &gt; 0) tanpa mutasi keluar/penjualan selama ≥ {{ $days }} hari.
                Modal tertanam total: <b>Rp {{ number_format($totalNilai, 0, ',', '.') }}</b>
                ({{ count($rows) }} produk).
                @if($neverSoldOnly ?? false)
                    <span class="badge bg-red-lt ms-1">Mode: tak pernah terjual sama sekali</span>
                @endif
            </div>
        </div>
        <div class="col-auto d-flex gap-2 d-print-none">
            <a href="{{ route('stocks.opname') }}" class="btn btn-outline-secondary">Opname</a>
            <a href="{{ route('stocks.mutations') }}" class="btn btn-outline-secondary">Mutasi</a>
            <button onclick="window.print()" class="btn btn-outline-primary"><i class="ti ti-printer me-1"></i> Cetak</button>
            <a href="{{ route('stocks.deadstock', array_merge(request()->query(), ['export' => 'csv'])) }}" class="btn btn-success"><i class="ti ti-download me-1"></i> Export CSV</a>
        </div>
    </div>
</div>

<div class="card mb-3 d-print-none">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label">Diam ≥ (hari)</label>
                <input
                    type="number"
                    name="days"
                    min="1"
                    max="3650"
                    value="{{ $days }}"
                    class="form-control"
                >
            </div>
            <div class="col-md-3">
                <label class="form-label">Tipe</label>
                <select name="type" class="form-select">
                    <option value="">Semua tipe</option>
                    @foreach(['hp_baru', 'hp_bekas', 'refurbished', 'aksesoris', 'sparepart', 'pulsa', 'paket_data', 'lain'] as $t)
                        <option value="{{ $t }}" {{ request('type') === $t ? 'selected' : '' }}>
                            {{ $t }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Cari</label>
                <input
                    name="q"
                    value="{{ request('q') }}"
                    class="form-control"
                    placeholder="Nama / SKU…"
                >
            </div>
            <div class="col-md-3">
                <label class="form-label">Mode produk</label>
                <div class="form-check mt-1">
                    <input
                        type="checkbox"
                        name="never_sold"
                        value="1"
                        id="neverSoldCheck"
                        class="form-check-input"
                        {{ ($neverSoldOnly ?? false) ? 'checked' : '' }}
                    >
                    <label for="neverSoldCheck" class="form-check-label">
                        Tak pernah terjual sama sekali
                    </label>
                </div>
            </div>
            <div class="col-md-12 d-flex gap-2 mt-2">
                <button class="btn btn-secondary">Filter</button>
                @if($neverSoldOnly ?? false)
                    <a
                        href="{{ route('stocks.deadstock', array_merge(request()->except(['never_sold', 'export', 'page']))) }}"
                        class="btn btn-outline-secondary"
                    >
                        × Keluar dari mode tak-pernah-terjual
                    </a>
                @else
                    <a
                        href="{{ route('stocks.deadstock', array_merge(request()->except(['export', 'page']), ['never_sold' => 1])) }}"
                        class="btn btn-outline-danger"
                    >
                        <i class="ti ti-ban me-1"></i> Tak pernah terjual sama sekali
                    </a>
                @endif
            </div>
        </form>
    </div>
</div>

@if($neverSoldOnly ?? false)
    <div class="alert alert-danger d-flex gap-2 align-items-start" role="alert">
        <div>
            <b>Menampilkan hanya produk yang TAK PERNAH terjual sama sekali</b>
            ({{ count($rows) }} produk, modal Rp {{ number_format($totalNilai, 0, ',', '.') }}).
            <div class="small">
                Kriteria: tidak ada satu pun baris <code>sale_items</code>
                untuk produk ini pada penjualan toko aktif — tanpa batas hari.
                Beda dengan deadstock biasa yang memakai ambang ≥ {{ $days }} hari
                tanpa gerakan keluar.
                <a href="{{ route('stocks.deadstock', array_merge(request()->except(['never_sold', 'export', 'page']))) }}">Kembali ke deadstock biasa</a>.
            </div>
        </div>
    </div>
@endif

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>Produk</th>
                    <th>Tipe</th>
                    <th class="text-end">Stok</th>
                    <th class="text-end">Modal @</th>
                    <th class="text-end">Nilai Modal</th>
                    <th>Keluar Terakhir</th>
                    <th class="text-end">Hari Diam</th>
                    <th>Riwayat Jual</th>
                    <th>Saran</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $r)
                    <tr>
                        <td class="font-monospace small">{{ $r['sku'] }}</td>
                        <td>
                            {{ $r['name'] }}
                            <div class="small text-secondary">{{ $r['brand'] }}</div>
                        </td>
                        <td class="small">{{ $r['type'] }}</td>
                        <td class="text-end"><b>{{ $r['qty'] }}</b></td>
                        <td class="text-end">{{ number_format($r['purchase_price'], 0, ',', '.') }}</td>
                        <td class="text-end">{{ number_format($r['nilai'], 0, ',', '.') }}</td>
                        <td class="small">
                            @if($r['never'])
                                <span class="badge bg-red-lt">tidak pernah</span>
                            @else
                                {{ $r['last_at']->format('d/m/Y H:i') }}
                            @endif
                        </td>
                        <td class="text-end">
                            <span class="badge {{ $r['stuck_days'] >= 180 ? 'bg-red-lt' : 'bg-yellow-lt' }}">
                                {{ $r['stuck_days'] }} hr
                            </span>
                        </td>
                        <td class="small">
                            @if($r['never_sold'] ?? false)
                                <span class="badge bg-red-lt">tak pernah terjual</span>
                            @else
                                <span class="badge bg-green-lt">pernah terjual</span>
                            @endif
                        </td>
                        <td class="small">{{ $r['saran'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10">
                            <div class="empty py-3">
                                <p class="empty-title">
                                    @if($neverSoldOnly ?? false)
                                        Tidak ada produk yang tak pernah terjual
                                    @else
                                        Tidak ada stok mati
                                    @endif
                                </p>
                                <p class="empty-subtitle text-secondary">
                                    @if($neverSoldOnly ?? false)
                                        Semua produk deadstock ≥ {{ $days }} hari ternyata pernah tercatat di penjualan. Coba matikan toggle "Tak pernah terjual sama sekali".
                                    @else
                                        Semua produk aktif bergerak dalam {{ $days }} hari terakhir. Coba kecilkan ambang ?days=.
                                    @endif
                                </p>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer text-secondary small">
        Kriteria deadstock biasa: produk aktif, qty toko aktif &gt; 0, tanpa movement
        keluar/mutasi_keluar/pakai_service sejak {{ \Carbon\Carbon::now()->subDays($days)->format('d/m/Y') }}.
        Kriteria "tak pernah terjual": tanpa baris sale_items pada penjualan toko aktif (tanpa batas hari).
        Saran bersifat operasional: obral/bundel/retur supplier — bukan aksi otomatis.
    </div>
</div>
@endsection
