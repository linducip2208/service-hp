@extends('layouts.tabler')

@section('title', 'Kategori')

@section('content')
    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <div class="page-pretitle">Master</div>
                <h2 class="page-title">Kategori</h2>
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
            <h3 class="card-title">+ Kategori Baru</h3>
        </div>
        <div class="card-body">
            <form
                method="POST"
                action="{{ route('categories.store') }}"
                class="row g-2 align-items-end"
            >
                @csrf
                <div class="col-md-6">
                    <label class="form-label">Nama kategori</label>
                    <input
                        type="text"
                        name="name"
                        value="{{ old('name') }}"
                        class="form-control @error('name') is-invalid @enderror"
                        placeholder="cth: HP Baru, Aksesoris, Sparepart"
                        maxlength="255"
                        required
                    >
                    @error('name')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label">Tipe</label>
                    <select
                        name="type"
                        class="form-select @error('type') is-invalid @enderror"
                        required
                    >
                        @foreach($types as $t)
                            <option
                                value="{{ $t }}"
                                {{ old('type') === $t ? 'selected' : '' }}
                            >
                                {{ $t }}
                            </option>
                        @endforeach
                    </select>
                    @error('type')
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
                action="{{ route('categories.import') }}"
                enctype="multipart/form-data"
                class="row g-2 align-items-end"
                onsubmit="return confirm('Import CSV kategori sekarang? Duplikat akan dilewati.')"
            >
                @csrf
                <div class="col-md-5">
                    <label class="form-label">
                        Import CSV kategori
                        <span class="text-secondary">
                            (kolom: nama,tipe)
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
                        href="{{ route('categories.template') }}"
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
                        <code>nama;tipe</code>
                        <br>
                        <code>Aksesoris;aksesoris</code>
                        <br>
                        Tipe: hp, aksesoris, sparepart, pulsa, lain
                        (kosong = lain). Duplikat = dilewati bila
                        nama sudah ada (case-insensitive) + dilaporkan.
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <form
                method="GET"
                action="{{ route('categories.index') }}"
                class="d-flex gap-2 w-100"
            >
                <input
                    type="text"
                    name="q"
                    value="{{ request('q') }}"
                    class="form-control"
                    placeholder="Cari kategori"
                >
                <button class="btn btn-outline-primary">
                    Cari
                </button>
                @if(request()->filled('q'))
                    <a
                        href="{{ route('categories.index') }}"
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
                        <th>Tipe</th>
                        <th class="text-end">Jumlah Produk</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($categories as $category)
                        <tr>
                            <td colspan="4" class="p-0 border-bottom-0">
                                <form
                                    method="POST"
                                    action="{{ route('categories.update', $category) }}"
                                    class="d-flex gap-2 align-items-center p-2 flex-wrap"
                                >
                                    @csrf
                                    @method('PUT')
                                    <input
                                        type="text"
                                        name="name"
                                        value="{{ old('name', $category->name) }}"
                                        class="form-control flex-fill"
                                        style="min-width: 200px;"
                                        maxlength="255"
                                        required
                                    >
                                    <select
                                        name="type"
                                        class="form-select w-auto"
                                        required
                                    >
                                        @foreach($types as $t)
                                            <option
                                                value="{{ $t }}"
                                                {{ $category->type === $t ? 'selected' : '' }}
                                            >
                                                {{ $t }}
                                            </option>
                                        @endforeach
                                    </select>
                                    @if(($category->products_count ?? 0) === 0)
                                        <span
                                            class="badge bg-secondary-lt"
                                            title="Kategori kosong — belum dipakai produk mana pun"
                                        >
                                            0 produk
                                        </span>
                                    @else
                                        <span class="badge bg-blue-lt">
                                            {{ $category->products_count }} produk
                                        </span>
                                    @endif
                                    <span class="ms-auto d-flex gap-1">
                                        <button class="btn btn-sm btn-outline-secondary">
                                            Simpan
                                        </button>
                                    </span>
                                </form>
                                <form
                                    method="POST"
                                    action="{{ route('categories.destroy', $category) }}"
                                    class="px-2 pb-2"
                                    onsubmit="return confirm('Hapus kategori {{ $category->name }}? Diblokir bila masih dipakai produk.')"
                                >
                                    @csrf
                                    @method('DELETE')
                                    @if(($category->products_count ?? 0) === 0)
                                        <button
                                            type="submit"
                                            class="btn btn-sm btn-outline-danger"
                                            title="Kategori kosong — hapus cepat via destroy existing"
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
                            <td colspan="4">
                                <div class="empty py-3">
                                    <div class="empty-icon">
                                        <i class="ti ti-category fs-1 text-secondary"></i>
                                    </div>
                                    <p class="empty-title">Belum ada kategori</p>
                                    <p class="empty-subtitle text-secondary">
                                        Tambahkan kategori lewat formulir di atas agar dropdown produk konsisten.
                                    </p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer d-flex justify-content-end">
            {{ $categories->links() }}
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
                        action="{{ route('categories.merge-exact') }}"
                        onsubmit="return confirm('Gabung OTOMATIS semua grup dengan nama sama persis + tipe sama? Primary = ID terkecil (data tertua) per grup. Tanpa fuzzy. Lanjut?')"
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
                    Otomatis hanya memproses grup yang SEMUA anggotanya bernama sama persis (string identik, case-sensitive) DAN bertipe sama.
                    Primary = ID terkecil (master tertua), produk dipindah, duplikat dihapus.
                    Tanpa fuzzy — grup beda kapital/spasi/simbol atau beda tipe dilewati otomatis, gabung manual via tombol Gabung per grup.
                </div>
            </div>
            @if(session('category_merge_exact_summary'))
                @php $mex = session('category_merge_exact_summary'); @endphp
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
                                    action="{{ route('categories.merge') }}"
                                    onsubmit="return confirm('Gabung {{ $g['members']->count() }} kategori ini ke 1 data primary? Produk dipindah, duplikat dihapus. Lanjut?')"
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
                                                tipe: {{ $first->type ?? '-' }}
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
                                                    tipe: {{ $m->type ?? '-' }}
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
