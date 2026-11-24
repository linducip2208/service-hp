@extends('layouts.tabler')
@section('title', 'Produk & HP')
@section('content')
<div class="page-header"><div class="row align-items-center"><div class="col"><div class="page-pretitle">Inventory</div>
<h2 class="page-title">
    Produk & HP
    @if(!empty($showAll))
        <span class="badge bg-purple-lt">Semua toko</span>
    @endif
</h2>
</div>
<div class="col-auto d-flex gap-2 flex-wrap align-items-center">
<a href="{{ route('products.create') }}" class="btn btn-primary"><i class="ti ti-plus me-1"></i>Produk</a>
@if(in_array(auth()->user()->role ?? '', ['superadmin', 'owner']))
    @if(!empty($showAll))
        <a href="{{ route('products.index', array_merge(request()->except('all'), [])) }}" class="btn btn-outline-secondary btn-sm"><i class="ti ti-building-store me-1"></i> Toko aktif saja</a>
    @else
        <a href="{{ route('products.index', array_merge(request()->query(), ['all' => 1])) }}" class="btn btn-outline-secondary btn-sm"><i class="ti ti-building me-1"></i> Semua toko</a>
    @endif
@endif
<div class="dropdown">
    <button class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><i class="ti ti-tool me-1"></i>Alat & Laporan</button>
    <div class="dropdown-menu dropdown-menu-end">
        <a href="{{ route('stocks.valuation') }}" class="dropdown-item"><i class="ti ti-cash me-1"></i> Nilai Stok</a>
        <a href="{{ route('stocks.deadstock') }}" class="dropdown-item"><i class="ti ti-snowflake me-1"></i> Stok Mati</a>
        <a href="{{ route('products.bulk') }}" class="dropdown-item"><i class="ti ti-currency-dollar me-1"></i> Harga Massal</a>
        <a href="{{ route('products.reorder') }}" class="dropdown-item"><i class="ti ti-shopping-cart me-1"></i> Saran Belanja</a>
        <a href="{{ route('products.export') }}" class="dropdown-item"><i class="ti ti-download me-1"></i> Export CSV</a>
        <button type="button" id="printLabelsBtn" class="dropdown-item"><i class="ti ti-printer me-1"></i> Label Terpilih (<span id="labelCount">0</span>)</button>
        <button type="button" id="printCatalogBtn" class="dropdown-item" title="Cetak katalog harga (produk aktif, filter & halaman ini)"><i class="ti ti-book me-1"></i> Katalog Harga</button>
    </div>
</div>
</div></div></div>
@if(($lowSpareparts ?? collect())->count())
<details class="alert alert-danger py-2">
    <summary>
        <strong>Sparepart menipis ({{ $lowSpareparts->count() }})</strong>
        <span class="text-secondary small">— klik untuk rincian</span>
    </summary>
    <div class="mt-2">
    @foreach($lowSpareparts as $lp)
        <span class="badge bg-red-lt mt-1 me-1">
            {{ $lp->name }} — stok {{ $lp->stock_total ?? 0 }}/min {{ $lp->min_stock }}
        </span>
    @endforeach
    </div>
</details>
@endif
@if(($lossCount ?? 0) > 0)
<details class="alert alert-warning py-2" {{ request()->boolean('rugi') ? 'open' : '' }}>
    <summary>
        <strong>
            <i class="ti ti-alert-triangle me-1"></i> Jual rugi: {{ $lossCount }} produk (jual &lt; beli)
        </strong>
        @if(request()->boolean('rugi'))
            <a
                href="{{ route('products.index', array_merge(request()->except('rugi'), [])) }}"
                class="btn btn-sm btn-outline-secondary ms-2"
                onclick="event.stopPropagation()"
            >
                Tampilkan semua
            </a>
        @else
            <a
                href="{{ route('products.index', array_merge(request()->query(), ['rugi' => 1])) }}"
                class="btn btn-sm btn-danger ms-2"
                onclick="event.stopPropagation()"
            >
                Filter hanya jual rugi
            </a>
        @endif
    </summary>
    <div class="mt-2">
        @foreach(($lossProducts ?? collect()) as $lp)
            <a
                href="{{ route('products.edit', $lp) }}"
                class="badge bg-red-lt mt-1 me-1"
                title="Beli Rp {{ number_format($lp->purchase_price, 0, ',', '.') }} → Jual Rp {{ number_format($lp->selling_price, 0, ',', '.') }}"
            >
                {{ $lp->name }} ({{ $lp->sku }}) — beli {{ number_format($lp->purchase_price, 0, ',', '.') }} / jual {{ number_format($lp->selling_price, 0, ',', '.') }}
            </a>
        @endforeach
        @if(($lossCount ?? 0) > ($lossProducts ?? collect())->count())
            <span class="text-secondary small">
                …dan {{ $lossCount - ($lossProducts ?? collect())->count() }} lainnya.
            </span>
        @endif
    </div>
