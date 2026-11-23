@extends('layouts.tabler')
@section('title','Garansi & Klaim')
@section('content')
<div class="page-header d-print-none"><div class="row align-items-center"><div class="col"><div class="page-pretitle">Service • Garansi</div><h2 class="page-title">Garansi & Klaim</h2><div class="text-secondary">Garansi otomatis dari serah terima service · proses klaim + WA pelanggan.</div></div>
<div class="col-auto ms-auto d-flex gap-2">
<a
    href="{{ route('warranties.index', array_merge(request()->query(), ['export' => 'csv'])) }}"
    class="btn btn-outline-success"
>
    <i class="ti ti-download me-1"></i> Export CSV
</a>
<button class="btn btn-outline-secondary" onclick="window.print()"><i class="ti ti-printer me-1"></i> Cetak</button>
<button
    type="button"
    class="btn btn-outline-primary"
    id="printWarrantyCardsBtn"
    title="Cetak kartu garansi terpilih (maks 50)"
>
    <i class="ti ti-id me-1"></i> Cetak Kartu Terpilih
</button>
</div></div></div>
<div class="print-area">
<form method="GET" class="card mt-2">
    <div class="card-body">
        <div class="row g-2">
            <div class="col-md-3">
                <input
                    name="search"
                    value="{{ request('search') }}"
                    class="form-control"
                    placeholder="Cari pelanggan / catatan / ref..."
                >
            </div>
            <div class="col-md-2">
                <select name="status" class="form-select">
                    <option value="">-- Semua status --</option>
                    @foreach(['aktif','klaim','selesai'] as $s)
                        <option {{ request('status')==$s?'selected':'' }}>{{ $s }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <select name="type" class="form-select">
                    <option value="">-- Semua tipe --</option>
                    @foreach(['toko','distributor','service'] as $t)
                        <option {{ request('type')==$t?'selected':'' }}>{{ $t }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <input
                    type="date"
                    name="from"
                    value="{{ request('from') }}"
                    class="form-control"
                    title="Dari tanggal mulai garansi (start_date)"
                >
            </div>
            <div class="col-md-2">
                <input
                    type="date"
                    name="to"
                    value="{{ request('to') }}"
                    class="form-control"
                    title="Sampai tanggal mulai garansi (start_date)"
                >
            </div>
            <div class="col-md-1">
                <div class="d-flex gap-1">
                    <button class="btn btn-outline-primary w-100">Filter</button>
                    @if(request('from') || request('to') || request('status') || request('type') || request('search'))
                        <a
                            href="{{ route('warranties.index') }}"
                            class="btn btn-outline-secondary"
                            title="Reset filter"
                        >
                            Reset
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </div>
</form>
<div class="card mt-2">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-end gap-2">
            <form
                method="POST"
                action="{{ route('warranties.bulkRemind') }}"
                id="bulkWarrantyRemindForm"
                class="d-flex flex-wrap align-items-end gap-2"
                onsubmit="return confirmBulkWarranties(this, 'Kirim pengingat WA garansi ke baris terpilih?')"
            >
                @csrf
                <button
                    type="submit"
                    class="btn btn-sm btn-outline-success"
                >
                    <i class="ti ti-brand-whatsapp me-1"></i> Ingatkan Terpilih
                </button>
                <span class="form-hint mt-1 w-100">
                    Hanya garansi aktif yang diingatkan via pesan H-7 yang sama
                    dengan pengingat otomatis — baris tanpa nomor HP atau status
                    bukan aktif dilewati, hasil dilaporkan per baris (maks 50).
                </span>
            </form>
            <form
                method="POST"
                action="{{ route('warranties.bulkClaim') }}"
                id="bulkWarrantyClaimForm"
                class="d-flex flex-wrap align-items-end gap-2"
                onsubmit="return confirmBulkWarranties(this, 'Proses klaim untuk garansi AKTIF terpilih?')"
            >
                @csrf
                <button
                    type="submit"
                    class="btn btn-sm btn-warning"
                >
                    🛠 Proses Klaim Terpilih
                </button>
                <span class="form-hint mt-1 w-100">
                    Hanya status aktif yang diproses (→ klaim + WA pelanggan),
                    baris bukan aktif dilewati, hasil dilaporkan per baris
                    (maks 50).
                </span>
            </form>
            <form
                method="POST"
                action="{{ route('warranties.bulkExtend') }}"
                id="bulkWarrantyExtendForm"
                class="d-flex flex-wrap align-items-end gap-2"
                onsubmit="return confirmBulkWarranties(this, 'Perpanjang garansi AKTIF terpilih?')"
            >
                @csrf
                <label
                    class="form-label mb-0"
                    for="bulkWarrantyExtendDays"
                >
                    Tambah hari
                </label>
                <input
                    type="number"
                    id="bulkWarrantyExtendDays"
                    name="extend_days"
                    class="form-control form-control-sm"
                    value="{{ old('extend_days', 30) }}"
                    min="1"
                    max="365"
                    step="1"
                    style="max-width:110px"
                    title="Jumlah hari perpanjangan (1–365, default 30)"
                />
                <button
                    type="submit"
                    class="btn btn-sm btn-outline-primary"
                >
                     Perpanjang Terpilih
                </button>
                <span class="form-hint mt-1 w-100">
                    Hanya status aktif yang diperpanjang (berakhir baru =
                    maks(hari ini, berakhir lama) + N hari),
                    baris tanpa tanggal berakhir atau bukan aktif dilewati,
                    hasil dilaporkan per baris (maks 50).
                </span>
            </form>
        </div>
    </div>
</div>
<div class="card mt-2"><div class="table-responsive"><table class="table card-table table-vcenter"><thead><tr>
<th class="w-1">
<input
    type="checkbox"
    class="form-check-input"
    id="checkAllWarranties"
    title="Pilih semua"
/>
</th>
<th>ID</th><th>Pelanggan</th><th>Ref Tiket</th><th>Periode</th><th>Status</th><th>Catatan Klaim</th><th></th></tr></thead><tbody>
@forelse($warranties as $w)<tr>
<td>
<input
    type="checkbox"
    class="form-check-input warranty-check"
    name="warranty_ids[]"
    value="{{ $w->id }}"
    title="Pilih garansi #{{ $w->id }}"
/>
</td>
<td class="fw-bold">#{{ $w->id }}</td>
<td>{{ $w->customer->name ?? '-' }}<div class="text-secondary small">{{ $w->customer->phone ?? '' }}</div></td>
<td>@if($w->ref_type==='service')<a href="{{ route('services.show',$w->ref_id) }}">Tiket #{{ $w->ref_id }}</a>@else<span class="text-secondary">{{ $w->ref_type ?? '-' }} #{{ $w->ref_id ?? '-' }}</span>@endif<div class="text-secondary small">{{ $w->type }}</div></td>
<td class="small text-nowrap">{{ $w->start_date?->format('d/m/Y') }} → {{ $w->end_date?->format('d/m/Y') }}@if($w->is_expired ?? false)<div><span class="badge bg-red">Kedaluwarsa</span></div>@endif</td>
<td><span class="badge {{ match($w->status){'aktif'=>'bg-green-lt','klaim'=>'bg-yellow-lt','selesai'=>'bg-dark-lt',default=>'bg-secondary-lt'} }}">{{ $w->status }}</span></td>
<td class="small">{{ $w->claim_note ?? '-' }}</td>
<td
    class="text-end text-nowrap"
>
<a
    href="{{ route('warranties.card',$w) }}"
    target="_blank"
    class="btn btn-sm btn-outline-secondary"
>
    <i class="ti ti-id me-1"></i> Kartu
</a>
@if($w->status==='aktif')
@if(trim((string) ($w->customer->phone ?? '')) !== '')
<form
    method="POST"
    action="{{ route('warranties.bulkRemind') }}"
    class="d-inline"
    onsubmit="return confirm('Kirim pengingat WA garansi #{{ $w->id }}?')"
>
    @csrf
    <input
        type="hidden"
        name="warranty_ids[]"
        value="{{ $w->id }}"
    />
    <button
        type="submit"
        class="btn btn-sm btn-outline-success ms-1"
        title="Ingatkan garansi #{{ $w->id }} via WA (reuse bulkRemind)"
    >
        <i class="ti ti-brand-whatsapp me-1"></i> Ingatkan
    </button>
</form>
@else
<button
    type="button"
    class="btn btn-sm btn-outline-secondary ms-1"
    disabled
    title="Tanpa nomor HP — pengingat WA dilewati (garansi #{{ $w->id }})"
>
    <i class="ti ti-brand-whatsapp me-1"></i> Ingatkan
</button>
@endif
<form
    method="POST"
    action="{{ route('warranties.claim',$w) }}"
    class="d-inline"
    onsubmit="return confirm('Proses klaim garansi ini?')"
>
    @csrf
    <input
        type="hidden"
        name="hasil"
        value="klaim"
    />
    <input
        type="hidden"
        name="claim_note"
        value="Klaim diterima di toko"
    />
    <button
        type="submit"
        class="btn btn-sm btn-warning"
    >
        Proses Klaim
    </button>
</form>
<form
    method="POST"
    action="{{ route('warranties.ticket',$w) }}"
    class="d-inline"
    onsubmit="return confirm('Buat tiket service dari garansi #{{ $w->id }}?')"
>
    @csrf
    <button
        type="submit"
        class="btn btn-sm btn-primary ms-1"
    >
        Buat Tiket
    </button>
</form>
@elseif($w->status==='klaim')
<form
    method="POST"
    action="{{ route('warranties.ticket',$w) }}"
    class="d-inline"
    onsubmit="return confirm('Buat tiket service dari garansi #{{ $w->id }}?')"
>
    @csrf
    <button
        type="submit"
        class="btn btn-sm btn-primary"
    >
        Buat Tiket
    </button>
</form>
<form
    method="POST"
    action="{{ route('warranties.claim',$w) }}"
    class="d-inline row g-1 mt-1"
    onsubmit="return confirm('Selesaikan klaim & kirim WA ke pelanggan?')"
>
    @csrf
    <input
        type="hidden"
        name="hasil"
        value="selesai"
    />
    <input
        name="claim_note"
        class="form-control form-control-sm"
        placeholder="Hasil pengerjaan garansi..."
        style="min-width:160px"
    />
    <button
        type="submit"
        class="btn btn-sm btn-success mt-1"
    >
        Selesai + WA
    </button>
</form>
@else<span class="text-secondary small">Selesai</span>@endif
</td></tr>
@empty<tr><td colspan="8"><div class="empty py-3"><div class="empty-icon"><i class="ti ti-shield-check fs-1 text-secondary"></i></div><p class="empty-title">Belum ada garansi</p><p class="empty-subtitle text-secondary">Garansi dibuat otomatis setiap tiket diserahterimakan ke pelanggan.</p></div></td></tr>@endforelse
</tbody></table></div>@if(method_exists($warranties,'links'))<div class="card-footer">{{ $warranties->links() }}</div>@endif</div>
</div>
<script>
function collectCheckedWarrantyIds() {
    return Array
        .from(document.querySelectorAll('.warranty-check:checked'))
        .map(function (cb) {
            return cb.value;
        });
}
function confirmBulkWarranties(form, message) {
    var ids = collectCheckedWarrantyIds();
    if (ids.length === 0) {
        alert('Pilih minimal 1 garansi dulu (centang baris tabel).');
        return false;
    }
    if (ids.length > 50) {
        alert('Maksimal 50 garansi per proses massal — kurangi pilihan Anda.');
        return false;
    }
    if (!confirm(message)) {
        return false;
    }
    ids.forEach(function (id) {
        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'warranty_ids[]';
        hidden.value = id;
        form.appendChild(hidden);
    });
    return true;
}
(function () {
    var checkAll = document.getElementById('checkAllWarranties');
    if (checkAll) {
        checkAll.addEventListener('change', function () {
            document.querySelectorAll('.warranty-check').forEach(function (cb) {
                cb.checked = checkAll.checked;
            });
        });
    }
    var btn = document.getElementById('printWarrantyCardsBtn');
    if (btn) {
        btn.addEventListener('click', function () {
            var ids = Array.from(document.querySelectorAll('.warranty-check:checked')).map(function (cb) {
                return cb.value;
            });
            if (!ids.length) {
                alert('Pilih minimal 1 garansi dulu (centang baris tabel).');
                return;
            }
            if (ids.length > 50) {
                alert('Maksimal 50 kartu per cetak massal — kurangi pilihan Anda.');
                return;
            }
            var q = ids.map(function (id) {
                return 'ids[]=' + encodeURIComponent(id);
            }).join('&');
            window.open("{{ route('warranties.cards') }}?" + q, '_blank');
        });
    }
})();
</script>
@endsection
