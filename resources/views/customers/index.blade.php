@extends('layouts.tabler')
@section('title','Pelanggan')
@section('content')
<div class="page-header"><div class="row align-items-center"><div class="col"><div class="page-pretitle">Master • CRM</div><h2 class="page-title">Pelanggan (CRM)</h2></div>
<div class="col-auto d-flex gap-2">
<button
    type="button"
    class="btn btn-outline-primary"
    id="printMemberCardsBtn"
    title="Cetak kartu member terpilih (maks 50)"
>
    <i class="ti ti-id me-1"></i> Cetak Kartu Terpilih
</button>
<a href="{{ route('customers.create') }}" class="btn btn-primary">+ Pelanggan</a>
</div></div></div>
@if($topCustomers->isNotEmpty())
<div class="card mt-3">
    <div class="card-header">
        <h3 class="card-title"><i class="ti ti-trophy me-1"></i> Top 10 Pelanggan</h3>
        <div class="card-subtitle text-secondary">By total belanja • {{ session('active_store_id') ? 'toko aktif' : 'semua toko' }}</div>
    </div>
    <div class="table-responsive">
        <table class="table table-vcenter card-table">
            <thead>
                <tr>
                    <th style="width:40px">#</th>
                    <th>Nama</th>
                    <th>Toko</th>
                    <th>Tier</th>
                    <th class="text-end">Total Belanja</th>
                </tr>
            </thead>
            <tbody>
                @foreach($topCustomers as $i => $t)
                    @php $topTier = \App\Http\Controllers\CustomerController::tierOf((float) $t->total_belanja); @endphp
                    <tr>
                        <td class="text-secondary">{{ $i + 1 }}</td>
                        <td>
                            <a href="{{ route('customers.show', $t) }}">{{ $t->name }}</a>
                        </td>
                        <td>{{ $t->store->name ?? '-' }}</td>
                        <td>
                            <span class="badge bg-{{ $topTier == 'Gold' ? 'yellow' : ($topTier == 'Silver' ? 'blue' : 'gray') }}-lt">
                                {{ $topTier }}
                            </span>
                        </td>
                        <td class="text-end">Rp {{ number_format($t->total_belanja, 0, ',', '.') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif
<div class="card mt-3">
    <div class="card-header">
        <h3 class="card-title"><i class="ti ti-repeat me-1"></i> Pelanggan Berulang (30 hari)</h3>
        <div class="card-subtitle text-secondary">
            {{ session('active_store_id') ? 'toko aktif' : 'semua toko' }} • invoice dalam 30 hari terakhir
        </div>
    </div>
    <div class="card-body">
        <div class="display-6 fw-bold">
            {{ number_format($repeatRate ?? 0, 1, ',', '.') }}%
        </div>
        <div class="text-secondary mt-1">
            {{ $repeatCustomers ?? 0 }} dari {{ $repeatActiveCustomers ?? 0 }} pelanggan bertransaksi
            punya ≥2 invoice (30 hari)
        </div>
        <div
            class="progress mt-2"
            role="progressbar"
            aria-valuenow="{{ $repeatRate ?? 0 }}"
            aria-valuemin="0"
            aria-valuemax="100"
        >
            <div
                class="progress-bar bg-success"
                style="width: {{ min(100, max(0, (float) ($repeatRate ?? 0))) }}%"
            ></div>
        </div>
    </div>
</div>
<div
    class="card mt-3"
    id="pertumbuhan-master"
>
    <div class="card-header">
        <h3 class="card-title">
            <i class="ti ti-trending-up me-1"></i> Pertumbuhan Master (12 bulan)
        </h3>
        <div class="card-subtitle text-secondary">
            Pelanggan baru •
            {{ session('active_store_id') ? 'toko aktif' : 'semua toko' }}
            + produk baru • global (tanpa scope toko)
        </div>
    </div>
    <div class="card-body">
        <div style="height:260px">
            <canvas id="chartMasterGrowth"></canvas>
        </div>
        <div class="text-secondary small mt-2">
            Pelanggan baru mengikuti toko aktif.
            Produk bersifat master global (tabel
            <code>products</code>
            tanpa
            <code>store_id</code>)
            sehingga selalu dihitung semua toko.
            Total 12 bulan:
            <b>{{ array_sum($growthCustomers ?? []) }}</b>
            pelanggan baru,
            <b>{{ array_sum($growthProducts ?? []) }}</b>
            produk baru.
        </div>
    </div>
</div>
@if(session('import_fail'))
    <div class="alert alert-warning mt-3">
        <strong>
            Rincian baris gagal ({{ count(session('import_fail')) }}):
        </strong>
        <ul class="mb-0">
            @foreach(session('import_fail') as $f)
                <li>{{ $f }}</li>
            @endforeach
        </ul>
    </div>
@endif
<div class="card mt-3">
    <div class="card-body">
        <form
            method="POST"
            action="{{ route('customers.import') }}"
            enctype="multipart/form-data"
            class="row g-2 align-items-end"
            onsubmit="return confirm('Import CSV pelanggan sekarang? Duplikat (HP sama) akan diupdate.')"
        >
            @csrf
            <div class="col-md-5">
                <label class="form-label">
                    Import CSV pelanggan
                    <span class="text-secondary">
                        (kolom: nama,hp,alamat,member)
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
                    href="{{ route('customers.template') }}"
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
                    <code>nama;hp;alamat;member</code>
                    <br>
                    <code>Budi Santoso;081234567890;Jl. Melati No. 10;MBR-1001</code>
                    <br>
                    Duplikat = update bila HP sama (ternormalisasi 08… → 628…,
                    scope toko aktif); tanpa HP = data baru.
                </div>
            </div>
        </form>
    </div>
</div>
<div class="card">
    <div class="card-header">
        <form class="d-flex gap-2" method="GET">
            <input
                name="q"
                value="{{ request('q') }}"
                class="form-control"
                placeholder="Cari nama / HP / kode member (mis. MBR-1001)"
            >
            <select name="tier" class="form-select" style="max-width:150px">
                <option value="">Semua tier</option>
                @foreach(['Bronze','Silver','Gold'] as $t)
                    <option value="{{ $t }}" {{ request('tier')==$t?'selected':'' }}>{{ $t }}</option>
                @endforeach
            </select>
            <button class="btn btn-outline-primary">Cari</button>
            <a
                href="{{ route('customers.index', array_merge(request()->query(), ['export' => 'csv'])) }}"
                class="btn btn-outline-success"
            ><i class="ti ti-download me-1"></i> Export CSV</a>
        </form>
    </div>
    <div
        class="card-body border-bottom py-2"
    >
        <form
            method="POST"
            action="{{ route('customers.move-store') }}"
            id="moveStoreForm"
            class="d-flex gap-2 align-items-center flex-wrap"
        >
            @csrf
            <select
                name="target_store_id"
                class="form-select"
                style="max-width:220px"
                required
            >
                <option value="">
                    — Pindah ke toko… —
                </option>
                @foreach($stores as $s)
                    <option value="{{ $s->id }}">
                        {{ $s->name }}
                    </option>
                @endforeach
            </select>
            <button
                type="submit"
                class="btn btn-outline-warning"
                id="moveStoreBtn"
                title="Pindah pelanggan terpilih ke toko tujuan (maks 100)"
            >
                <i class="ti ti-building-store me-1"></i> Pindah Toko Terpilih
            </button>
            <span class="text-secondary small">
                Centang baris tabel • maks 100 • riwayat sales/service tidak ikut pindah
            </span>
        </form>
        @if(session('move_store_summary'))
            @php $mvs = session('move_store_summary'); @endphp
            <div class="mt-2">
                <div class="fw-bold mb-1">
                    Hasil pindah ke {{ $mvs['tujuan'] ?? '-' }}:
                    {{ $mvs['berhasil'] ?? 0 }} berhasil,
                    {{ $mvs['dilewati'] ?? 0 }} dilewati,
                    {{ $mvs['gagal'] ?? 0 }} gagal
                </div>
                @foreach(($mvs['details_ok'] ?? []) as $line)
                    <div class="text-success small">
                        <i class="ti ti-check me-1"></i> {{ $line }}
                    </div>
                @endforeach
                @foreach(($mvs['details_skipped'] ?? []) as $line)
                    <div class="text-secondary small">
                        <i class="ti ti-player-skip-forward me-1"></i> {{ $line }}
                    </div>
                @endforeach
                @foreach(($mvs['details_failed'] ?? []) as $line)
                    <div class="text-danger small">
                        <i class="ti ti-x me-1"></i> {{ $line }}
                    </div>
                @endforeach
            </div>
        @endif
    </div>
    <div class="table-responsive">
        <table class="table table-vcenter card-table">
            <thead>
                <tr>
                    <th class="w-1">
                        <input
                            type="checkbox"
                            class="form-check-input"
                            id="checkAllCustomers"
                            title="Pilih semua"
                        >
                    </th>
                    <th>Nama</th>
                    <th>HP</th>
                    <th>Kode Member</th>
                    <th>Toko</th>
                    <th>Tier</th>
                    <th class="text-end">Total Belanja</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($customers as $c)
                    @php $tier = \App\Http\Controllers\CustomerController::tierOf((float) $c->total_belanja); @endphp
                    @php
                        $wa = $c->phone
                            ? \App\Services\WaService::normalize((string) $c->phone)
                            : '';
                        $waValid = $wa !== ''
                            && str_starts_with($wa, '62')
                            && strlen($wa) >= 10
                            && strlen($wa) <= 15;
                        $salesCount = (int) ($c->sales_count ?? 0);
                        $serviceCount = (int) ($c->service_tickets_count ?? 0);
                        $tradeInCount = (int) ($c->trade_ins_count ?? 0);
                        $warrantyCount = (int) ($c->warranties_count ?? 0);
                        $hasSecondhand = in_array((int) $c->id, $secondhandIds ?? [], true);
                        $isTanpaTransaksi = $salesCount === 0 && $serviceCount === 0;
                        $isAmanHapus = $isTanpaTransaksi
                            && $tradeInCount === 0
                            && $warrantyCount === 0
                            && !$hasSecondhand;
                    @endphp
                    <tr>
                        <td>
                            <input
                                type="checkbox"
                                class="form-check-input customer-check"
                                value="{{ $c->id }}"
                            >
                        </td>
                        <td>
                            <div>
                                {{ $c->name }}
                            </div>
                            @if($isTanpaTransaksi)
                                <span
                                    class="badge bg-gray-lt mt-1"
                                    title="0 sales + 0 tiket service"
                                >
                                    Belum ada transaksi
                                </span>
                            @endif
                        </td>
                        <td>
                            <span>{{ $c->phone }}</span>
                            @if($waValid)
                                <a
                                    href="https://wa.me/{{ $wa }}"
                                    target="_blank"
                                    rel="noopener"
                                    class="btn btn-sm btn-outline-success ms-1"
                                    title="Chat WhatsApp {{ $c->name }}"
                                ><i class="ti ti-message me-1"></i></a>
                            @endif
                        </td>
                        <td>
                            @if($c->member_code)
                                <span class="badge bg-azure-lt">{{ $c->member_code }}</span>
                            @else
                                <span class="text-secondary">-</span>
                            @endif
                        </td>
                        <td>{{ $c->store->name ?? '-' }}</td>
                        <td>
                            <span class="badge bg-{{ $tier=='Gold'?'yellow':($tier=='Silver'?'blue':'gray') }}-lt">{{ $tier }}</span>
                        </td>
                        <td class="text-end">Rp {{ number_format($c->total_belanja,0,',','.') }}</td>
                        <td
                            class="text-end"
                        >
                            <a
                                href="{{ route('customers.show',$c) }}"
                                class="btn btn-sm btn-outline-primary"
                            >
                                CRM
                            </a>
                            <a
                                href="{{ route('customers.edit',$c) }}"
                                class="btn btn-sm btn-outline-secondary"
                            >
                                Edit
                            </a>
                            @if($isAmanHapus)
                                <form
                                    method="POST"
                                    action="{{ route('customers.destroy', $c) }}"
                                    class="d-inline"
                                    onsubmit="return confirm('Hapus {{ $c->name }}? Pelanggan tanpa transaksi ini akan dihapus permanen. Lanjut?')"
                                >
                                    @csrf
                                    @method('DELETE')
                                    <button
                                        type="submit"
                                        class="btn btn-sm btn-outline-danger"
                                        title="Hapus pelanggan tanpa transaksi (aman: 0 sales, 0 service, 0 tukar-tambah/garansi/secondhand)"
                                    >
                                        Hapus
                                    </button>
                                </form>
                            @elseif($isTanpaTransaksi)
                                <div
                                    class="text-secondary small mt-1"
                                    title="Guard customers.destroy menolak bila masih ada relasi"
                                >
                                    @if($tradeInCount > 0)
                                        Ada {{ $tradeInCount }} tukar-tambah
                                    @elseif($warrantyCount > 0)
                                        Ada {{ $warrantyCount }} garansi
                                    @elseif($hasSecondhand)
                                        Ada riwayat beli-bekas
                                    @endif
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">
                            <div class="empty py-3">
                                <div class="empty-icon"><i class="ti ti-users fs-1 text-secondary"></i></div>
                                <p class="empty-title">Belum ada pelanggan</p>
                                <p class="empty-subtitle text-secondary">Tambahkan pelanggan untuk histori belanja, tier, dan pengingat service.</p>
                                <div class="empty-action">
                                    <a href="{{ route('customers.create') }}" class="btn btn-primary"><i class="ti ti-plus me-1"></i>Tambah Pelanggan</a>
                                </div>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer">{{ $customers->links() }}</div>
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
                action="{{ route('customers.merge-exact') }}"
                onsubmit="return confirm('Gabung OTOMATIS semua grup dengan no. HP sama persis? Primary = ID terkecil (data tertua) per grup. Grup Nama-mirip TIDAK disentuh. Lanjut?')"
            >
                @csrf
                <button class="btn btn-sm btn-warning">
                    <i class="ti ti-bolt me-1"></i> Gabung Otomatis (no. HP sama persis)
                </button>
            </form>
        </div>
    </div>
    <div class="card-body">
        <div class="text-secondary small">
            Otomatis hanya memproses grup berlabel "HP sama" (phone ternormalisasi exact).
            Primary = ID terkecil (master tertua), total belanja dihitung ulang dari sales.
            Grup "Nama mirip" butuh keputusan manual — gunakan tombol Gabung per grup.
            Grup lintas-toko dilewati otomatis.
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
    action="{{ route('customers.merge') }}"
    onsubmit="return confirm('Gabung {{ $g['members']->count() }} pelanggan ini ke 1 data primary? Data duplikat akan dihapus, riwayat sales & service dipindah. Lanjut?')"
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
            <span class="badge bg-warning-lt">
                {{ $g['kind'] }}
            </span>
            <div class="text-secondary small">
                {{ $g['key'] }}
            </div>
        </td>
        @php $first = $g['members']->first(); @endphp
        <td>
            <a href="{{ route('customers.show', $first) }}">
                {{ $first->name }}
            </a>
            <div class="text-secondary small">
                {{ $first->phone ?? '-' }} • Rp {{ number_format($first->total_belanja, 0, ',', '.') }}
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
                <a href="{{ route('customers.show', $m) }}">
                    {{ $m->name }}
                </a>
                <div class="text-secondary small">
                    {{ $m->phone ?? '-' }} • Rp {{ number_format($m->total_belanja, 0, ',', '.') }}
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
<script>
(function () {
    var checkAll = document.getElementById('checkAllCustomers');
    if (checkAll) {
        checkAll.addEventListener('change', function () {
            document.querySelectorAll('.customer-check').forEach(function (cb) {
                cb.checked = checkAll.checked;
            });
        });
    }
    var btn = document.getElementById('printMemberCardsBtn');
    if (btn) {
        btn.addEventListener('click', function () {
            var ids = Array.from(
                document.querySelectorAll('.customer-check:checked')
            ).map(function (cb) {
                return cb.value;
            });
            if (!ids.length) {
                alert('Pilih minimal 1 pelanggan dulu (centang baris tabel).');
                return;
            }
            if (ids.length > 50) {
                alert('Maksimal 50 kartu member per cetak massal — kurangi pilihan Anda.');
                return;
            }
            var q = ids.map(function (id) {
                return 'ids[]=' + encodeURIComponent(id);
            }).join('&');
            window.open("{{ route('customers.member-cards') }}?" + q, '_blank');
        });
    }
    var moveForm = document.getElementById('moveStoreForm');
    if (moveForm) {
        moveForm.addEventListener('submit', function (ev) {
            ev.preventDefault();
            var ids = Array.from(
                document.querySelectorAll('.customer-check:checked')
            ).map(function (cb) {
                return cb.value;
            });
            if (!ids.length) {
                alert('Pilih minimal 1 pelanggan dulu (centang baris tabel).');
                return;
            }
            if (ids.length > 100) {
                alert('Maksimal 100 pelanggan per pindah toko massal — kurangi pilihan Anda.');
                return;
            }
            var destSelect = moveForm.querySelector('select[name="target_store_id"]');
            if (!destSelect || !destSelect.value) {
                alert('Pilih toko tujuan dulu.');
                return;
            }
            var destName = destSelect.options[destSelect.selectedIndex].text.trim();
            moveForm.querySelectorAll('input[name="ids[]"]').forEach(function (el) {
                el.remove();
            });
            ids.forEach(function (id) {
                var h = document.createElement('input');
                h.type = 'hidden';
                h.name = 'ids[]';
                h.value = id;
                moveForm.appendChild(h);
            });
            if (!confirm(
                'Pindah ' + ids.length + ' pelanggan ke "' + destName + '"? ' +
                'Riwayat sales/service TIDAK ikut pindah (tetap tercatat). Lanjut?'
            )) {
                return;
            }
            moveForm.submit();
        });
    }
})();
</script>
@push('js')
<script src="/vendor/chart/chart.umd.min.js"></script>
<script>
try {
    var masterEl = document.getElementById('chartMasterGrowth');
    if (masterEl && typeof Chart !== 'undefined') {
        new Chart(masterEl, {
            type: 'bar',
            data: {
                labels: @json($growthLabels ?? []),
                datasets: [
                    {
                        label: 'Pelanggan baru',
                        data: @json($growthCustomers ?? []),
                        backgroundColor: '#206bc4'
                    },
                    {
                        label: 'Produk baru',
                        data: @json($growthProducts ?? []),
                        backgroundColor: '#2fb344'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    }
                }
            }
        });
    }
} catch (e) {
    console.error(e);
}
</script>
@endpush
@endsection