</details>
@endif
@if(session('import_fail'))
<div class="alert alert-warning"><strong>Rincian baris gagal ({{ count(session('import_fail')) }}):</strong><ul class="mb-0">@foreach(session('import_fail') as $f)<li>{{ $f }}</li>@endforeach</ul></div>
@endif
<details class="card mb-3">
    <summary class="card-body py-2" style="cursor:pointer;list-style:none">
        <i class="ti ti-upload me-1"></i><strong>Import CSV produk</strong>
        <span class="text-secondary small">(sku,nama,type,brand,category,harga_beli,harga_jual,min_stock)</span>
    </summary>
    <div class="card-body border-top">
        <form
            method="POST"
            action="{{ route('products.import') }}"
            enctype="multipart/form-data"
            class="row g-2 align-items-end"
            data-loading
            onsubmit="return confirm('Import CSV produk sekarang?')"
        >
            @csrf
            <div class="col-md-6">
                <label class="form-label">
                    Import CSV produk
                    <span class="text-secondary">
                        (kolom: sku,nama,type,brand,category,harga_beli,harga_jual,min_stock)
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
            <div class="col-md-2">
                <button class="btn btn-outline-primary w-100">
                    Import
                </button>
            </div>
            <div class="col-md-2">
                <a
                    href="{{ route('products.template') }}"
                    class="btn btn-outline-success w-100"
                >
                    <i class="ti ti-download me-1"></i> Template
                </a>
            </div>
        </form>
    </div>
</details>
<div class="card mb-3"><div class="card-body py-2"><form method="GET" class="row g-2 align-items-end">
@if(!empty($showAll))
    <input type="hidden" name="all" value="1">
@endif
<div class="col-md-4"><input name="q" value="{{ request('q') }}" class="form-control" placeholder="Nama / SKU / barcode…"></div>
<div class="col-md-2"><select name="type" class="form-select"><option value="">Semua tipe</option>
@foreach(['hp_baru','hp_bekas','refurbished','aksesoris','sparepart','pulsa','paket_data','lain'] as $t)<option value="{{ $t }}" {{ request('type')===$t?'selected':'' }}>{{ $t }}</option>@endforeach</select></div>
<div class="col-md-2"><select name="brand_id" class="form-select"><option value="">Semua merek</option>@foreach($brands as $b)<option value="{{ $b->id }}" {{ (string)request('brand_id')===(string)$b->id?'selected':'' }}>{{ $b->name }}</option>@endforeach</select></div>
<div class="col-md-2"><select name="category_id" class="form-select"><option value="">Semua kategori</option>@foreach(($categories ?? []) as $c)<option value="{{ $c->id }}" {{ (string)request('category_id')===(string)$c->id?'selected':'' }}>{{ $c->name }}</option>@endforeach</select></div>
<div class="col-md-2"><button class="btn btn-primary w-100"><i class="ti ti-filter me-1"></i>Filter</button></div>
<div class="col-md-12 d-flex flex-wrap gap-2 align-items-center">
    <a
        href="{{ route('products.index', array_merge(request()->query(), ['export' => 'csv'])) }}"
        class="btn btn-outline-success btn-sm"
    >
        <i class="ti ti-download me-1"></i> Export CSV (filter aktif)
    </a>
    @if(request()->boolean('rugi'))
        <a
            href="{{ route('products.index', array_merge(request()->except('rugi'), [])) }}"
            class="btn btn-outline-secondary btn-sm"
        >
            × Hapus filter jual rugi
        </a>
        <span class="badge bg-red-lt">
            Hanya jual rugi (aktif, jual &lt; beli)
        </span>
    @else
        <a
            href="{{ route('products.index', array_merge(request()->query(), ['rugi' => 1])) }}"
            class="btn btn-outline-danger btn-sm"
        >
            <i class="ti ti-alert-triangle me-1"></i> Hanya jual rugi
        </a>
    @endif
    @if(request()->boolean('no_photo'))
        <a
            href="{{ route('products.index', array_merge(request()->except('no_photo'), [])) }}"
            class="btn btn-outline-secondary btn-sm"
        >
            × Hapus filter tanpa foto
        </a>
        <span class="badge bg-orange-lt">
            Tanpa foto (aktif): {{ $noPhotoCount ?? 0 }}
        </span>
    @else
        <a
            href="{{ route('products.index', array_merge(request()->query(), ['no_photo' => 1])) }}"
            class="btn btn-outline-warning btn-sm"
        >
            <i class="ti ti-camera me-1"></i> Tanpa foto
            <span class="badge bg-orange-lt ms-1">
                {{ $noPhotoCount ?? 0 }}
            </span>
        </a>
    @endif
