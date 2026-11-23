@extends('layouts.tabler')
@section('title','Supplier')
@section('content')
<div class="page-header"><div class="row align-items-center"><div class="col"><div class="page-pretitle">Master</div><h2 class="page-title">Supplier</h2></div>
<div class="col-auto">
    <a
        href="{{ route('suppliers.create') }}"
        class="btn btn-primary"
    >
        + Supplier
    </a>
    <a
        href="{{ route('suppliers.index', array_filter(['q' => request('q'), 'export' => 'csv'])) }}"
        class="btn btn-outline-success"
    >
        <i class="ti ti-download me-1"></i> Export CSV
    </a>
</div></div></div>
<div class="card"><div class="card-header"><form class="d-flex gap-2" method="GET"><input name="q" value="{{ request('q') }}" class="form-control" placeholder="Cari supplier"><button class="btn btn-outline-primary">Cari</button></form></div>
@if(session('import_fail'))
    <div
        class="alert alert-warning m-3"
    >
        <strong>Rincian baris gagal ({{ count(session('import_fail')) }}):</strong>
        <ul
            class="mb-0"
        >
            @foreach(session('import_fail') as $f)
                <li>{{ $f }}</li>
            @endforeach
        </ul>
    </div>
@endif
<div
    class="card-body border-bottom"
>
    <form
        method="POST"
        action="{{ route('suppliers.import') }}"
        enctype="multipart/form-data"
        class="row g-2 align-items-end"
        data-loading
        onsubmit="return confirm('Import CSV supplier sekarang?')"
    >
        @csrf
        <div
            class="col-md-6"
        >
            <label
                class="form-label"
            >
                Import CSV supplier
                <span
                    class="text-secondary"
                >
                    (kolom: kode,nama,hp,alamat)
                </span>
            </label>
            <input
                type="file"
                name="file"
                accept=".csv,.txt"
                class="form-control"
                required
            >
            <div
                class="text-secondary small mt-1"
            >
                Contoh format (delimiter ; atau , — baris pertama header):
                <code>kode;nama;hp;alamat</code> —
                contoh: <code>SUP-001;PT Ponsel Jaya;08123456789;Jl. Glodok No.1</code>.
                Alias kolom: code/name/phone/telepon/telp/no_hp/address.
                kode boleh kosong (otomatis SUP-xxx).
                Duplikat = update hp/alamat/kode bila nama sama persis (case-insensitive) di toko aktif; hutang tidak diubah.
            </div>
        </div>
        <div
            class="col-md-2"
        >
            <button
                class="btn btn-outline-primary w-100"
            >
                Import
            </button>
            <a
                href="{{ route('suppliers.template') }}"
                class="btn btn-outline-success w-100 mt-2"
            >
                <i class="ti ti-download me-1"></i> Unduh Template
            </a>
        </div>
    </form>
