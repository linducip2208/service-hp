@extends('layouts.tabler')
@section('title','Booking Online')
@section('content')
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <div class="page-pretitle">Service • Booking</div>
            <h2 class="page-title">Booking Online</h2>
            <div class="text-secondary">Konfirmasi booking → otomatis jadi tiket service.</div>
        </div>
        <div class="col-auto ms-auto d-flex gap-2">
            <a
                href="{{ route('bookings.index', array_merge(request()->query(), ['export' => 'csv'])) }}"
                class="btn btn-outline-success"
            >
                <i class="ti ti-download me-1"></i> Export CSV
            </a>
            <a href="{{ route('bookings.calendar') }}" class="btn btn-outline-primary"><i class="ti ti-calendar me-1"></i> Kalender</a>
        </div>
    </div>
</div>
<div class="row row-cards mt-2">
    <div class="col-sm-6 col-lg-4">
        <div class="card">
            <div class="card-body">
                <div class="subheader">Konversi booking → tiket</div>
                <div class="h1 mb-1">{{ $convRate ?? 0 }}%</div>
                <div class="text-secondary">
                    {{ $statConverted ?? 0 }} dari {{ $statTotal ?? 0 }} booking jadi tiket
                </div>
                <div class="small text-secondary mt-2">
                    Definisi: booking dihitung jadi tiket bila
                    <code>ticket_id</code> terisi (dibuat otomatis saat status →
                    dikonfirmasi). Rumus: <code>konversi = ticket_id NOT NULL / total booking × 100%</code>
                    (seluruh toko, tanpa filter; status
                    <code>selesai</code>/<code>dikonfirmasi</code> tanpa tiket tidak dihitung).
                </div>
            </div>
        </div>
    </div>