</div></form></div></div>
<div class="card"><div class="table-responsive"><table class="table table-hover mb-0">
<thead>
<tr>
<th>
    <input
        type="checkbox"
        id="checkAllLabels"
        title="Pilih semua di halaman ini"
    >
</th>
<th>SKU</th>
<th></th>
<th>Produk</th>
<th>Tipe</th>
<th class="text-end">Beli</th>
<th class="text-end">Jual</th>
<th class="text-end">Stok toko aktif</th>
@if(!empty($showAll))
    <th>Stok per toko</th>
@endif
<th></th>
</tr>
</thead>
<tbody>
@forelse($products as $p)
    <tr class="{{ $p->is_active && (float) $p->selling_price < (float) $p->purchase_price ? 'table-danger' : '' }}">
        <td>
            <input
                type="checkbox"
                class="form-check-input labelCheck"
                value="{{ $p->id }}"
                data-name="{{ $p->name }}"
                data-price="Rp {{ number_format($p->selling_price, 0, ',', '.') }}"
                data-code="{{ $p->barcode ?: $p->sku }}"
            >
        </td>
<td class="font-monospace small">{{ $p->sku }}</td>
<td style="width:44px">
    @if($p->photo)
        <img src="{{ asset('storage/'.$p->photo) }}" alt="" loading="lazy" class="rounded" style="width:40px;height:40px;object-fit:cover">
    @else
        <span class="avatar avatar-sm bg-secondary-lt"><i class="ti ti-package"></i></span>
    @endif
</td>
<td>
    {{ $p->name }}
    <div class="small text-secondary">
        {{ $p->brand->name ?? '' }}
        @if($p->is_serialized)
            <span class="badge bg-blue-lt">IMEI</span>
        @endif
        @unless($p->is_active)
            <span class="badge bg-secondary-lt">nonaktif</span>
        @endunless
    </div>
</td>
<td class="small">{{ $p->type }}</td>
<td class="text-end">{{ number_format($p->purchase_price,0,',','.') }}</td>
<td class="text-end">
    {{ number_format($p->selling_price,0,',','.') }}
    @if($p->is_active && (float) $p->selling_price < (float) $p->purchase_price)
        <div>
            <span class="badge bg-red-lt mt-1">
                RUGI
            </span>
        </div>
    @endif
</td>
<td class="text-end"><b>{{ $p->stock_total ?? 0 }}</b></td>
@if(!empty($showAll))
    <td class="small">
        @forelse(($stores ?? collect()) as $s)
            <div>
                {{ $s->name }}: <b>{{ ($stockMap ?? [])[$p->id][$s->id] ?? 0 }}</b>
                @if($p->is_serialized)
                    <span class="text-secondary">(unit: {{ ($unitMap ?? [])[$p->id][$s->id] ?? 0 }})</span>
                @endif
            </div>
        @empty
            <span class="text-secondary">—</span>
        @endforelse
    </td>