</div>
<div class="table-responsive"><table class="table table-vcenter card-table"><thead><tr><th>Kode</th><th>Nama</th><th>Telepon</th><th class="text-end">Hutang</th><th class="text-end">Outstanding Beli</th><th class="text-end">Beli bln ini*</th><th class="text-end">Nota*</th><th>Pembelian terakhir*</th><th></th></tr></thead>
<tbody>
@forelse($suppliers as $s)
    @php
        $wa = $s->phone
            ? \App\Services\WaService::normalize((string) $s->phone)
            : '';
        $waValid = $wa !== ''
            && str_starts_with($wa, '62')
            && strlen($wa) >= 10
            && strlen($wa) <= 15;
        $purchaseCount = (int) ($s->purchases_count ?? ($notaCountMap[$s->id] ?? 0));
        $outstanding = (float) ($outstandingMap[$s->id] ?? 0);
        $hutang = (float) $s->hutang;
        $noPurchase = $purchaseCount === 0;
        $safeDelete = $noPurchase && $hutang <= 0.005 && $outstanding <= 0.005;
        $blockReasons = [];
        if (! $noPurchase) {
            $blockReasons[] = $purchaseCount . ' pembelian';
        }
        if ($hutang > 0.005) {
            $blockReasons[] = 'hutang Rp ' . number_format($hutang, 0, ',', '.');
        }
        if ($outstanding > 0.005) {
            $blockReasons[] = 'outstanding Rp ' . number_format($outstanding, 0, ',', '.');
        }
        $blockText = implode(', ', $blockReasons);
    @endphp
    <tr>
        <td>
            {{ $s->code }}
        </td>
        <td>
            <div>
                {{ $s->name }}
            </div>
            @if($noPurchase)
                <span
                    class="badge bg-secondary-lt mt-1"
                    title="Supplier ini belum pernah dipakai di pembelian mana pun"
                >
                    Belum ada pembelian
                </span>
            @endif
        </td>
        <td>
            <span>
                {{ $s->phone }}
            </span>
            @if($waValid)
                <a
                    href="https://wa.me/{{ $wa }}"
                    target="_blank"
                    rel="noopener"
                    class="btn btn-sm btn-outline-success ms-1"
                    title="Chat WhatsApp {{ $s->name }}"
                >
                    <i class="ti ti-message me-1"></i>
                </a>
            @endif
        </td>
        <td class="text-end">
            Rp {{ number_format($s->hutang, 0, ',', '.') }}
        </td>
        <td class="text-end text-danger">
            Rp {{ number_format($outstandingMap[$s->id] ?? 0, 0, ',', '.') }}
        </td>
        <td class="text-end">
            Rp {{ number_format($monthTotalMap[$s->id] ?? 0, 0, ',', '.') }}
        </td>
        <td class="text-end">
            {{ number_format($notaCountMap[$s->id] ?? 0, 0, ',', '.') }}
        </td>
        <td class="text-secondary small">
            {{ isset($lastBuyMap[$s->id]) ? \Carbon\Carbon::parse($lastBuyMap[$s->id])->format('d/m/Y H:i') : '–' }}
        </td>
        <td class="text-end">
            <a
                href="{{ route('suppliers.show', $s) }}"
                class="btn btn-sm btn-outline-primary"
            >
                Detail
            </a>
            <a
                href="{{ route('suppliers.edit', $s) }}"
                class="btn btn-sm btn-outline-secondary"
            >
                Edit
            </a>
            @if($safeDelete)
                <form
                    method="POST"
                    action="{{ route('suppliers.destroy', $s) }}"
                    class="d-inline"
                    onsubmit="return confirm('Hapus supplier {{ $s->name }}? Supplier tanpa pembelian & tanpa hutang/outstanding — aman dihapus. Lanjut?')"
                >
                    @csrf
                    @method('DELETE')
                    <button
                        type="submit"
                        class="btn btn-sm btn-outline-danger"
                        title="Hapus cepat — supplier tanpa pembelian & tanpa hutang/outstanding"
                    >
                        Hapus
                    </button>
                </form>
            @else
                <button
                    type="button"
                    class="btn btn-sm btn-outline-danger"
                    disabled
                    title="Tidak bisa hapus cepat: {{ $blockText ?: 'masih terkait transaksi' }}"
                >
                    Hapus
                </button>
                <div
                    class="text-secondary small mt-1"
                    title="Syarat hapus cepat: tanpa purchase, hutang Rp 0, outstanding Rp 0"
                >
                    {{ $blockText }}
                </div>
            @endif
        </td>
    </tr>
@empty
    <tr>
        <td colspan="9">
            <div class="empty py-3">
                <div class="empty-icon">
                    <i class="ti ti-truck fs-1 text-secondary"></i>
                </div>
                <p class="empty-title">
                    Belum ada supplier
                </p>
                <p class="empty-subtitle text-secondary">
                    Daftarkan supplier untuk pembelian stok dan pantau hutang/outstanding.
                </p>
                <div class="empty-action">
                    <a
                        href="{{ route('suppliers.create') }}"
                        class="btn btn-primary"
                    >
                        <i class="ti ti-plus me-1"></i>
                        Tambah Supplier
                    </a>
                </div>
            </div>
        </td>
    </tr>
@endforelse</tbody></table></div>
<div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2"><span class="text-secondary small">* Scope toko aktif.</span>{{ $suppliers->links() }}</div></div>
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
                action="{{ route('suppliers.merge-exact') }}"
                onsubmit="return confirm('Gabung OTOMATIS semua grup dengan nama sama persis ternormalisasi? Primary = ID terkecil (data tertua) per grup. Tanpa fuzzy. Lanjut?')"
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
            Otomatis hanya memproses grup berlabel "Nama mirip" (nameKey exact: lowercase tanpa spasi & non-alfanumerik).
            Primary = ID terkecil (master tertua), hutang digabung, purchases/konsinyasi/unit dipindah.
            Tanpa fuzzy/levenshtein — grup mirip bunyi butuh keputusan manual via tombol Gabung per grup.
            Grup lintas-toko dilewati otomatis.
        </div>
    </div>
    @if(session('supplier_merge_exact_summary'))
        @php $mex = session('supplier_merge_exact_summary'); @endphp
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
                        action="{{ route('suppliers.merge') }}"
                        onsubmit="return confirm('Gabung {{ $g['members']->count() }} supplier ini ke 1 data primary? Hutang digabung, purchases/konsinyasi/unit dipindah, duplikat dihapus. Lanjut?')"
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
                                <a href="{{ route('suppliers.show', $first) }}">{{ $first->name }}</a>
                                <div class="text-secondary small">
                                    {{ $first->code }} • {{ $first->phone ?? '-' }} • Hutang Rp {{ number_format($first->hutang, 0, ',', '.') }}
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
                                <button class="btn btn-sm btn-warning">Gabung</button>
                            </td>
                        </tr>
                        @foreach($g['members']->skip(1) as $m)
                        <tr>
                            <td>
                                <a href="{{ route('suppliers.show', $m) }}">{{ $m->name }}</a>
                                <div class="text-secondary small">
                                    {{ $m->code }} • {{ $m->phone ?? '-' }} • Hutang Rp {{ number_format($m->hutang, 0, ',', '.') }}
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
