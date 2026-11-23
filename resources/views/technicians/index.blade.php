@extends('layouts.tabler')
@section('title','Teknisi & Komisi')
@section('content')
<div class="page-header d-print-none"><div class="row align-items-center"><div class="col"><div class="page-pretitle">Service • SDM</div><h2 class="page-title">Teknisi & Komisi</h2><div class="text-secondary">Workload tiket aktif + komisi menunggu / dibayar.</div></div>
<div class="col-auto ms-auto d-flex gap-2"><button class="btn btn-outline-secondary" onclick="window.print()"><i class="ti ti-printer me-1"></i> Cetak</button><a href="{{ route('technicians.report') }}" class="btn btn-outline-info"><i class="ti ti-chart-bar me-1"></i> Laporan Komisi</a></div></div></div>
<div class="print-area">
<div class="card mt-2">
    <div class="card-body">
        <div class="row align-items-center g-2">
            <div class="col-md">
                <h3 class="card-title mb-1">
                    Bayar Semua Menunggu
                </h3>
                <div class="text-secondary">
                    Total menunggu:
                    <strong>
                        Rp {{ number_format($totalMenunggu ?? 0, 0, ',', '.') }}
                    </strong>
                    · 1 kas keluar per teknisi dalam 1 transaksi DB.
                </div>
            </div>
            <div class="col-md-auto">
                @if($canPayCommission ?? false)
                    <form
                        method="POST"
                        action="{{ route('technicians.payAll') }}"
                        enctype="multipart/form-data"
                        class="d-flex gap-2 align-items-end"
                        onsubmit="return confirm('Bayar SEMUA komisi menunggu (Rp {{ number_format($totalMenunggu ?? 0, 0, ',', '.') }})? Kas keluar operasional per teknisi.')"
                    >
                        @csrf
                        <div>
                            <label
                                class="form-label mb-1"
                                for="payAllKas"
                            >
                                Akun kas
                            </label>
                            <select
                                id="payAllKas"
                                name="cash_account_id"
                                class="form-select"
                                required
                            >
                                @forelse(($cashAccounts ?? collect()) as $a)
                                    <option
                                        value="{{ $a->id }}"
                                        {{ $a->is_default ? 'selected' : '' }}
                                    >
                                        {{ $a->name }}
                                        (Rp {{ number_format($a->balance, 0, ',', '.') }})
                                    </option>
                                @empty
                                    <option value="">
                                        Kas Utama (otomatis dibuat)
                                    </option>
                                @endforelse
                            </select>
                        </div>
                        <div>
                            <label
                                class="form-label mb-1"
                                for="payAllBukti"
                            >
                                Bukti (opsional)
                            </label>
                            <input
                                type="file"
                                id="payAllBukti"
                                name="bukti"
                                accept="image/*"
                                class="form-control"
                                title="Foto bukti bayar massal (opsional, JPG/PNG maks 2MB)"
                            >
                        </div>
                        <button
                            type="submit"
                            class="btn btn-success"
                            {{ ($totalMenunggu ?? 0) <= 0 ? 'disabled' : '' }}
                        >
                            Bayar Semua Menunggu
                        </button>
                    </form>
                @else
                    <div
                        class="text-secondary small"
                    >
                        Pembayaran komisi hanya oleh owner/admin.
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
<div class="card mt-2">
    <div class="card-header">
        <h3 class="card-title">
            Leaderboard Produktivitas (bulan {{ $monthKey }})
        </h3>
        <form
            class="ms-auto d-flex gap-2"
            method="GET"
        >
            <input
                name="q"
                value="{{ request('q') }}"
                class="form-control form-control-sm"
                placeholder="Cari teknisi"
            >
            <button class="btn btn-sm btn-outline-primary">
                Cari
            </button>
        </form>
    </div>
