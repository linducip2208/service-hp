@extends('layouts.tabler')
@section('title','Hutang & Piutang')
@section('content')
<div class="page-header d-print-none"><div class="row align-items-center"><div class="col">
  <div class="page-pretitle">Keuangan</div><h2 class="page-title">Hutang & Piutang</h2>
  <div class="text-secondary">Hutang outstanding: <b class="text-danger">Rp {{ number_format($totalHutang,0,',','.') }}</b> &nbsp;|&nbsp; Piutang outstanding: <b class="text-success">Rp {{ number_format($totalPiutang,0,',','.') }}</b></div>
</div><div class="col-auto ms-auto d-flex gap-2"><button class="btn btn-outline-secondary" onclick="window.print()"><i class="ti ti-printer me-1"></i> Cetak</button>
<a href="{{ route('debts.index', array_merge(request()->query(), ['export' => 'csv'])) }}" class="btn btn-outline-success"><i class="ti ti-download me-1"></i> Export CSV</a></div></div></div>
@if (session('import_fail'))
    <div
        class="alert alert-warning"
    >
        <strong>
            Rincian baris gagal / dilewati ({{ count(session('import_fail')) }}):
        </strong>
        <ul
            class="mb-0"
        >
            @foreach (session('import_fail') as $f)
                <li>
                    {{ $f }}
                </li>
            @endforeach
        </ul>
    </div>
@endif
<div class="print-area">
<div class="row row-cards">
<div class="col-12"><div class="card"><div class="card-header"><h3 class="card-title"><i class="ti ti-alert-triangle me-1"></i> Jatuh Tempo ≤ 7 Hari ({{ $dueSoon->count() }})</h3></div>
@if($dueSoon->count())
<div class="table-responsive"><table class="table table-vcenter card-table"><thead><tr><th>Pihak</th><th>Tipe</th><th class="text-end">Sisa</th><th>Jatuh Tempo</th><th></th></tr></thead><tbody>
@foreach($dueSoon as $d)<tr>
  <td>{{ $d->party_name }}</td><td><span class="badge bg-{{ $d->type=='hutang'?'red':'green' }}-lt">{{ $d->type }}</span></td>
  <td class="text-end">Rp {{ number_format($d->remaining,0,',','.') }}</td>
  <td><span class="badge bg-{{ $d->due_date && $d->due_date->isPast() ? 'red' : 'yellow' }}-lt">{{ $d->due_date?->format('d/m/Y') }}</span></td>
  <td class="text-end"><a href="{{ route('debts.index', array_merge(request()->query(), ['remind' => $d->id])) }}" class="btn btn-sm btn-outline-success" onclick="return confirm('Kirim pengingat WA ke {{ $d->party_name }}?')"><i class="ti ti-brand-whatsapp me-1"></i> WA</a></td>
</tr>@endforeach</tbody></table></div>
@else<div class="card-body text-secondary">Tidak ada yang jatuh tempo dalam 7 hari ke depan. </div>@endif</div></div>

<div class="col-12">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">
                <i class="ti ti-clock me-1"></i> Belum Lunas Tanpa Jatuh Tempo ({{ $noDueTotal ?? $noDue->count() }})
            </h3>
            <span class="card-subtitle text-secondary ms-auto">
                Minta tetapkan tempo via kolom Jatuh tempo
            </span>
            <div class="card-actions">
                <a
                    href="{{ route('debts.index', array_merge(request()->query(), ['tanpa_tempo' => 1])) }}"
                    class="btn btn-sm btn-outline-warning"
                >
                    Filter tanpa tempo
                </a>
                @if (request()->boolean('tanpa_tempo'))
                    <a
                        href="{{ route('debts.index', collect(request()->query())->except('tanpa_tempo')->all()) }}"
                        class="btn btn-sm btn-outline-secondary"
                    >
                        Reset
                    </a>
                @endif
            </div>
        </div>
        @if ($noDue->count())
            <div class="table-responsive">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th>Pihak</th>
                            <th>Tipe</th>
                            <th class="text-end">Sisa</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($noDue as $d)
                            <tr>
                                <td>
                                    {{ $d->party_name }}
                                </td>
                                <td>
                                    <span class="badge bg-{{ $d->type == 'hutang' ? 'red' : 'green' }}-lt">
                                        {{ $d->type }}
                                    </span>
                                </td>
                                <td class="text-end">
                                    Rp {{ number_format($d->remaining, 0, ',', '.') }}
                                </td>
                                <td>
                                    <span class="badge bg-grey-lt">
                                        tanpa tempo — tetapkan tempo
                                    </span>
                                </td>
                                <td class="text-end">
                                    <span class="text-secondary small">
                                        Isi tanggal di kolom Jatuh tempo ↓
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="card-body text-secondary">
                Semua hutang/piutang belum lunas sudah punya jatuh tempo. 
            </div>
        @endif
    </div>
