@extends('layouts.tabler')
@section('title', 'Promo')
@section('content')
    <div class="page-header">
        <div class="row align-items-center">
            <div class="col">
                <div class="page-pretitle">Master</div>
                <h2 class="page-title">Promo & Voucher</h2>
            </div>
            <div class="col-auto">
                <a href="{{ route('promos.qrSheet') }}" class="btn btn-outline-primary">Cetak QR Semua</a>
                <a href="{{ route('promos.create') }}" class="btn btn-primary">+ Promo</a>
            </div>
        </div>
    </div>
    <div class="card mb-3">
        <div
            class="card-body d-flex align-items-center gap-2"
        >
            <form
                id="bulkOffForm"
                action="{{ route('promos.bulkOff') }}"
                method="POST"
                class="d-inline"
            >
                @csrf
                <button
                    type="submit"
                    class="btn btn-outline-danger"
                    onclick="return confirm('Nonaktifkan promo yang dipilih?')"
                >
                    Nonaktifkan Terpilih
                </button>
            </form>
            <form
                id="bulkOnForm"
                action="{{ route('promos.bulkOn') }}"
                method="POST"
                class="d-inline"
            >
                @csrf
                <button
                    type="submit"
                    class="btn btn-outline-success"
                    onclick="return confirm('Aktifkan promo yang dipilih? Promo kedaluwarsa / quota habis akan dilewati.')"
                >
                    Aktifkan Terpilih
                </button>
            </form>
            <form
                id="bulkExtendForm"
                action="{{ route('promos.bulkExtend') }}"
                method="POST"
                class="d-inline"
            >
                @csrf
                <button
                    type="submit"
                    class="btn btn-outline-primary"
                    onclick="return confirm('Perpanjang promo yang dipilih +30 hari? Tanggal dihitung dari hari ini bila sudah lewat, lalu diaktifkan kecuali quota habis.')"
                >
                    Perpanjang +30 Hari Terpilih
                </button>
            </form>
            <form
                id="bulkDuplicateForm"
                action="{{ route('promos.bulkDuplicate') }}"
                method="POST"
                class="d-inline"
            >
                @csrf
                <button
                    type="submit"
                    class="btn btn-outline-info"
                    onclick="return confirm('Duplikat promo yang dipilih? Salinan dibuat nonaktif dengan kode -COPY, quota 0, tanggal dikosongkan.')"
                >
                    Duplikat Terpilih
                </button>
            </form>
            <form
                id="bulkDuplicateAllForm"
                action="{{ route('promos.bulkDuplicateAll') }}"
                method="POST"
                class="d-inline-flex align-items-center gap-2"
                title="Salin promo tercentang ke satu toko tujuan (kode turunan per toko, nonaktif)"
            >
                @csrf
                <select
                    name="store_id"
                    class="form-select form-select-sm"
                    style="max-width: 180px;"
                    required
                >
                    <option
                        value=""
                        disabled
                        selected
                    >
                        Salin terpilih ke toko...
                    </option>
                    @foreach (($stores ?? collect()) as $s)
                        <option value="{{ $s->id }}">
                            {{ $s->name }}
                        </option>
                    @endforeach
                </select>
                <button
                    type="submit"
                    class="btn btn-sm btn-outline-warning"
                    onclick="return confirm('Salin promo yang dipilih ke toko tujuan? Maks 20, salinan nonaktif + kode unik per toko, duplikat dilewati.')"
                >
                    Salin Terpilih Antar Toko
                </button>
            </form>
            <span class="text-secondary small">
                Centang promo lalu klik tombol untuk menonaktifkan / mengaktifkan /
                memperpanjang (+30 hari, otomatis aktif kecuali quota habis) /
                menduplikat (salinan nonaktif -COPY, quota 0) massal.
            </span>
        </div>
    </div>
    @if (session('import_fail'))
        <div
            class="alert alert-warning"
        >
            <strong>
                Rincian baris gagal / dilewati ({{ count(session('import_fail')) }}):
            </strong>
            <ul class="mb-0">
                @foreach (session('import_fail') as $f)
                    <li>{{ $f }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    <div class="card mb-3">
        <div class="card-body">
            <form
                method="POST"
                action="{{ route('promos.import') }}"
                enctype="multipart/form-data"
                class="row g-2 align-items-end"
                onsubmit="return confirm('Import CSV promo sekarang? Kode duplikat dilewati (tidak diupdate).')"
            >
                @csrf
                <div class="col-md-6">
                    <label class="form-label">
                        Import CSV promo
                        <span class="text-secondary">
                            (kolom: code,name,type,value,min_belanja,start_date,end_date,quota,is_active)
                        </span>
                    </label>
                    <input
                        type="file"
                        name="file"
                        accept=".csv,.txt"
                        class="form-control"
                        required
                    >
                    <div class="text-secondary small mt-1">
                        Contoh format:
                        <code>code,name,type,value,min_belanja,start_date,end_date,quota,is_active</code>
                        <br>
                        Contoh baris:
                        <code>HEMAT10,Promo Hemat 10%,diskon_persen,10,50000,2026-01-01,2026-12-31,100,1</code>
                        <br>
                        type valid: diskon_persen,diskon_rupiah,bundling,voucher,member.
                        Duplikat dilewati (quota/used aman).
                    </div>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-outline-primary w-100">
                        Import
                    </button>
                </div>
                <div class="col-md-2">
                    <a
                        href="{{ route('promos.template') }}"
                        class="btn btn-outline-secondary w-100"
                    >
                        Unduh Template
                    </a>
                </div>
            </form>
        </div>
    </div>
    <div class="card mb-3">
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col">
                    <div class="fw-bold"><i class="ti ti-package me-1"></i> Bundling (≥2 produk berbeda)</div>
                    <div class="text-secondary small">
                        Nilai = persen diskon yang hanya berlaku bila keranjang berisi
                        minimal 2 produk berbeda. Aturan pasangan A/B spesifik
                        belum didukung — syarat minimal 2 item berbeda.
                    </div>
                </div>
                <div class="col-auto">
                    <a
                        href="{{ route('promos.create', [
                            'type' => 'bundling',
                            'name' => 'Bundling Hemat 2 Produk',
                            'value' => 10,
                            'min_belanja' => 50000,
                        ]) }}"
                        class="btn btn-primary"
                    >
                        <i class="ti ti-package me-1"></i> Buat Bundling
                    </a>
                </div>
            </div>
        </div>
    </div>
    <div
        class="card mb-3"
    >
        <div
            class="card-body d-flex flex-wrap align-items-center gap-2"
        >
            <a
                href="{{ route('promos.index') }}"
                class="btn {{ empty($unusedOnly) ? 'btn-primary' : 'btn-outline-primary' }}"
            >
                Semua Promo
            </a>
            <a
                href="{{ route('promos.index', ['unused' => 1]) }}"
                class="btn {{ !empty($unusedOnly) ? 'btn-warning' : 'btn-outline-warning' }}"
                title="Tampilkan promo yang belum pernah dipakai (used=0 + sales count 0)"
            >
                Belum pernah dipakai
                <span
                    class="badge bg-yellow-lt ms-1"
                >
                    {{ $unusedCount ?? 0 }}
                </span>
            </a>
            @if (!empty($unusedOnly))
                <span
                    class="text-secondary small"
                >
                    Filter aktif: hanya promo yang belum pernah dipakai
                    (used = 0 dan belum ada transaksi sales).
                    <a
                        href="{{ route('promos.index') }}"
                    >
                        Tampilkan semua
                    </a>
                </span>
            @endif
        </div>
    </div>
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">
                Daftar Promo
            </h3>
            <form
                class="ms-auto d-flex gap-2"
                method="GET"
            >
                @if(!empty($unusedOnly))
                    <input
                        type="hidden"
                        name="unused"
                        value="1"
                    >
                @endif
                <input
                    name="q"
                    value="{{ request('q') }}"
                    class="form-control form-control-sm"
                    placeholder="Cari kode / nama promo"
                >
                <button class="btn btn-sm btn-outline-primary">
                    Cari
                </button>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th class="w-1">
                            <input
                                type="checkbox"
                                id="checkAllPromo"
                                class="form-check-input"
                                title="Pilih semua"
                            >
                        </th>
                        <th>Kode</th>
                        <th>Nama</th>
                        <th>Toko</th>
                        <th>Tipe</th>
                        <th class="text-end">Nilai</th>
                        <th>Periode</th>
                        <th>Quota</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($promos as $p)
                        <tr>
                            <td>
                                <input
                                    type="checkbox"
                                    name="ids[]"
                                    value="{{ $p->id }}"
                                    form="bulkOffForm"
                                    class="form-check-input promo-check"
                                >
                            </td>
                            <td><b>{{ $p->code }}</b></td>
                            <td>{{ $p->name }}</td>
                            <td class="text-secondary small">
                                {{ $p->store?->name ?? 'Semua toko' }}
                            </td>
                            <td>{{ $p->type }}</td>
                            <td class="text-end">{{ number_format($p->value, 0, ',', '.') }}</td>
                            <td class="text-secondary small">
                                {{ $p->start_date?->format('d/m/y') ?? '–' }}
                                s/d
                                {{ $p->end_date?->format('d/m/y') ?? '–' }}
                                <br>
                                <span class="badge bg-{{ $p->promo_class }}-lt">{{ $p->promo_status }}</span>
                                @if (!empty($p->promo_countdown))
                                    <br>
                                    <small class="text-secondary">
                                         {{ $p->promo_countdown }}
                                    </small>
                                @endif
                            </td>
                            <td>
                                @if ($p->quota > 0)
                                    {{ $p->used }}/{{ $p->quota }}
                                    <span class="text-secondary small">(sisa {{ $p->sisa_quota }})</span>
                                @else
                                    ∞ ({{ $p->used }})
                                @endif
                            </td>
                            <td>
                                <span
                                    class="badge bg-{{ $p->is_active ? 'green' : 'red' }}-lt"
                                >
                                    {{ $p->is_active ? 'Aktif' : 'Mati' }}
                                </span>
                                @if (!empty($p->is_unused))
                                    <br>
                                    <span
                                        class="badge bg-yellow-lt mt-1"
                                        title="Promo ini belum pernah dipakai (used = 0 dan sales count = 0)"
                                    >
                                        Belum pernah dipakai
                                    </span>
                                @endif
                            </td>
                            <td class="text-end">
                                <a
                                    href="{{ route('promos.show', $p) }}"
                                    class="btn btn-sm btn-outline-primary"
                                >Detail</a>
                                <a
                                    href="{{ route('promos.edit', $p) }}"
                                    class="btn btn-sm btn-outline-secondary"
                                >Edit</a>
                                <form
                                    action="{{ route('promos.duplicate', $p) }}"
                                    method="POST"
                                    class="d-inline"
                                >
                                    @csrf
                                    <button
                                        type="submit"
                                        class="btn btn-sm btn-outline-info"
                                        title="Duplikat di toko yang sama"
                                    >Duplikat</button>
                                </form>
                                <form
                                    action="{{ route('promos.duplicate', $p) }}"
                                    method="POST"
                                    class="d-inline-flex align-items-center gap-1 mt-1"
                                    title="Salin promo ini ke toko lain (nonaktif, kode unik)"
                                >
                                    @csrf
                                    <select
                                        name="store_id"
                                        class="form-select form-select-sm"
                                        style="max-width: 150px;"
                                        required
                                    >
                                        <option
                                            value=""
                                            disabled
                                            selected
                                        >
                                            Salin ke toko…
                                        </option>
                                        @foreach (($stores ?? collect()) as $s)
                                            <option value="{{ $s->id }}">
                                                {{ $s->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <button
                                        type="submit"
                                        class="btn btn-sm btn-outline-success"
                                    >Salin</button>
                                </form>
                                <form
                                    action="{{ route('promos.duplicateAll', $p) }}"
                                    method="POST"
                                    class="d-inline"
                                    title="Salin promo ini ke semua toko aktif (kode turunan per toko, nonaktif)"
                                >
                                    @csrf
                                    <button
                                        type="submit"
                                        class="btn btn-sm btn-outline-warning mt-1"
                                        onclick="return confirm('Salin promo {{ $p->code }} ke SEMUA toko aktif? Kode sama di toko lain akan dilewati, salinan dibuat nonaktif.')"
                                    >Salin ke Semua Toko</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10">
                                <div class="empty py-3">
                                    <div class="empty-icon"><i class="ti ti-tag fs-1 text-secondary"></i></div>
                                    <p class="empty-title">Belum ada promo</p>
                                    <p class="empty-subtitle text-secondary">Buat kode promo untuk dipakai sebagai potongan di POS.</p>
                                    <div class="empty-action">
                                        <a href="{{ route('promos.create') }}" class="btn btn-primary">
                                            <i class="ti ti-plus me-1"></i>Buat Promo
                                        </a>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $promos->links() }}</div>
    </div>
    <script>
        document
            .getElementById('checkAllPromo')
            ?.addEventListener('change', function () {
                document
                    .querySelectorAll('.promo-check')
                    .forEach(function (box) {
                        box.checked = document
                            .getElementById('checkAllPromo')
                            .checked;
                    });
            });
        // Checkbox terikat ke bulkOffForm via atribut form;
        // saat bulkOnForm / bulkExtendForm / bulkDuplicateForm / bulkDuplicateAllForm disubmit,
        // salin id tercentang sebagai hidden input.
        ['bulkOnForm', 'bulkExtendForm', 'bulkDuplicateForm', 'bulkDuplicateAllForm']
            .forEach(function (formId) {
                document
                    .getElementById(formId)
                    ?.addEventListener('submit', function (e) {
                        var form = e.target;
                        form
                            .querySelectorAll('input[name="ids[]"]')
                            .forEach(function (el) {
                                el.remove();
                            });
                        document
                            .querySelectorAll('.promo-check:checked')
                            .forEach(function (box) {
                                var hidden = document.createElement('input');
                                hidden.type = 'hidden';
                                hidden.name = 'ids[]';
                                hidden.value = box.value;
                                form.appendChild(hidden);
                            });
                    });
            });
    </script>
@endsection