<div class="table-responsive"><table class="table card-table table-vcenter"><thead><tr><th>#</th><th>Teknisi</th><th class="text-center">Selesai Bulan Ini</th><th class="text-center">Target</th><th>Capaian</th><th class="text-center">Total Selesai</th><th class="text-center">Rata-rata (hari)</th><th>Rating</th></tr></thead><tbody>
@php $rank = $technicians->sortByDesc(fn($t) => ($doneMonth[$t->id] ?? 0))->values(); @endphp
@forelse($rank as $i => $t)
@php
$selesai = $doneMonth[$t->id] ?? 0;
$target = $techTargets[$t->id] ?? 0;
$pct = $target > 0 ? min(100, round($selesai / $target * 100, 1)) : 0;
@endphp
<tr>
<td>{{ $i+1 }}</td><td>{{ $t->name }}</td>
<td class="text-center"><span class="badge bg-green-lt">{{ $selesai }}</span></td>
<td class="text-center">{{ $target > 0 ? $target : '-' }}</td>
<td style="min-width:140px">
@if($target > 0)
<div class="progress progress-sm"><div class="progress-bar {{ $pct >= 100 ? 'bg-success' : 'bg-primary' }}" style="width: {{ $pct }}%"></div></div>
<div class="small text-secondary">{{ $pct }}%</div>
@else
<span class="text-secondary small">belum diset</span>
@endif
</td>
<td class="text-center">{{ $doneTotal[$t->id] ?? 0 }}</td>
<td class="text-center">{{ $avgDays[$t->id] ?? '-' }}</td>
<td>
@if(isset($speed[$t->id]))
{{ str_repeat('★', $speed[$t->id]) }}{{ str_repeat('☆', 5-$speed[$t->id]) }}
@else
<span class="text-secondary">-</span>
@endif
</td>
</tr>
@empty
<tr><td colspan="8"><div class="empty py-3"><div class="empty-icon"><i class="ti ti-tool fs-1 text-secondary"></i></div><p class="empty-title">Belum ada data produktivitas</p><p class="empty-subtitle text-secondary">Selesaikan tiket bulan ini untuk mengisi leaderboard teknisi.</p></div></td></tr>
@endforelse
</tbody></table></div></div>
@if($canSetTarget)
<div class="card mt-2"><div class="card-header"><h3 class="card-title">Target Tiket Selesai / Bulan ({{ $monthKey }})</h3></div><div class="table-responsive"><table class="table card-table table-vcenter"><thead><tr><th>Teknisi</th><th class="text-center">Target Saat Ini</th><th></th></tr></thead><tbody>
@forelse($technicians as $t)
<tr>
<td>{{ $t->name }}</td>
<td class="text-center"><span class="badge bg-blue-lt">{{ $techTargets[$t->id] ?? 0 }} tiket</span></td>
<td class="text-end"><form method="POST" action="{{ route('technicians.target', $t->id) }}" class="d-flex gap-2 justify-content-end">
@csrf
<input type="number" name="target" min="0" max="10000" value="{{ $techTargets[$t->id] ?? 0 }}" class="form-control form-control-sm" style="max-width:110px">
<button class="btn btn-sm btn-primary">Simpan</button>
</form></td>
</tr>
@empty
<tr><td colspan="3" class="text-center text-secondary">Belum ada teknisi.</td></tr>
@endforelse
</tbody></table></div></div>
@endif
<div
    class="card mt-2"
>
<div
    class="card-body border-bottom"
>
<div
    class="d-flex flex-wrap align-items-end gap-2"
>
<div>
<label
    class="form-label mb-1"
    for="bulkSlipBulan"
>
Periode slip massal
</label>
<input
    type="month"
    id="bulkSlipBulan"
    class="form-control form-control-sm"
    value="{{ $monthKey }}"
>
</div>
<button
    type="button"
    class="btn btn-sm btn-outline-primary"
    id="printSlipsBtn"
>
<i class="ti ti-printer me-1"></i> Cetak Slip Terpilih (maks 20)
</button>
<span
    class="form-hint mt-1 w-100"