</div>

<div class="col-lg-4">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Aging Outstanding (umur hari)</h3>
        </div>
        <div class="table-responsive"><table class="table card-table"><thead><tr><th>Bucket</th><th class="text-end">Hutang</th><th class="text-end">Piutang</th></tr></thead><tbody>
@foreach(['0-30','31-60','>60'] as $b)<tr><td>{{ $b }} hari</td>
  <td class="text-end">{{ number_format($aging['hutang'][$b],0,',','.') }}</td>
  <td class="text-end">{{ number_format($aging['piutang'][$b],0,',','.') }}</td></tr>@endforeach
</tbody></table></div>
        <div class="card-body">
            <canvas id="chartAging" height="160"></canvas>
            <div class="text-secondary mt-2">
                Bucket umur sejak dibuat: 0&ndash;30 / 31&ndash;60 / &gt;60 hari.
            </div>
        </div>
    </div>
</div>
<div class="col-lg-4"><div class="card"><div class="card-header"><h3 class="card-title">Catat Baru</h3></div>
<div class="card-body"><form method="POST" action="{{ route('debts.store') }}" onsubmit="return confirm('Catat hutang/piutang ini?')">@csrf
  <div class="mb-2"><label class="form-label">Tipe</label><select name="type" class="form-select" required><option value="hutang">Hutang (kita berhutang)</option><option value="piutang">Piutang (orang berhutang ke kita)</option></select></div>
  <div class="mb-2"><label class="form-label">Pihak</label><input name="party_name" class="form-control" required></div>
  <div class="row"><div class="col-6 mb-2"><label class="form-label">Total</label><input type="number" name="total" class="form-control" min="1" required></div>
  <div class="col-6 mb-2"><label class="form-label">Sudah dibayar</label><input type="number" name="paid" class="form-control" min="0" value="0"></div></div>
  <div class="mb-2"><label class="form-label">Jatuh tempo</label><input type="date" name="due_date" class="form-control"></div>
  <button class="btn btn-primary w-100">Simpan</button>
</form></div></div>
<div
    class="card mt-3"
>
    <div
        class="card-header"
    >
        <h3 class="card-title">
            Import Saldo Awal (Migrasi)
        </h3>
    </div>
    <div
        class="card-body"
    >
        <div
            class="alert alert-warning"
        >
            <strong>
                Tanpa mutasi kas.
            </strong>
            Import ini
            <strong>
                hanya mencatat baris hutang/piutang
            </strong>
            — tidak menambah/mengurangi saldo akun kas dan tidak membuat
            jurnal kas (tanpa jurnal ganda saat cicilan dibayar nanti).
            Kolom
            <code>
                terbayar
            </code>
            yang &gt; 0 diterima sebagai
            <strong>
                info historis
            </strong>
            (sudah dibayar di sistem lama), bukan pembayaran baru.
        </div>
        <p class="text-secondary">
            Format CSV (delimiter
            <code>
                ;
            </code>
            atau
            <code>
                ,
            </code>
            otomatis terdeteksi):
            <code>
                tipe,pihak,total,terbayar,jatuh_tempo,ref
            </code>
        </p>
        <ul class="text-secondary small">
            <li>
                <code>tipe</code>: hutang / piutang.
            </li>
            <li>
                <code>total</code> wajib ≥ 1;
                <code>terbayar</code> opsional (default 0, dijepit ke total).
            </li>
            <li>
                <code>jatuh_tempo</code> opsional: yyyy-mm-dd atau dd/mm/yyyy.
            </li>
            <li>
                <code>ref</code> opsional: label nota lama (tanpa tautan transaksi).
            </li>
            <li>
                Status dihitung otomatis: belum / sebagian / lunas.
            </li>
            <li>
                Duplikat (tipe + pihak + total sama) dilewati &amp; dilaporkan.
            </li>
        </ul>
        <a
            href="{{ route('debts.template') }}"
            class="btn btn-outline-success w-100 mb-2"
        >
            <i class="ti ti-download me-1"></i> Unduh Template CSV
        </a>
        <form
            method="POST"
            action="{{ route('debts.import') }}"
            enctype="multipart/form-data"
            onsubmit="return confirm('Import saldo awal? Hanya mencatat hutang/piutang, TANPA mutasi kas.')"
        >
            @csrf
            <div class="mb-2">
                <label class="form-label">
                    File CSV (maks 2 MB)
                </label>
                <input
                    type="file"
                    name="file"
                    class="form-control"
                    accept=".csv,.txt"
                    required
                >
            </div>
            <button class="btn btn-primary w-100">
                Import Saldo Awal
            </button>
        </form>
    </div>