@endif
<td class="text-end text-nowrap">
    <a href="{{ route('products.show',$p) }}" class="btn btn-sm btn-outline-primary">Detail</a>
    <div class="dropdown d-inline">
        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Aksi lainnya">Aksi</button>
        <div class="dropdown-menu dropdown-menu-end">
            <a href="{{ route('products.edit',$p) }}" class="dropdown-item"><i class="ti ti-edit me-1"></i>Edit</a>
            @if(in_array(auth()->user()->role ?? '', ['superadmin', 'owner', 'admin', 'gudang']))
                <form
                    method="POST"
                    action="{{ route('products.duplicate', $p) }}"
                    onsubmit="return confirm('Duplikat {{ $p->name }}? SKU baru + -COPY, stok mulai 0 & nonaktif.')"
                >
                    @csrf
                    <button class="dropdown-item"><i class="ti ti-copy me-1"></i>Duplikat</button>
                </form>
            @endif
        </div>
    </div>
</td>
</tr>
@empty
    <tr>
    <td colspan="{{ !empty($showAll) ? 10 : 9 }}">
    <div class="empty py-3"><div class="empty-icon"><i class="ti ti-package fs-1 text-secondary"></i></div><p class="empty-title">Belum ada produk</p><p class="empty-subtitle text-secondary">Tambahkan produk manual atau import massal via CSV di atas.</p><div class="empty-action"><a href="{{ route('products.create') }}" class="btn btn-primary"><i class="ti ti-plus me-1"></i>Tambah Produk</a></div></div>
    </td>
    </tr>
@endforelse
</tbody></table></div>
<div class="card-footer">
    {{ $products->links() }}
</div>
</div>

@if(!request()->filled('q') && !empty($duplicateGroups))
<div class="card mt-3 border-warning">
    <div class="card-header bg-warning-lt">
        <h3 class="card-title">
            <i class="ti ti-alert-triangle me-1"></i> Kemungkinan Duplikat ({{ count($duplicateGroups) }} grup)
        </h3>
        <div class="card-actions">
            <form
                method="POST"
                action="{{ route('products.merge-exact') }}"
                onsubmit="return confirm('Gabung OTOMATIS semua grup yang identik penuh? Hanya grup yang SEMUA anggotanya sama persis (nama + brand + tipe + RAM + storage + warna + harga beli + harga jual) yang digabung, primary = ID terkecil. Grup yang beda harga/varian dilewati. Lanjut?')"
            >
                @csrf
                <button class="btn btn-sm btn-warning">
                    <i class="ti ti-bolt me-1"></i> Gabung Otomatis (identik penuh)
                </button>
            </form>
        </div>
    </div>
    <div class="card-body">
        <div class="text-secondary small">
            Otomatis hanya memproses grup yang SEMUA anggotanya identik penuh
            (nama ternormalisasi + brand + tipe + RAM + storage + warna +
            harga beli + harga jual sama persis).
            Primary = ID terkecil (data tertua) per grup, tiap grup dalam
            transaction sendiri.
            Grup yang beda harga/varian dilewati otomatis — gunakan tombol
            Gabung per grup bila memang yakin itu duplikat.
        </div>
    </div>
    @if(session('merge_exact_summary'))
        @php $mex = session('merge_exact_summary'); @endphp
        <div class="card-body border-top">
            <div class="fw-bold mb-1">
                Hasil gabung otomatis:
                {{ $mex['berhasil'] ?? 0 }} berhasil,
                {{ $mex['dilewati'] ?? 0 }} dilewati,
                {{ $mex['gagal'] ?? 0 }} gagal
            </div>
            @foreach(($mex['details_ok'] ?? []) as $line)
                <div class="text-success small">
                    <i class="ti ti-check me-1"></i> {{ $line }}
                </div>
            @endforeach
            @foreach(($mex['details_skipped'] ?? []) as $line)
                <div class="text-secondary small">
                    <i class="ti ti-player-skip-forward me-1"></i> {{ $line }}
                </div>
            @endforeach
            @foreach(($mex['details_failed'] ?? []) as $line)
                <div class="text-danger small">
                    <i class="ti ti-x me-1"></i> {{ $line }}
                </div>
            @endforeach
        </div>
    @endif
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th>Grup</th>
                        <th>Anggota</th>
                        <th>Primary?</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($duplicateGroups as $gi => $g)
                        <form
                            method="POST"
                            action="{{ route('products.merge') }}"
                            onsubmit="return confirm('Gabung {{ $g['members']->count() }} produk ini ke 1 data primary? Stok digabung per toko, riwayat sales/pembelian/service dipindah, duplikat dihapus. Lanjut?')"
                        >
                            @csrf
                            @foreach($g['members'] as $m)
                                <input
                                    type="hidden"
                                    name="ids[]"
                                    value="{{ $m->id }}"
                                >
                            @endforeach
                            @php
                                $first = $g['members']->first();
                            @endphp
                            <tr>
                                <td rowspan="{{ $g['members']->count() }}">
                                    <span class="badge bg-warning-lt">Nama mirip</span>
                                    <div class="text-secondary small">
                                        {{ $g['key'] }}
                                    </div>
                                </td>
                                <td>
                                    <a href="{{ route('products.show', $first) }}">
                                        {{ $first->name }}
                                    </a>
                                    <div class="text-secondary small">
                                        {{ $first->sku }} • {{ $first->brand->name ?? '-' }} • {{ $first->type }}
                                    </div>
                                </td>
                                <td>
                                    <input
                                        type="radio"
                                        name="primary_id"
                                        value="{{ $first->id }}"
                                        class="form-check-input"
                                        checked
                                        required
                                    >
                                </td>
                                <td
                                    rowspan="{{ $g['members']->count() }}"
                                    class="text-end align-middle"
                                >
                                    <button class="btn btn-sm btn-warning">
                                        Gabung
                                    </button>
                                </td>
                            </tr>
                            @foreach($g['members']->skip(1) as $m)
                                <tr>
                                    <td>
                                        <a href="{{ route('products.show', $m) }}">
                                            {{ $m->name }}
                                        </a>
                                        <div class="text-secondary small">
                                            {{ $m->sku }} • {{ $m->brand->name ?? '-' }} • {{ $m->type }}
                                        </div>
                                    </td>
                                    <td>
                                        <input
                                            type="radio"
                                            name="primary_id"
                                            value="{{ $m->id }}"
                                            class="form-check-input"
                                            required
                                        >
                                    </td>
                                </tr>
                            @endforeach
                        </form>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endif