</div>
<form method="GET" class="card mt-2">
    <div class="card-body">
        <div class="row g-2">
            <div class="col-md-4">
                <input
                    name="search"
                    value="{{ request('search') }}"
                    class="form-control"
                    placeholder="Cari kode / nama / HP / device..."
                >
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="">-- Semua --</option>
                    @foreach(['menunggu','dikonfirmasi','dibatalkan','selesai'] as $s)
                        <option {{ request('status')==$s?'selected':'' }}>{{ $s }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <input
                    type="month"
                    name="bulan"
                    value="{{ request('bulan') }}"
                    class="form-control"
                    title="Filter bulan jadwal (schedule_date)"
                >
            </div>
            <div class="col-md-2">
                <div class="d-flex gap-1">
                    <button class="btn btn-outline-primary w-100">Filter</button>
                    @if(request('bulan') || request('status') || request('search'))
                        <a
                            href="{{ route('bookings.index') }}"
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
    <div class="card-body border-bottom">
        <div class="d-flex flex-wrap align-items-end gap-2">
            <form
                method="POST"
                action="{{ route('bookings.bulkConfirm') }}"
                id="bulkConfirmForm"
                class="d-flex flex-wrap align-items-end gap-2"
                onsubmit="return confirmBulkBookings(this, 'Konfirmasi booking terpilih & buatkan tiket per booking?')"
            >
                @csrf
                <button
                    type="submit"
                    class="btn btn-sm btn-primary"
                >
                    Konfirmasi Terpilih
                </button>
                <span class="form-hint mt-1 w-100">
                    Centang baris yang belum ada tiketnya — tiap booking terpilih dibuatkan 1 tiket
                    (customer dicocokkan via HP bila belum ada). Baris yang sudah ada tiketnya dilewati,
                    hasil dilaporkan per baris.
                </span>
            </form>
            <form
                method="POST"
                action="{{ route('bookings.bulkCancel') }}"
                id="bulkCancelForm"
                class="d-flex flex-wrap align-items-end gap-2"
                onsubmit="return confirmBulkBookings(this, 'Batalkan booking terpilih yang masih menunggu?')"
            >
                @csrf
                <button
                    type="submit"
                    class="btn btn-sm btn-outline-danger"
                >
                    Batalkan Terpilih
                </button>
                <span class="form-hint mt-1 w-100">
                    Hanya status menunggu yang dibatalkan — baris yang sudah ada tiketnya
                    atau statusnya bukan menunggu dilewati, hasil dilaporkan per baris.
                </span>
            </form>
            <form
                method="POST"
                action="{{ route('bookings.bulkRemind') }}"
                id="bulkRemindForm"
                class="d-flex flex-wrap align-items-end gap-2"
                onsubmit="return confirmBulkBookings(this, 'Kirim pengingat WA ke booking menunggu terpilih?')"
            >
                @csrf
                <button
                    type="submit"
                    class="btn btn-sm btn-outline-success"
                >
                    Ingatkan Terpilih
                </button>
                <span class="form-hint mt-1 w-100">
                    Hanya status menunggu tanpa tiket yang diingatkan via template
                    pengingat yang sama dengan tombol Ingatkan per baris — baris tanpa
                    nomor HP atau yang sudah ada tiketnya dilewati, hasil dilaporkan
                    per baris (maks 50).
                </span>
            </form>
            <form
                method="POST"
                action="{{ route('bookings.bulkTransfer') }}"
                id="bulkTransferBookingForm"
                class="d-flex flex-wrap align-items-end gap-2 w-100"
                onsubmit="return confirmBulkTransferBookings(this, 'Pindah booking menunggu/dikonfirmasi terpilih ke toko tujuan?')"
            >
                @csrf
                <select
                    name="to_store_id"
                    class="form-select form-select-sm"
                    style="max-width: 260px"
                    required
                    title="Pilih toko tujuan pindah massal"
                >
                    <option value="">-- Pilih Toko Tujuan --</option>
                    @foreach(($stores ?? collect()) as $st)
                        <option value="{{ $st->id }}">{{ $st->name }}</option>
                    @endforeach
                </select>
                <button
                    type="submit"
                    class="btn btn-sm btn-outline-dark"
                >
                    Pindah Toko Terpilih
                </button>
                <span class="form-hint mt-1 w-100">
                    Hanya status menunggu/dikonfirmasi yang dipindah
                    (cermin pindah massal tiket: update store_id,
                    tanpa ubah status) — baris yang sudah ada tiketnya
                    atau statusnya selesai/dibatalkan dilewati,
                    hasil dilaporkan per baris (maks 50).
                </span>
            </form>
            <form
                method="POST"
                action="{{ route('bookings.bulkReschedule') }}"
                id="bulkRescheduleBookingForm"
                class="d-flex flex-wrap align-items-end gap-2 w-100"
                onsubmit="return confirmBulkRescheduleBookings(this, 'Geser jadwal booking menunggu/dikonfirmasi terpilih?')"
            >
                @csrf
                <input
                    type="datetime-local"
                    name="target_date"
                    class="form-control form-control-sm"
                    style="max-width: 230px"
                    title="Tanggal baru jadwal (opsional bila pakai geser +N hari)"
                >
                <span class="text-secondary small">atau</span>
                <input
                    type="number"
                    name="shift_days"
                    class="form-control form-control-sm"
                    style="max-width: 130px"
                    min="1"
                    max="90"
                    placeholder="+N hari"
                    title="Geser +N hari dari jadwal lama (1–90)"
                >
                <button
                    type="submit"
                    class="btn btn-sm btn-outline-primary"
                >
                    Geser Jadwal Terpilih
                </button>
                <span class="form-hint mt-1 w-100">
                    Isi tanggal baru ATAU geser +N hari (bila keduanya
                    diisi, tanggal baru yang dipakai) — hanya status
                    menunggu/dikonfirmasi tanpa tiket yang digeser
                    (update schedule_date massal), baris yang sudah ada
                    tiketnya atau statusnya selesai/dibatalkan dilewati,
                    hasil dilaporkan per baris (maks 50).
                </span>
            </form>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table card-table table-vcenter">
            <thead>
                <tr>
                    <th class="w-1">
                        <input
                            type="checkbox"
                            class="form-check-input"
                            id="checkAllBookings"
                            title="Pilih semua belum bertiket"
                        >
                    </th>
                    <th>Kode</th>
                    <th>Pelanggan</th>
                    <th>Device</th>
                    <th>Jadwal</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($bookings as $b)
                    <tr>
                        <td>
                            @if(!$b->ticket_id)
                                <input
                                    type="checkbox"
                                    class="form-check-input booking-check"
                                    name="booking_ids[]"
                                    value="{{ $b->id }}"
                                    data-status="{{ $b->status }}"
                                    data-ticket="{{ $b->ticket_id ? 1 : 0 }}"
                                    title="Pilih booking {{ $b->code }}"
                                >
                            @endif
                        </td>
                        <td class="fw-bold">
                            {{ $b->code }}
                            @if($b->ticket_id)
                                <div class="small">
                                    <a href="{{ route('services.show',$b->ticket_id) }}">
                                        Tiket #{{ $b->ticket_id }}
                                    </a>
                                </div>
                            @endif
                        </td>
                        <td>
                            {{ $b->customer_name }}
                            <div class="text-secondary small">{{ $b->customer_phone }}</div>
                        </td>
                        <td>
                            {{ $b->device }}
                            <div class="text-secondary small">{{ \Str::limit($b->complaint,50) }}</div>
                        </td>
                        <td class="text-secondary">{{ $b->schedule_date }}</td>
                        <td>
                            <span class="badge {{ match($b->status){'menunggu'=>'bg-yellow-lt','dikonfirmasi'=>'bg-blue-lt','dibatalkan'=>'bg-red-lt','selesai'=>'bg-green-lt',default=>'bg-secondary-lt'} }}">
                                {{ $b->status }}
                            </span>
                        </td>
                        <td class="text-end text-nowrap">
                            <form
                                method="POST"
                                action="{{ route('bookings.remind',$b) }}"
                                class="d-inline"
                                onsubmit="return confirm('Kirim pengingat WA ke pelanggan?')"
                            >
                                @csrf
                                <button class="btn btn-sm btn-outline-success" title="Kirim pengingat WA">
                                    Ingatkan
                                </button>
                            </form>
                            @if($b->status==='menunggu')
                                <form
                                    method="POST"
                                    action="{{ route('bookings.update',$b) }}"
                                    class="d-inline"
                                >
                                    @csrf
                                    @method('PUT')
                                    <input type="hidden" name="status" value="dikonfirmasi">
                                    <button
                                        class="btn btn-sm btn-primary"
                                        onclick="return confirm('Konfirmasi booking & buat tiket service?')"
                                    >
                                        Konfirmasi → Tiket
                                    </button>
                                </form>
                                <form
                                    method="POST"
                                    action="{{ route('bookings.update',$b) }}"
                                    class="d-inline"
                                >
                                    @csrf
                                    @method('PUT')
                                    <input type="hidden" name="status" value="dibatalkan">
                                    <button
                                        class="btn btn-sm btn-ghost-danger"
                                        onclick="return confirm('Tolak / batalkan booking ini?')"
                                    >
                                        Tolak
                                    </button>
                                </form>
                            @elseif($b->status==='dikonfirmasi')
                                <form
                                    method="POST"
                                    action="{{ route('bookings.update',$b) }}"
                                    class="d-inline"
                                >
                                    @csrf
                                    @method('PUT')
                                    <input type="hidden" name="status" value="selesai">
                                    <button
                                        class="btn btn-sm btn-success"
                                        onclick="return confirm('Tandai booking ini selesai?')"
                                    >
                                        Selesai
                                    </button>
                                </form>
                            @endif
                            <form
                                method="POST"
                                action="{{ route('bookings.destroy',$b) }}"
                                class="d-inline"
                                onsubmit="return confirm('Hapus?')"
                            >
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-sm btn-ghost-secondary">Hapus</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
                            <div class="empty py-3">
                                <div class="empty-icon">
                                    <i class="ti ti-calendar-event fs-1 text-secondary"></i>
                                </div>
                                <p class="empty-title">Belum ada booking</p>
                                <p class="empty-subtitle text-secondary">
                                    Booking masuk otomatis dari formulir publik. Bagikan link lacak/booking ke pelanggan.
                                </p>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if(method_exists($bookings,'links'))
        <div class="card-footer">{{ $bookings->links() }}</div>
    @endif
</div>
<script>
function collectCheckedBookingIds() {
    return Array
        .from(document.querySelectorAll('.booking-check:checked'))
        .map(function (cb) {
            return cb.value;
        });
}
function confirmBulkBookings(form, message) {
    var ids = collectCheckedBookingIds();
    if (ids.length === 0) {
        alert('Centang dulu minimal 1 booking.');
        return false;
    }
    if (!confirm(message)) {
        return false;
    }
    ids.forEach(function (id) {
        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'booking_ids[]';
        hidden.value = id;
        form.appendChild(hidden);
    });
    return true;
}
function confirmBulkTransferBookings(form, message) {
    var ids = collectCheckedBookingIds();
    if (ids.length === 0) {
        alert('Centang dulu minimal 1 booking.');
        return false;
    }
    if (ids.length > 50) {
        alert('Maksimal 50 booking per pindah massal.');
        return false;
    }
    var dest = form.querySelector('select[name="to_store_id"]');
    if (!dest || dest.value === '') {
        alert('Pilih toko tujuan dulu.');
        return false;
    }
    if (!confirm(message)) {
        return false;
    }
    ids.forEach(function (id) {
        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'booking_ids[]';
        hidden.value = id;
        form.appendChild(hidden);
    });
    return true;
}
function confirmBulkRescheduleBookings(form, message) {
    var ids = collectCheckedBookingIds();
    if (ids.length === 0) {
        alert('Centang dulu minimal 1 booking.');
        return false;
    }
    if (ids.length > 50) {
        alert('Maksimal 50 booking per geser jadwal massal.');
        return false;
    }
    var target = form.querySelector('input[name="target_date"]');
    var shift = form.querySelector('input[name="shift_days"]');
    var hasTarget = target && target.value !== '';
    var hasShift = shift && shift.value !== '' && parseInt(shift.value, 10) >= 1;
    if (!hasTarget && !hasShift) {
        alert('Isi tanggal baru ATAU geser +N hari dulu.');
        return false;
    }
    if (!confirm(message)) {
        return false;
    }
    ids.forEach(function (id) {
        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'booking_ids[]';
        hidden.value = id;
        form.appendChild(hidden);
    });
    return true;
}
document
    .getElementById('checkAllBookings')
    ?.addEventListener('change', function () {
        var checked = document.getElementById('checkAllBookings').checked;
        document
            .querySelectorAll('.booking-check')
            .forEach(function (cb) {
                cb.checked = checked;
            });
    });
</script>
@endsection