</div>
</div>
<div class="col-lg-8">
    <div class="card mb-3">
        <div class="card-header">
            <h3 class="card-title">Ringkasan per Pihak ({{ $perParty->count() }})</h3>
            <span class="card-subtitle text-secondary ms-auto">Mengikuti filter aktif</span>
        </div>
        @if ($perParty->count())
            <div class="table-responsive">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th>Pihak</th>
                            <th class="text-end">Total Tagihan</th>
                            <th class="text-end">Dibayar</th>
                            <th class="text-end">Sisa</th>
                            <th class="text-center">Nota</th>
                            <th>Jatuh Tempo Terdekat</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($perParty as $p)
                            <tr>
                                <td>
                                    {{ $p->party_name }}
                                </td>
                                <td class="text-end">
                                    Rp {{ number_format((float) $p->sum_total, 0, ',', '.') }}
                                </td>
                                <td class="text-end text-success">
                                    Rp {{ number_format((float) $p->sum_paid, 0, ',', '.') }}
                                </td>
                                <td class="text-end fw-bold">
                                    Rp {{ number_format((float) $p->sum_remaining, 0, ',', '.') }}
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-blue-lt">{{ $p->nota_count }}</span>
                                </td>
                                <td>
                                    @if ($p->nearest_due)
                                        {{ \Carbon\Carbon::parse($p->nearest_due)->format('d/m/Y') }}
                                    @else
                                        <span class="text-secondary">-</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="card-body text-secondary">
                Belum ada ringkasan pihak untuk filter saat ini.
            </div>
        @endif
    </div>
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Daftar</h3>
            <form class="ms-auto d-flex gap-2 align-items-center" method="GET">
                <input
                    name="q"
                    value="{{ request('q') }}"
                    class="form-control form-control-sm"
                    placeholder="Cari pihak"
                >
                <select name="type" class="form-select form-select-sm">
                    <option value="">Semua</option>
                    <option value="hutang" {{ request('type') == 'hutang' ? 'selected' : '' }}>Hutang</option>
                    <option value="piutang" {{ request('type') == 'piutang' ? 'selected' : '' }}>Piutang</option>
                </select>
                <select name="status" class="form-select form-select-sm">
                    <option value="">Semua status</option>
                    @foreach (['belum', 'sebagian', 'lunas'] as $s)
                        <option value="{{ $s }}" {{ request('status') == $s ? 'selected' : '' }}>
                            {{ $s }}
                        </option>
                    @endforeach
                </select>
                <label class="form-check d-flex align-items-center gap-1 mb-0 text-nowrap">
                    <input
                        type="checkbox"
                        name="tanpa_tempo"
                        value="1"
                        class="form-check-input"
                        {{ request()->boolean('tanpa_tempo') ? 'checked' : '' }}
                    >
                    <span class="form-check-label small">
                        Tanpa tempo
                    </span>
                </label>
                <button class="btn btn-sm btn-outline-primary">Filter</button>
                @if (request()->boolean('tanpa_tempo'))
                    <a
                        href="{{ route('debts.index', collect(request()->query())->except('tanpa_tempo')->all()) }}"
                        class="btn btn-sm btn-outline-secondary"
                    >
                        Reset
                    </a>
                @endif
            </form>
        </div>
        <div class="card-body border-bottom">
            <form
                method="POST"
                action="{{ route('debts.bulkPay') }}"
                id="bulkPayForm"
                class="d-flex flex-wrap align-items-end gap-2"
                onsubmit="return confirm('Lunasi penuh hutang/piutang yang dipilih?')"
            >
                @csrf
                <div>
                    <label class="form-label mb-1">Akun kas (bayar terpilih)</label>
                    <select name="cash_account_id" class="form-select form-select-sm" required>
                        @foreach ($accounts as $a)
                            <option value="{{ $a->id }}">
                                {{ $a->name }} (Rp {{ number_format($a->balance, 0, ',', '.') }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="btn btn-sm btn-success">
                    Bayar Terpilih (lunasi penuh)
                </button>
                <button type="button" class="btn btn-sm btn-outline-primary" id="printSelectedBtn">
                    <i class="ti ti-printer me-1"></i> Cetak Terpilih (massal)
                </button>
                <button
                    type="button"
                    class="btn btn-sm btn-outline-success"
                    id="remindSelectedBtn"
                >
                    <i class="ti ti-brand-whatsapp me-1"></i> Ingatkan Terpilih (WA massal, maks 50)
                </button>
                <span class="form-hint mt-1 w-100">
                    Centang baris yang belum lunas — masing-masing dilunasi penuh & dicatat kas.
                    Cetak massal mendukung hutang (surat pernyataan) & piutang (surat tagihan).
                    Ingatkan Terpilih mengirim WA pengingat per baris, melewati yang tanpa nomor.
                </span>
            </form>
            <form
                method="POST"
                action="{{ route('debts.bulkRemind') }}"
                id="bulkRemindForm"
                class="d-none"
                onsubmit="return confirm('Kirim pengingat WA ke yang dipilih (maks 50)?')"
            >
                @csrf
            </form>
            <form
                method="POST"
                action="{{ route('debts.bulkDueDate') }}"
                id="bulkDueDateForm"
                class="d-flex flex-wrap align-items-end gap-2 mt-2 pt-2 border-top"
                onsubmit="return confirm('Geser jatuh tempo yang dipilih?')"
            >
                @csrf
                <div>
                    <label
                        class="form-label mb-1"
                        for="bulkTargetDate"
                    >
                        Tanggal baru (opsional)
                    </label>
                    <input
                        type="date"
                        name="target_date"
                        id="bulkTargetDate"
                        class="form-control form-control-sm"
                    >
                </div>
                <div>
                    <label
                        class="form-label mb-1"
                        for="bulkShiftDays"
                    >
                        Geser +N hari (1–90)
                    </label>
                    <input
                        type="number"
                        name="shift_days"
                        id="bulkShiftDays"
                        class="form-control form-control-sm"
                        style="width:120px"
                        min="1"
                        max="90"
                        placeholder="+7"
                    >
                </div>
                <button
                    type="button"
                    class="btn btn-sm btn-warning"
                    id="dueDateSelectedBtn"
                >
                    <i class="ti ti-calendar me-1"></i> Geser Terpilih (maks 50)
                </button>
                <span class="form-hint mt-1 w-100">
                    Centang baris belum lunas — isi tanggal baru ATAU +N hari
                    (tanggal baru menang bila keduanya diisi).
                    Yang lunas dilewati &amp; dilaporkan.
                </span>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th class="w-1">
                            <input
                                type="checkbox"
                                class="form-check-input"
                                id="checkAllDebts"
                                title="Pilih semua belum lunas"
                            >
                        </th>
                        <th>Pihak</th>
                        <th>Tipe</th>
                        <th class="text-end">Total</th>
                        <th class="text-end">Sisa</th>
                        <th>Status</th>
                        <th>Jatuh tempo</th>
                        <th>Cicilan</th>
                        <th>Riwayat</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($debts as $d)
                        <tr>
                            <td>
                                @if ($d->status !== 'lunas')
                                    <input
                                        type="checkbox"
                                        class="form-check-input debt-check"
                                        name="debt_ids[]"
                                        value="{{ $d->id }}"
                                        form="bulkPayForm"
                                    >
                                @endif
                            </td>
                            <td>{{ $d->party_name }}</td>
                            <td>
                                <span class="badge bg-{{ $d->type == 'hutang' ? 'red' : 'green' }}-lt">
                                    {{ $d->type }}
                                </span>
                            </td>
                            <td class="text-end">{{ number_format($d->total, 0, ',', '.') }}</td>
                            <td class="text-end">{{ number_format($d->remaining, 0, ',', '.') }}</td>
                            <td>
                                <span class="badge bg-{{ $d->status === 'lunas' ? 'green' : ($d->status === 'belum' ? 'red' : 'yellow') }}-lt">
                                    {{ $d->status }}
                                </span>
                            </td>
                            <td>
                                <div class="text-secondary small mb-1">
                                    {{ $d->due_date?->format('d/m/Y') ?? '-' }}
                                </div>
                                @if (is_null($d->due_date) && $d->status !== 'lunas')
                                    <div class="mb-1">
                                        <span class="badge bg-grey-lt">
                                            tanpa tempo — tetapkan tempo
                                        </span>
                                    </div>
                                @endif
                                <form
                                    method="POST"
                                    action="{{ route('debts.dueDate', $d) }}"
                                    class="d-flex gap-1 align-items-center"
                                    onsubmit="return confirm('Ubah jatuh tempo nota ini?')"
                                >
                                    @csrf
                                    <input
                                        type="date"
                                        name="due_date"
                                        class="form-control form-control-sm"
                                        style="width:135px"
                                        value="{{ $d->due_date?->format('Y-m-d') ?? '' }}"
                                    >
                                    <button
                                        type="submit"
                                        class="btn btn-sm btn-outline-secondary"
                                        title="Simpan jatuh tempo"
                                    >
                                        <i class="ti ti-calendar me-1"></i>
                                    </button>
                                </form>
                            </td>
                            <td style="min-width:220px">
                                @if ($d->status !== 'lunas')
                                    <form
                                        method="POST"
                                        action="{{ route('debts.update', $d) }}"
                                        enctype="multipart/form-data"
                                        onsubmit="return confirm('Catat pembayaran ini?')"
                                    >
                                        @csrf
                                        @method('PUT')
                                        <div class="d-flex gap-1 mb-1">
                                            <input
                                                type="number"
                                                name="pay_amount"
                                                min="1"
                                                max="{{ $d->remaining }}"
                                                class="form-control form-control-sm"
                                                style="width:110px"
                                                placeholder="Rp"
                                                required
                                            >
                                            <select
                                                name="cash_account_id"
                                                class="form-select form-select-sm"
                                                required
                                            >
                                                @foreach ($accounts as $a)
                                                    <option value="{{ $a->id }}">
                                                        {{ $a->name }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="mb-1">
                                            <input
                                                type="file"
                                                name="bukti"
                                                class="form-control form-control-sm"
                                                accept="image/*"
                                                title="Foto bukti pembayaran (opsional)"
                                            >
                                            <div class="form-text">
                                                Bukti opsional (gambar, maks 2 MB).
                                            </div>
                                        </div>
                                        <button class="btn btn-sm btn-primary w-100">
                                            Bayar
                                        </button>
                                    </form>
                                @else
                                    <span class="text-success">Lunas</span>
                                @endif
                            </td>
                            <td>
                                @php
                                    $riw = ($paymentsByDebt ?? collect())->get($d->id, collect());
                                @endphp
                                @if ($riw->count())
                                    <details>
                                        <summary class="small">
                                            {{ $riw->count() }}x bayar
                                        </summary>
                                        <ul class="list-unstyled small mb-0 mt-1">
                                            @foreach ($riw as $p)
                                                @php
                                                    $buktiPayPath = null;
                                                    if (preg_match('/\[BUKTI:(.*?)\]/', $p->description ?? '', $mPay)) {
                                                        $buktiPayPath = trim($mPay[1]);
                                                    }
                                                @endphp
                                                <li class="mb-1">
                                                    <span class="text-secondary">
                                                        {{ $p->created_at ? $p->created_at->format('d/m/y') : '-' }}
                                                    </span>
                                                    Rp {{ number_format($p->amount, 0, ',', '.') }}
                                                    @if ($buktiPayPath)
                                                        <a
                                                            href="{{ asset('storage/' . $buktiPayPath) }}"
                                                            target="_blank"
                                                            class="ms-1"
                                                        >
                                                            <i class="ti ti-paperclip me-1"></i> Bukti
                                                        </a>
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    </details>
                                @else
                                    <span class="text-secondary small">-</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <a
                                        href="{{ route('debts.statement', $d) }}"
                                        class="btn btn-sm btn-outline-primary"
                                        title="{{ $d->type == 'hutang' ? 'Surat pernyataan hutang' : 'Surat tagihan' }}"
                                        target="_blank"
                                    ><i class="ti ti-receipt me-1"></i></a>
                                @if ($d->status !== 'lunas')
                                    <a
                                        href="{{ route('debts.index', array_merge(request()->query(), ['remind' => $d->id])) }}"
                                        class="btn btn-sm btn-outline-success"
                                        title="Kirim pengingat WA"
                                        onclick="return confirm('Kirim pengingat WA ke {{ $d->party_name }}?')"
                                    ><i class="ti ti-brand-whatsapp me-1"></i></a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10">
                                <div class="empty py-3">
                                    <div class="empty-icon"><i class="ti ti-wallet fs-1 text-secondary"></i></div>
                                    <p class="empty-title">Belum ada hutang/piutang</p>
                                    <p class="empty-subtitle text-secondary">Catat lewat formulir Catat Baru — jatuh tempo ≤ 7 hari akan diingatkan otomatis.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $debts->links() }}</div>
    </div>
</div>
<script>
document.getElementById('checkAllDebts')?.addEventListener('change', function () {
    document.querySelectorAll('.debt-check').forEach(function (cb) {
        cb.checked = document.getElementById('checkAllDebts').checked;
    });
});
document.getElementById('printSelectedBtn')?.addEventListener('click', function () {
    const ids = Array.from(document.querySelectorAll('.debt-check:checked')).map(function (cb) {
        return cb.value;
    });
    if (!ids.length) {
        alert('Pilih minimal 1 hutang/piutang dulu (centang baris tabel).');
        return;
    }
    const q = ids.map(function (id) {
        return 'ids[]=' + encodeURIComponent(id);
    }).join('&');
    window.open("{{ route('debts.statements') }}?" + q, '_blank');
});
document.getElementById('remindSelectedBtn')?.addEventListener('click', function () {
    const checked = Array.from(document.querySelectorAll('.debt-check:checked'));
    if (!checked.length) {
        alert('Pilih minimal 1 hutang/piutang dulu (centang baris tabel).');
        return;
    }
    if (checked.length > 50) {
        alert('Maksimal 50 baris per pengingat massal. Kurangi pilihan Anda.');
        return;
    }
    const form = document.getElementById('bulkRemindForm');
    form.querySelectorAll('input[name="debt_ids[]"]').forEach(function (el) {
        el.remove();
    });
    checked.forEach(function (cb) {
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'debt_ids[]';
        hidden.value = cb.value;
        form.appendChild(hidden);
    });
    if (!confirm('Kirim pengingat WA ke ' + checked.length + ' baris terpilih?')) {
        return;
    }
    form.submit();
});
document.getElementById('dueDateSelectedBtn')?.addEventListener('click', function () {
    const checked = Array.from(document.querySelectorAll('.debt-check:checked'));
    if (!checked.length) {
        alert('Pilih minimal 1 hutang/piutang dulu (centang baris tabel).');
        return;
    }
    if (checked.length > 50) {
        alert('Maksimal 50 baris per geser jatuh tempo massal. Kurangi pilihan Anda.');
        return;
    }
    const form = document.getElementById('bulkDueDateForm');
    const target = document.getElementById('bulkTargetDate')?.value || '';
    const shift = document.getElementById('bulkShiftDays')?.value || '';
    if (!target && !shift) {
        alert('Isi tanggal baru ATAU geser +N hari dulu.');
        return;
    }
    form.querySelectorAll('input[name="debt_ids[]"]').forEach(function (el) {
        el.remove();
    });
    checked.forEach(function (cb) {
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'debt_ids[]';
        hidden.value = cb.value;
        form.appendChild(hidden);
    });
    if (!confirm('Geser jatuh tempo ' + checked.length + ' baris terpilih?')) {
        return;
    }
    form.submit();
});
</script>
@push('js')
<script src="/vendor/chart/chart.umd.min.js"></script>
<script>
try {
    const rpAging = (v) => 'Rp ' + Number(v || 0).toLocaleString('id-ID');
    const agingEl = document.getElementById('chartAging');
    if (agingEl && window.Chart) {
        new Chart(agingEl, {
            type: 'bar',
            data: {
                labels: ['0-30 hari', '31-60 hari', '>60 hari'],
                datasets: [
                    {
                        label: 'Hutang',
                        data: [
                            {{ (float) ($aging['hutang']['0-30'] ?? 0) }},
                            {{ (float) ($aging['hutang']['31-60'] ?? 0) }},
                            {{ (float) ($aging['hutang']['>60'] ?? 0) }}
                        ],
                        backgroundColor: '#e74c3c'
                    },
                    {
                        label: 'Piutang',
                        data: [
                            {{ (float) ($aging['piutang']['0-30'] ?? 0) }},
                            {{ (float) ($aging['piutang']['31-60'] ?? 0) }},
                            {{ (float) ($aging['piutang']['>60'] ?? 0) }}
                        ],
                        backgroundColor: '#2fb344'
                    }
                ]
            },
            options: {
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: (c) => ' ' + c.dataset.label + ': ' + rpAging(c.parsed.y)
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { callback: (v) => rpAging(v) }
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
</div>
</div>
@endsection