<div
    id="labelSheet"
    class="d-none"
>
    <div
        id="labelGrid"
        class="label-grid"
    ></div>
</div>

<div
    id="catalogSheet"
    class="d-none"
>
    @php
        $catalogStoreId = session('active_store_id');
        $catalogStoreName = 'Semua toko';
        if ($catalogStoreId) {
            $catalogStoreName = (string) (\App\Models\Store::whereKey($catalogStoreId)->value('name') ?? ('Toko #' . $catalogStoreId));
        }
        $catalogActive = $products->getCollection()->filter(
            function ($p) {
                return (bool) $p->is_active;
            }
        )->values();
        $catalogChunks = $catalogActive->chunk(20);
        $catalogFilters = [];
        if (request()->filled('q')) {
            $catalogFilters[] = 'Cari: ' . request('q');
        }
        if (request()->filled('type')) {
            $catalogFilters[] = 'Tipe: ' . request('type');
        }
    @endphp
    @php
        $catalogBrandHit = $brands->first(
            function ($b) {
                return (string) $b->id === (string) request('brand_id');
            }
        );
        $catalogCategoryHit = isset($categories) ? collect($categories)->first(
            function ($c) {
                return (string) $c->id === (string) request('category_id');
            }
        ) : null;
        if ($catalogBrandHit) {
            $catalogFilters[] = 'Merek: ' . $catalogBrandHit->name;
        }
        if ($catalogCategoryHit) {
            $catalogFilters[] = 'Kategori: ' . $catalogCategoryHit->name;
        }
        if (request()->boolean('rugi')) {
            $catalogFilters[] = 'Hanya jual rugi';
        }
        if (request()->boolean('no_photo')) {
            $catalogFilters[] = 'Tanpa foto';
        }
    @endphp
    @if($catalogActive->isEmpty())
        <div class="catalog-page">
            <div class="catalog-kop">
                <div class="catalog-store">
                    {{ $catalogStoreName }}
                </div>
                <div class="catalog-title">
                    Katalog Harga
                </div>
                <div class="catalog-meta">
                    {{ now()->translatedFormat('d F Y H:i') }} — tidak ada produk aktif pada filter/halaman ini.
                </div>
            </div>
        </div>
    @else
        @foreach($catalogChunks as $chunkIndex => $chunk)
            <div class="catalog-page">
                <div class="catalog-kop">
                    <div class="catalog-store">
                        {{ $catalogStoreName }}
                    </div>
                    <div class="catalog-title">
                        Katalog Harga
                        @if($chunkIndex > 0)
                            <span class="catalog-continued">
                                (lanjutan)
                            </span>
                        @endif
                    </div>
                    <div class="catalog-meta">
                        {{ now()->translatedFormat('d F Y H:i') }}
                        • {{ $catalogActive->count() }} produk aktif (halaman ini)
                        @if(count($catalogFilters))
                            • Filter: {{ implode(' | ', $catalogFilters) }}
                        @endif
                    </div>
                </div>
                <table class="catalog-table">
                    <thead>
                        <tr>
                            <th class="catalog-no">
                                No
                            </th>
                            <th class="catalog-photo">
                                Foto
                            </th>
                            <th>
                                Produk
                            </th>
                            <th class="catalog-price">
                                Harga Jual
                            </th>
                            <th class="catalog-stock">
                                Stok
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($chunk as $i => $p)
                            <tr>
                                <td class="catalog-no">
                                    {{ $chunkIndex * 20 + $i + 1 }}
                                </td>
                                <td class="catalog-photo">
                                    @if(!empty($p->photo))
                                        <img
                                            src="{{ asset('storage/' . $p->photo) }}"
                                            alt="{{ $p->name }}"
                                            loading="lazy"
                                        >
                                    @else
                                        <span class="catalog-nophoto">
                                            -
                                        </span>
                                    @endif
                                </td>
                                <td>
                                    <strong>
                                        {{ $p->name }}
                                    </strong>
                                    <div class="catalog-sub">
                                        {{ $p->brand->name ?? '-' }} • {{ $p->type }}
                                    </div>
                                    <div class="catalog-sku">
                                        {{ $p->sku }}
                                    </div>
                                </td>
                                <td class="catalog-price">
                                    Rp {{ number_format($p->selling_price, 0, ',', '.') }}
                                </td>
                                <td class="catalog-stock">
                                    {{ $p->stock_total ?? 0 }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="catalog-foot">
                    Hal {{ $chunkIndex + 1 }} / {{ $catalogChunks->count() }}
                </div>
            </div>
        @endforeach
    @endif
</div>

<style>
    #catalogSheet {
        background: #fff;
    }
    .catalog-page {
        padding: 12px 4px;
    }
    .catalog-kop {
        text-align: center;
        margin-bottom: 10px;
        border-bottom: 2px solid #111;
        padding-bottom: 8px;
    }
    .catalog-store {
        font-size: 20px;
        font-weight: 800;
        letter-spacing: 0.3px;
    }
    .catalog-title {
        font-size: 15px;
        font-weight: 700;
        margin-top: 2px;
    }
    .catalog-continued {
        font-weight: 400;
        font-size: 12px;
    }
    .catalog-meta {
        font-size: 12px;
        color: #555;
        margin-top: 2px;
    }
    .catalog-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
    }
    .catalog-table th,
    .catalog-table td {
        border: 1px solid #999;
        padding: 5px 7px;
        vertical-align: middle;
    }
    .catalog-table thead th {
        background: #f0f0f0;
    }
    .catalog-no {
        width: 34px;
        text-align: center;
    }
    .catalog-photo {
        width: 56px;
        text-align: center;
    }
    .catalog-photo img {
        width: 44px;
        height: 44px;
        object-fit: cover;
    }
    .catalog-nophoto {
        color: #999;
    }
    .catalog-sub,
    .catalog-sku {
        font-size: 11px;
        color: #555;
    }
    .catalog-sku {
        font-family: monospace;
    }
    .catalog-price {
        white-space: nowrap;
        text-align: right;
        font-weight: 700;
        width: 130px;
    }
    .catalog-stock {
        text-align: center;
        width: 52px;
    }
    .catalog-foot {
        font-size: 11px;
        color: #555;
        text-align: right;
        margin-top: 6px;
    }
    @media print {
        body.printing-catalog #catalogSheet,
        body.printing-catalog #catalogSheet * {
            visibility: visible;
        }
        body.printing-catalog #catalogSheet {
            display: block !important;
            position: absolute;
            inset: 0;
            background: #fff;
            color: #000;
        }
        body.printing-catalog #labelSheet {
            display: none !important;
        }
        body.printing-catalog .catalog-page {
            padding: 6mm 8mm;
            page-break-after: always;
            page-break-inside: avoid;
        }
        body.printing-catalog .catalog-page:last-child {
            page-break-after: auto;
        }
        body.printing-catalog .catalog-photo img {
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
    }