>
Centang teknisi lalu cetak — 1 halaman gabungan, 1 slip per halaman (page-break), mengikuti periode bulan di atas.
</span>
</div>
</div>
<div
    class="table-responsive"
>
<table
    class="table card-table table-vcenter"
>
<thead>
<tr>
<th
    class="w-1"
>
<input
    type="checkbox"
    class="form-check-input"
    id="checkAllSlips"
    title="Pilih semua teknisi"
>
</th>
<th>
Teknisi
</th>
<th
    class="text-center"
>
Tiket Aktif
</th>
<th
    class="text-end"
>
Menunggu
</th>
<th
    class="text-end"
>
Dibayar
</th>
<th></th>
</tr>
</thead>
<tbody>
@forelse($technicians as $t)
@php
    $wa = $t->phone
        ? \App\Services\WaService::normalize((string) $t->phone)
        : '';
    $waValid = $wa !== ''
        && str_starts_with($wa, '62')
        && strlen($wa) >= 10
        && strlen($wa) <= 15;
@endphp
<tr>
<td>
<input
    type="checkbox"
    class="form-check-input slip-check"
    value="{{ $t->id }}"
>
</td>
<td>
    {{ $t->name }}
    <div class="text-secondary small">
        {{ $t->phone ?? '' }} · rate {{ $t->commission_rate ?? 10 }}%
        @if($waValid)
            <a
                href="https://wa.me/{{ $wa }}"
                target="_blank"
                rel="noopener"
                class="btn btn-sm btn-outline-success ms-1 py-0 px-1"
                title="Chat WhatsApp {{ $t->name }}"
            ><i class="ti ti-message me-1"></i></a>
        @endif
    </div>
</td>
<td class="text-center"><span class="badge bg-blue-lt">{{ $workload[$t->id] ?? 0 }}</span></td>
<td class="text-end">Rp {{ number_format($komisiMenunggu[$t->id] ?? 0,0,',','.') }}</td>
<td class="text-end">
    Rp {{ number_format($komisiDibayar[$t->id] ?? 0,0,',','.') }}
    @if(!empty($buktiKomisiByTech[$t->id] ?? null))
        <div>
            <a
                href="{{ asset('storage/' . $buktiKomisiByTech[$t->id]) }}"
                target="_blank"
                class="small"
                title="Bukti pembayaran komisi terakhir"
            >
                <i class="ti ti-paperclip me-1"></i> Bukti
            </a>
        </div>
    @endif
</td>
<td class="text-end">
    @if($canPayCommission ?? false)
        <form
            method="POST"
            action="{{ route('technicians.pay', $t->id) }}"
            enctype="multipart/form-data"
            onsubmit="return confirm('Bayar semua komisi menunggu? (kas keluar operasional)')"
        >
            @csrf
            <input
                type="file"
                name="bukti"
                accept="image/*"
                class="form-control form-control-sm mb-1"
                title="Foto bukti bayar (opsional, JPG/PNG maks 2MB)"
            >
            <button
                class="btn btn-sm btn-success"
                {{ ($komisiMenunggu[$t->id] ?? 0) <= 0 ? 'disabled' : '' }}
            >
                Bayar Komisi
            </button>
        </form>
    @else
        <div
            class="text-secondary small"
        >
            Hanya owner/admin yang dapat membayar.
        </div>
    @endif
    <a
        href="{{ route('technicians.slip', $t->id) }}"
        class="btn btn-sm btn-outline-secondary mt-1"
    >
        <i class="ti ti-receipt me-1"></i> Slip
    </a>
    <a
        href="{{ route('technicians.vcard', $t->id) }}"
        class="btn btn-sm btn-outline-primary mt-1"
        title="Unduh kontak {{ $t->name }} (.vcf)"
    >
        Unduh vCard
    </a>
