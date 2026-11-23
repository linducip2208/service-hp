@extends('layouts.tabler')

@section('title', 'Merek')

@section('content')
    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <div class="page-pretitle">Master</div>
                <h2 class="page-title">Merek</h2>
            </div>
        </div>
    </div>

    @if(session('import_fail'))
        <div
            class="alert alert-warning"
        >
            <strong>
                Rincian baris gagal / dilewati ({{ count(session('import_fail')) }}):
            </strong>
            <ul class="mb-0">
                @foreach(session('import_fail') as $f)
                    <li>{{ $f }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card mb-3">
        <div class="card-header">
            <h3 class="card-title">+ Merek Baru</h3>
        </div>
        <div class="card-body">
            <form
                method="POST"
                action="{{ route('brands.store') }}"
                class="row g-2 align-items-end"
            >
                @csrf
                <div class="col-md-6">
                    <label class="form-label">Nama merek</label>
                    <input
                        type="text"
                        name="name"
                        value="{{ old('name') }}"
                        class="form-control @error('name') is-invalid @enderror"
                        placeholder="cth: Apple, Samsung, Xiaomi"
                        maxlength="255"
                        required
                    >
                    @error('name')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-md-2">
                    <button class="btn btn-primary w-100">
                        Simpan
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <form
                method="POST"
                action="{{ route('brands.import') }}"
                enctype="multipart/form-data"
                class="row g-2 align-items-end"
                onsubmit="return confirm('Import CSV merek sekarang? Duplikat akan dilewati.')"
            >
                @csrf
                <div class="col-md-5">
                    <label class="form-label">
                        Import CSV merek
                        <span class="text-secondary">
                            (kolom: nama)
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
                        <i class="ti ti-upload me-1"></i> Import
                    </button>
                    <a
                        href="{{ route('brands.template') }}"
                        class="btn btn-outline-success w-100 mt-2"
                    >
                        <i class="ti ti-download me-1"></i> Unduh Template
                    </a>
                </div>
                <div class="col-md-5">
                    <div class="text-secondary small">
                        Contoh format (delimiter otomatis
                        <code>;</code>
                        atau
                        <code>,</code>
                        , header
                        <code>id</code>
                        diabaikan):
                        <br>
                        <code>nama</code>
                        <br>
                        <code>Samsung</code>
                        <br>
                        Duplikat = dilewati bila nama sudah ada
                        (case-insensitive) + dilaporkan.
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <form
                method="GET"
                action="{{ route('brands.index') }}"
                class="d-flex gap-2 w-100"
            >
                <input
                    type="text"
                    name="q"
                    value="{{ request('q') }}"
                    class="form-control"
                    placeholder="Cari merek"
                >
                <button class="btn btn-outline-primary">
                    Cari
                </button>
                @if(request()->filled('q'))
                    <a
                        href="{{ route('brands.index') }}"
                        class="btn btn-outline-secondary"
                    >
                        Reset
                    </a>
                @endif
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th>Nama</th>
                        <th class="text-end">Jumlah Produk</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($brands as $brand)
                        <tr>
                            <td colspan="3" class="p-0 border-bottom-0">
                                <form
                                    method="POST"
                                    action="{{ route('brands.update', $brand) }}"
                                    class="d-flex gap-2 align-items-center p-2 flex-wrap"
                                >
                                    @csrf
                                    @method('PUT')
                                    <input
                                        type="text"
                                        name="name"
                                        value="{{ old('name', $brand->name) }}"
                                        class="form-control flex-fill"
                                        style="min-width: 200px;"
                                        maxlength="255"
                                        required
                                    >
                                    @if(($brand->products_count ?? 0) === 0)
                                        <span
                                            class="badge bg-secondary-lt"
                                            title="Merek kosong — belum dipakai produk mana pun"
                                        >
                                            0 produk
                                        </span>
                                    @else
                                        <span class="badge bg-blue-lt">
                                            {{ $brand->products_count }} produk
                                        </span>
                                    @endif
                                    <span class="ms-auto d-flex gap-1">
                                        <a
                                            href="{{ route('products.index', ['brand_id' => $brand->id]) }}"
                                            class="btn btn-sm btn-outline-primary"
                                        >
                                            Produk
                                        </a>
                                        <button class="btn btn-sm btn-outline-secondary">
                                            Simpan
                                        </button>
                                    </span>
                                </form>
                                <form
                                    method="POST"
                                    action="{{ route('brands.destroy', $brand) }}"
                                    class="px-2 pb-2"
                                    onsubmit="return confirm('Hapus merek {{ $brand->name }}? Diblokir bila masih dipakai produk.')"
                                >
                                    @csrf
                                    @method('DELETE')
                                    @if(($brand->products_count ?? 0) === 0)
                                        <button
                                            type="submit"
                                            class="btn btn-sm btn-outline-danger"
                                            title="Merek kosong — hapus cepat via destroy existing"
                                        >
                                            Hapus cepat (kosong)
                                        </button>
                                    @else
                                        <button
                                            type="submit"
                                            class="btn btn-sm btn-outline-danger"
                                        >
                                            Hapus
                                        </button>
                                    @endif
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3">
                                <div class="empty py-3">
                                    <div class="empty-icon">
                                        <i class="ti ti-tags fs-1 text-secondary"></i>
                                    </div>
                                    <p class="empty-title">Belum ada merek</p>
                                    <p class="empty-subtitle text-secondary">
                                        Tambahkan merek lewat formulir di atas agar dropdown produk konsisten.
                                    </p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer d-flex justify-content-end">
            {{ $brands->links() }}
        </div>
    </div>

    @if(!request()->filled('q') && !empty($duplicateGroups ?? []))
        <div
            class="card mt-3 border-warning"
        >
            <div
                class="card-header bg-warning-lt"
            >
                <h3
                    class="card-title"
                >
                    <i class="ti ti-alert-triangle me-1"></i> Kemungkinan Duplikat ({{ count($duplicateGroups) }} grup)
                </h3>
                <div
                    class="card-actions"
                >
                    <form
                        method="POST"
                        action="{{ route('brands.merge-exact') }}"
                        onsubmit="return confirm('Gabung OTOMATIS semua grup dengan nama sama persis? Primary = ID terkecil (data tertua) per grup. Tanpa fuzzy. Lanjut?')"
                    >
                        @csrf
                        <button
                            class="btn btn-sm btn-warning"
                        >
                            <i class="ti ti-bolt me-1"></i> Gabung Otomatis (nama sama persis)
                        </button>
                    </form>
                </div>
            </div>
            <div
                class="card-body"
            >
                <div
                    class="text-secondary small"
                >
                    Otomatis hanya memproses grup yang SEMUA anggotanya bernama sama persis (string identik, case-sensitive).
                    Primary = ID terkecil (master tertua), produk dipindah, duplikat dihapus.
                    Tanpa fuzzy — grup beda kapital/spasi/simbol dilewati otomatis, gabung manual via tombol Gabung per grup.
                </div>
            </div>
            @if(session('brand_merge_exact_summary'))
                @php $mex = session('brand_merge_exact_summary'); @endphp
                <div
                    class="card-body border-top"
                >
                    <div
                        class="fw-bold mb-1"
                    >
                        Hasil gabung otomatis:
                        {{ $mex['berhasil'] ?? 0 }} berhasil,
                        {{ $mex['dilewati'] ?? 0 }} dilewati,
                        {{ $mex['gagal'] ?? 0 }} gagal
                    </div>
                    @foreach(($mex['details_ok'] ?? []) as $line)
                        <div
                            class="text-success small"
                        >
                            <i class="ti ti-check me-1"></i> {{ $line }}
                        </div>
                    @endforeach
                    @foreach(($mex['details_skipped'] ?? []) as $line)
                        <div
                            class="text-secondary small"
                        >
                            <i class="ti ti-player-skip-forward me-1"></i> {{ $line }}
                        </div>
                    @endforeach
                    @foreach(($mex['details_failed'] ?? []) as $line)
                        <div
                            class="text-danger small"
                        >
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
                            @foreach($duplicateGroups as $g)
                                <form
                                    method="POST"
                                    action="{{ route('brands.merge') }}"
                                    onsubmit="return confirm('Gabung {{ $g['members']->count() }} merek ini ke 1 data primary? Produk dipindah, duplikat dihapus. Lanjut?')"
                                >
                                    @csrf
                                    @foreach($g['members'] as $m)
                                        <input
                                            type="hidden"
                                            name="ids[]"
                                            value="{{ $m->id }}"
                                        >
                                    @endforeach
                                    <tr>
                                        <td rowspan="{{ $g['members']->count() }}">
                                            <span class="badge bg-warning-lt">{{ $g['kind'] }}</span>
                                            <div class="text-secondary small">{{ $g['key'] }}</div>
                                        </td>
                                        @php $first = $g['members']->first(); @endphp
                                        <td>
                                            {{ $first->name }}
                                            <div class="text-secondary small">
                                                {{ $first->products_count ?? '' }}
                                                <a href="{{ route('products.index', ['brand_id' => $first->id]) }}">
                                                    lihat produk
                                                </a>
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
                                                {{ $m->name }}
                                                <div class="text-secondary small">
                                                    <a href="{{ route('products.index', ['brand_id' => $m->id]) }}">
                                                        lihat produk
                                                    </a>
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
@endsection