</style>

<script>
    (function () {
        var catalogBtn = document.getElementById('printCatalogBtn');

        if (catalogBtn) {
            catalogBtn.addEventListener('click', function () {
                var sheet = document.getElementById('catalogSheet');

                if (!sheet) {
                    return;
                }

                document.body.classList.add('printing-catalog');
                sheet.classList.remove('d-none');
                sheet.scrollIntoView();

                setTimeout(function () {
                    window.print();
                }, 300);
            });
        }

        window.addEventListener('afterprint', function () {
            var sheet = document.getElementById('catalogSheet');

            if (sheet) {
                sheet.classList.add('d-none');
            }

            document.body.classList.remove('printing-catalog');
        });
    })();
</script>

<style>
    .label-grid {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 6px;
    }
    .label-cell {
        border: 1px dashed #aaa;
        padding: 6px;
        text-align: center;
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
        }
        .no-print,
        .page-header,
        .card {
            display: none !important;
        }
        #labelSheet.card {
            display: block !important;
        }
        .label-grid {
            display: grid !important;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 8mm;
            padding: 10mm;
        }
        .label-cell {
            border: 1px dashed #888;
            padding: 4mm;
            text-align: center;
            page-break-inside: avoid;
        }
    }
</style>

<script src="/vendor/jsbarcode/JsBarcode.all.min.js"></script>
<script>
    (function () {
        const checkAll = document.getElementById('checkAllLabels');
        const printBtn = document.getElementById('printLabelsBtn');
        const countEl = document.getElementById('labelCount');

        function selectedBoxes() {
            return Array.from(
                document.querySelectorAll('.labelCheck:checked')
            );
        }

        function refreshCount() {
            countEl.textContent = selectedBoxes().length;
        }

        if (checkAll) {
            checkAll.addEventListener('change', function () {
                document.querySelectorAll('.labelCheck').forEach(function (c) {
                    c.checked = checkAll.checked;
                });
                refreshCount();
            });
        }

        document.querySelectorAll('.labelCheck').forEach(function (c) {
            c.addEventListener('change', refreshCount);
        });

        if (printBtn) {
            printBtn.addEventListener('click', function () {
                var catalogSheet = document.getElementById('catalogSheet');

                if (catalogSheet) {
                    catalogSheet.classList.add('d-none');
                }

                document.body.classList.remove('printing-catalog');

                const boxes = selectedBoxes();

                if (!boxes.length) {
                    alert('Centang dulu produk yang mau dicetak labelnya.');
                    return;
                }

                const grid = document.getElementById('labelGrid');
                grid.innerHTML = '';

                boxes.forEach(function (box) {
                    const name = box.dataset.name || '-';
                    const price = box.dataset.price || '';
                    const code = box.dataset.code || '';

                    const cell = document.createElement('div');
                    cell.className = 'label-cell';

                    const nameDiv = document.createElement('div');
                    const nameB = document.createElement('b');
                    nameB.textContent = name;
                    nameDiv.appendChild(nameB);

                    const priceDiv = document.createElement('div');
                    priceDiv.className = 'lbl-price';
                    priceDiv.textContent = price;

                    const svg = document.createElementNS(
                        'http://www.w3.org/2000/svg',
                        'svg'
                    );
                    svg.setAttribute('class', 'lbl-bc');

                    const codeDiv = document.createElement('div');
                    codeDiv.className = 'font-monospace small';
                    codeDiv.textContent = code;

                    cell.appendChild(nameDiv);
                    cell.appendChild(priceDiv);
                    cell.appendChild(svg);
                    cell.appendChild(codeDiv);
                    grid.appendChild(cell);

                    try {
                        JsBarcode(svg, code, {
                            format: 'CODE128',
                            height: 38,
                            displayValue: false,
                            width: 1.4,
                        });
                    } catch (e) {
                        codeDiv.textContent = code + ' (barcode gagal)';
                    }
                });

                document.getElementById('labelSheet').classList.remove('d-none');
                document.getElementById('labelSheet').scrollIntoView();

                setTimeout(function () {
                    window.print();
                }, 300);
            });
        }

        refreshCount();
    })();
</script>
@endsection