</td>
</tr>@empty<tr><td colspan="6"><div class="empty py-3"><div class="empty-icon"><i class="ti ti-users fs-1 text-secondary"></i></div><p class="empty-title">Belum ada teknisi</p><p class="empty-subtitle text-secondary">Tambahkan akun teknisi lewat menu Master → Pengguna (role teknisi).</p></div></td></tr>@endforelse
</tbody></table></div></div>
<div class="card mt-2"><div class="card-header"><h3 class="card-title">Komisi Terbaru (50)</h3></div><div class="table-responsive"><table class="table card-table"><thead><tr><th>Tiket</th><th>Teknisi</th><th class="text-end">Nominal</th><th>Status</th><th>Bukti</th><th></th></tr></thead><tbody>
@forelse($commissions as $c)<tr><td><a href="{{ route('services.show',$c->service_ticket_id) }}">{{ $c->ticket->ticket_no ?? '#'.$c->service_ticket_id }}</a></td><td>{{ $c->technician->name ?? '' }}</td><td class="text-end">Rp {{ number_format($c->amount,0,',','.') }}</td><td><span class="badge {{ $c->status=='dibayar'?'bg-green-lt':'bg-yellow-lt' }}">{{ $c->status }}</span></td><td>
    @if($c->status == 'dibayar' && !empty($buktiKomisiByTech[$c->technician_id] ?? null))
        <a
            href="{{ asset('storage/' . $buktiKomisiByTech[$c->technician_id]) }}"
            target="_blank"
            class="small"
            title="Bukti pembayaran komisi {{ $c->technician->name ?? '' }}"
        >
            <i class="ti ti-paperclip me-1"></i> Bukti
        </a>
    @else
        <span class="text-secondary">-</span>
    @endif
</td><td class="text-end">
    @if($c->status == 'menunggu' && ($canPayCommission ?? false))
        <form
            method="POST"
            action="{{ route('technicians.pay', $c->technician_id) }}"
            enctype="multipart/form-data"
            onsubmit="return confirm('Bayar komisi ini? (kas keluar operasional)')"
        >
            @csrf
            <input
                type="hidden"
                name="commission_ids[]"
                value="{{ $c->id }}"
            >
            <input
                type="file"
                name="bukti"
                accept="image/*"
                class="form-control form-control-sm mb-1"
                title="Foto bukti bayar (opsional, JPG/PNG maks 2MB)"
            >
            <button
                class="btn btn-sm btn-outline-success"
            >
                Bayar
            </button>
        </form>
    @endif
</td></tr>
@empty<tr><td colspan="6"><div class="empty py-3"><div class="empty-icon"><i class="ti ti-coin fs-1 text-secondary"></i></div><p class="empty-title">Belum ada komisi</p><p class="empty-subtitle text-secondary">Komisi dibuat otomatis setiap tiket diserahterimakan, siap dibayar dari sini.</p></div></td></tr>@endforelse
</tbody></table></div></div>
</div>
<script>
document
    .getElementById('checkAllSlips')
    ?.addEventListener('change', function () {
        const
            on = document
                .getElementById('checkAllSlips')
                .checked;
        document
            .querySelectorAll('.slip-check')
            .forEach(function (cb) {
                cb
                    .checked = on;
            });
    });
document
    .getElementById('printSlipsBtn')
    ?.addEventListener('click', function () {
        const
            ids = Array
                .from(
                    document
                        .querySelectorAll('.slip-check:checked')
                )
                .map(function (cb) {
                    return cb
                        .value;
                });
        if (!ids
            .length) {
            alert('Pilih minimal 1 teknisi dulu (centang baris tabel).');
            return;
        }
        if (ids
            .length > 20) {
            alert('Maksimal 20 slip per cetak massal.');
            return;
        }
        const
            bulan = document
                .getElementById('bulkSlipBulan')
                ?.value || '{{ $monthKey }}';
        const
            q = ids
                .map(function (id) {
                    return 'ids[]=' + encodeURIComponent(id);
                })
                .join('&');
        window
            .open(
                "{{ route('technicians.bulkSlips') }}?bulan=" + encodeURIComponent(bulan) + "&" + q,
                '_blank'
            );
    });
</script>
@endsection
