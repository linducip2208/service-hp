@extends('layouts.tabler')
@section('title','Tiket Service')
@section('content')
<div class="page-header d-print-none">
  <div class="row align-items-center">
    <div class="col"><div class="page-pretitle">Service</div><h2 class="page-title">Tiket Service</h2><div class="text-secondary">Kelola penerimaan, diagnosa, pengerjaan & serah terima.</div></div>
    <div class="col-auto ms-auto d-flex gap-2"><a href="{{ route('help') }}#service" class="btn btn-outline-secondary" title="Bantuan Service"><i class="ti ti-help"></i></a><a href="{{ route('services.report') }}" class="btn btn-outline-info"><i class="ti ti-chart-bar me-1"></i>Laporan</a><a href="{{ route('services.create') }}" class="btn btn-primary"><i class="ti ti-plus me-1"></i>Terima HP Baru</a></div>
  </div>
</div>
<form
  method="GET"
  id="serviceFilterForm"
  class="card mt-2"
>
  <div class="card-body">
    <div class="row g-2">
      <div class="col-md-4">
        <input
          type="text"
          name="search"
          value="{{ request('search') }}"
          class="form-control"
          placeholder="Cari tiket / IMEI / nama / no. HP (08/628)..."
          title="Bisa cari by no. HP pelanggan — format 08… atau 628… sama-sama ketemu"
        >
      </div>
      <div class="col-md-3">
        <select
          name="status"
          class="form-select"
        >
          <option value="">-- Semua Status --</option>
          @foreach($statuses as $s)
            <option
              value="{{ $s }}"
              {{ request('status') == $s ? 'selected' : '' }}
            >
              {{ $s }}
            </option>
          @endforeach
        </select>
      </div>
      <div class="col-md-3">
        <select
          name="technician_id"
          class="form-select"
        >
          <option value="">-- Semua Teknisi --</option>
          @foreach($technicians as $t)
            <option
              value="{{ $t->id }}"
              {{ request('technician_id') == $t->id ? 'selected' : '' }}
            >
              {{ $t->name }}
            </option>
          @endforeach
        </select>
      </div>
      <div class="col-md-2">
        <select
          name="priority"
          class="form-select"
        >
          <option value="">-- Semua Prioritas --</option>
          @foreach(['rendah','normal','mendesak'] as $p)
            <option
              value="{{ $p }}"
              {{ request('priority') == $p ? 'selected' : '' }}
            >
              {{ $p }}
            </option>
          @endforeach
        </select>
      </div>
      <div class="col-md-2">
        <input
          name="from"
          type="date"
          value="{{ request('from') }}"
          class="form-control"
          title="Tiket masuk dari tanggal"
        >
      </div>
      <div class="col-md-2">
        <input
          name="to"
          type="date"
          value="{{ request('to') }}"
          class="form-control"
          title="Tiket masuk sampai tanggal"
        >
      </div>
      <div class="col-md-2">
        <select
          name="sort"
          class="form-select"
        >
          <option value="">-- Terbaru --</option>
          <option
            value="priority"
            {{ request('sort') == 'priority' ? 'selected' : '' }}
          >
            Prioritas dulu
          </option>
        </select>
      </div>
      <div class="col-md-2">
        <button class="btn btn-outline-primary w-100">Filter</button>
      </div>
      <div class="col-md-12">
        <div
          class="d-flex flex-wrap gap-1"
          role="group"
          aria-label="Preset tanggal cepat"
        >
          <button
            type="button"
            class="btn btn-sm btn-outline-primary"
            data-preset="today"
          >
            Hari ini
          </button>
          <button
            type="button"
            class="btn btn-sm btn-outline-primary"
            data-preset="week"
          >
            7 Hari
          </button>
          <button
            type="button"
            class="btn btn-sm btn-outline-primary"
            data-preset="month"
          >
            Bulan ini
          </button>
        </div>
      </div>
      <div class="col-md-12 d-flex gap-2">
        <a
          href="{{ route('services.index', array_merge(request()->except('export'), ['export' => 'csv'])) }}"
          class="btn btn-outline-success btn-sm"
        >
          <i class="ti ti-download me-1"></i> Export CSV
        </a>
        <a
          href="{{ route('services.report', request()->only(['status','technician_id','priority','from','to'])) }}"
          class="btn btn-outline-info btn-sm"
        >
          <i class="ti ti-chart-bar me-1"></i> Laporan Periode
        </a>
      </div>
    </div>
  </div>
</form>
<script>
(function () {
    var form = document.getElementById('serviceFilterForm');
    if (!form) {
        return;
    }
    function fmt(d) {
        return d.toISOString().slice(0, 10);
    }
    function applyRange(from, to) {
        var fromEl = form.querySelector('input[name="from"]');
        var toEl = form.querySelector('input[name="to"]');
        if (fromEl) {
            fromEl.value = from;
        }
        if (toEl) {
            toEl.value = to;
        }
        form.submit();
    }
    form.querySelectorAll('[data-preset]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var now = new Date();
            var mode = btn.getAttribute('data-preset');
            if (mode === 'today') {
                var t = fmt(now);
                applyRange(t, t);
            } else if (mode === 'week') {
                var start = new Date(now);
                start.setDate(start.getDate() - 6);
                applyRange(fmt(start), fmt(now));
            } else {
                var first = new Date(now.getFullYear(), now.getMonth(), 1);
                applyRange(fmt(first), fmt(now));
            }
        });
    });
})();
</script>
@if(!empty($urgentCount))
<div class="alert alert-danger mt-2 d-flex align-items-center" role="alert">
  <i class="ti ti-alert-triangle me-2 fs-2"></i>
  <div><strong>{{ $urgentCount }} tiket MENDESAK aktif!</strong>
  <span class="text-muted d-block small">Kerjakan dulu: @foreach(($urgentTickets ?? collect()) as $u)<a href="{{ route('services.show',$u) }}" class="fw-bold">{{ $u->ticket_no }}</a>{{ !$loop->last ? ', ' : '' }}@endforeach <a href="{{ route('services.index', array_merge(request()->query(), ['sort' => 'priority'])) }}" class="ms-1">Urutkan prioritas →</a></span></div>
  </div>
@endif
<div class="card mt-2"><div class="card-body border-bottom d-flex flex-wrap align-items-start gap-2">
  <button type="button" class="btn btn-sm btn-outline-primary" id="printTicketsBtn"><i class="ti ti-printer me-1"></i>Cetak Terpilih (maks 50)</button>
  <span class="form-hint m-0">
    Centang tiket di tabel lalu cetak gabungan 1 halaman (ringkas + barcode, tiap tiket ganti halaman).
  </span>
  <form
    method="POST"
    action="{{ route('services.bulkPickupRemind') }}"
    id="bulkPickupForm"
    class="d-flex flex-wrap align-items-end gap-2 w-100"
    onsubmit="return confirmBulkPickup(this, 'Kirim pengingat WA ambil ke tiket siap_diambil terpilih?')"
  >
    @csrf
    <button
      type="submit"
      class="btn btn-sm btn-outline-success"
    >
      Ingatkan Ambil Terpilih
    </button>
    <span class="form-hint m-0 w-100">
      Hanya status siap_diambil yang diingatkan via template
      siap_diambil yang sama dengan notif otomatis —
      baris tanpa nomor HP atau statusnya bukan siap_diambil
      dilewati, hasil dilaporkan per baris (maks 50).
    </span>
  </form>
  <form
    method="POST"
    action="{{ route('services.bulkTechnicianRemind') }}"
    id="bulkTechnicianForm"
    class="d-flex flex-wrap align-items-end gap-2 w-100"
    onsubmit="return confirmBulkTechnician(this, 'Kirim pengingat WA ke teknisi penanggung jawab untuk tiket aktif terpilih?')"
  >
    @csrf
    <button
      type="submit"
      class="btn btn-sm btn-outline-warning"
    >
      <i class="ti ti-bell me-1"></i> Ingatkan Teknisi Terpilih
    </button>
    <span class="form-hint m-0 w-100">
      Mengelompokkan tiket aktif terpilih per teknisi
      (1 pesan WA per teknisi berisi daftar tiketnya) —
      status final diserahkan/batal, baris tanpa teknisi,
      atau teknisi tanpa nomor HP dilewati,
      hasil dilaporkan per teknisi (maks 50 tiket).
    </span>
  </form>
  <form
    method="POST"
    action="{{ route('services.bulkPriority') }}"
    id="bulkPriorityForm"
    class="d-flex flex-wrap align-items-end gap-2 w-100"
    onsubmit="return confirmBulkPriority(this, 'Ubah prioritas tiket aktif terpilih?')"
  >
    @csrf
    <select
      name="priority"
      class="form-select form-select-sm w-auto"
      title="Prioritas tujuan untuk tiket terpilih"
      required
    >
      <option value="normal" selected>normal</option>
      <option value="rendah">rendah</option>
      <option value="mendesak">mendesak</option>
    </select>
    <button
      type="submit"
      class="btn btn-sm btn-outline-danger"
    >
      Set Prioritas Terpilih
    </button>
    <span class="form-hint m-0 w-100">
      Mengubah prioritas tiket aktif terpilih sekaligus —
      status final diserahkan/batal atau yang prioritasnya
      sudah sama dilewati, hasil dilaporkan per baris
      (maks 50 tiket).
    </span>
  </form>
  <form
    method="POST"
    action="{{ route('services.bulkTechnician') }}"
    id="bulkSetTechnicianForm"
    class="d-flex flex-wrap align-items-end gap-2 w-100"
    onsubmit="return confirmBulkSetTechnician(this, 'Set teknisi tiket aktif terpilih ke teknisi tujuan?')"
  >
    @csrf
    <select
      name="technician_id"
      class="form-select form-select-sm"
      style="max-width: 260px"
      required
      title="Pilih teknisi tujuan untuk tiket terpilih"
    >
      <option value="">-- Pilih Teknisi Tujuan --</option>
      @foreach(($technicians ?? collect()) as $tech)
        <option value="{{ $tech->id }}">{{ $tech->name }}</option>
      @endforeach
    </select>
    <button
      type="submit"
      class="btn btn-sm btn-outline-primary"
    >
      Set Teknisi Terpilih
    </button>
    <span class="form-hint m-0 w-100">
      Memindahkan tiket aktif terpilih ke teknisi tujuan
      sekaligus (rebalance beban) —
      status final diserahkan/batal atau yang teknisinya
      sudah sama dilewati, perubahan dicatat di log,
      hasil dilaporkan per baris (maks 50).
    </span>
  </form>
  <form
    method="POST"
    action="{{ route('services.bulkTransfer') }}"
    id="bulkTransferForm"
    class="d-flex flex-wrap align-items-end gap-2 w-100"
    onsubmit="return confirmBulkTransfer(this, 'Pindah tiket aktif terpilih ke toko tujuan?')"
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
    <span class="form-hint m-0 w-100">
      Hanya tiket aktif yang dipindah
      (cermin pindah satuan: update store_id + catat log,
      tanpa ubah status) —
      baris diserahkan/batal dilewati,
      hasil dilaporkan per baris (maks 50).
    </span>
  </form>
  <form
    method="POST"
    action="{{ route('services.bulkEstimasi') }}"
    id="bulkEstimasiForm"
    class="d-flex flex-wrap align-items-end gap-2 w-100"
    onsubmit="return confirmBulkEstimasi(this, 'Geser estimasi tiket aktif terpilih?')"
  >
    @csrf
    <select
      name="mode"
      id="bulkEstimasiMode"
      class="form-select form-select-sm w-auto"
      title="Mode geser estimasi"
    >
      <option value="shift">Geser ±N hari</option>
      <option value="set">Set tanggal</option>
    </select>
    <input
      type="number"
      name="shift_days"
      id="bulkEstimasiShift"
      class="form-control form-control-sm w-auto"
      style="max-width: 130px"
      value="1"
      min="-30"
      max="30"
      step="1"
      title="Jumlah hari geser: +N maju, -N mundur (maks ±30)"
    >
    <input
      type="date"
      name="set_date"
      id="bulkEstimasiDate"
      class="form-control form-control-sm w-auto"
      style="max-width: 180px; display: none"
      title="Tanggal estimasi tujuan (mode set tanggal)"
    >
    <button
      type="submit"
      class="btn btn-sm btn-outline-primary"
    >
      Geser Estimasi Terpilih
    </button>
    <span class="form-hint m-0 w-100">
      Menggeser estimated_done_at tiket aktif terpilih —
      basis tiap tiket (estimasi lama atau tanggal masuk bila kosong),
      perubahan dicatat di log per tiket,
      status final diserahkan/batal dilewati,
      hasil dilaporkan per baris (maks 50).
    </span>
  </form>
</div><div class="table-responsive"><table class="table card-table table-vcenter">
<thead>
  <tr>
    <th class="w-1"><input type="checkbox" class="form-check-input" id="checkAllTickets" title="Pilih semua di halaman ini"></th>
    <th>Tiket</th>
    <th>Pelanggan</th>
    <th>Device / IMEI</th>
    <th>Teknisi</th>
    <th>Estimasi</th>
    <th>Status</th>
    <th>Prioritas</th>
    <th class="text-end">Total</th>
    <th></th>
  </tr>
</thead>
<tbody>
@forelse($tickets as $t)
@php
  // Overdue konsisten report(): batal dikecualikan; selesai (diserahkan/siap_diambil)
  // pembandingnya taken_at ?? updated_at, tiket aktif pembandingnya now().
  // N hari = round(max(0, (end - estimated_done_at) / 86400), 1) sama persis report.
  $isBatal = $t->status === 'batal';
  $isSelesai = in_array($t->status, ['diserahkan', 'siap_diambil']);
  $isAktif = !in_array($t->status, ['diserahkan', 'batal']);
  $overdueEnd = $isSelesai
    ? ($t->taken_at ?? $t->updated_at)
    : now();
  $overdueHari = null;
  if ($t->estimated_done_at && !$isBatal && $overdueEnd && $overdueEnd->gt($t->estimated_done_at)) {
    $overdueHari = round(max(0, ($overdueEnd->getTimestamp() - $t->estimated_done_at->getTimestamp()) / 86400), 1);
  }
  $isOverdue = $overdueHari !== null;
  $isOverdueAktif = $isOverdue && $isAktif;
@endphp
<tr @if($t->priority === 'mendesak' || $isOverdueAktif) class="table-danger" @endif>
  <td>
    <input
      type="checkbox"
      class="form-check-input ticket-check"
      name="ticket_ids[]"
      value="{{ $t->id }}"
      data-status="{{ $t->status }}"
      title="Pilih tiket {{ $t->ticket_no }}"
    >
  </td>
  <td><a href="{{ route('services.show',$t) }}" class="fw-bold">{{ $t->ticket_no }}</a>@if($t->priority==='mendesak') <span class="badge bg-red text-white">MENDESAK</span>@endif<div class="text-secondary small">{{ $t->created_at->format('d/m/Y H:i') }} · {{ $t->priority }}</div></td>
  <td>{{ $t->customer->name ?? '-' }}<div class="text-secondary small">{{ $t->customer->phone ?? '' }}</div></td>
  <td>{{ $t->product_brand }} {{ $t->product_type }}<div class="text-secondary small">{{ $t->imei }} · {{ \Str::limit($t->complaint,40) }}</div></td>
  <td>{{ $t->technician->name ?? '-' }}</td>
  <td class="text-nowrap small">
    {{ $t->estimated_done_at ? $t->estimated_done_at->format('d/m/Y H:i') : '-' }}
    @if($isOverdue)
      <div>
        <span
          class="badge bg-red"
          title="Melewati estimasi {{ $t->estimated_done_at ? $t->estimated_done_at->format('d/m/Y H:i') : '-' }} (definisi sama dengan laporan)"
        >
          Terlambat {{ str_replace('.', ',', (string) $overdueHari) }} hari
        </span>
      </div>
    @endif
  </td>
  <td>
    <span class="badge {{ match($t->status){'diterima'=>'bg-blue-lt','diagnosa'=>'bg-cyan-lt','menunggu_persetujuan'=>'bg-yellow-lt','disetujui'=>'bg-teal-lt','dikerjakan'=>'bg-purple-lt','qc'=>'bg-orange-lt','siap_diambil'=>'bg-green-lt','diserahkan'=>'bg-dark-lt','batal'=>'bg-red-lt',default=>'bg-secondary-lt'} }}">{{ $t->status }}</span>
  </td>
  <td>
    @php
      $priorityLocked = in_array($t->status, ['diserahkan', 'batal']);
    @endphp
    <form
      method="POST"
      action="{{ route('services.priority', $t) }}"
      title="{{ $priorityLocked ? 'Tiket selesai/batal: prioritas dikunci' : 'Ubah prioritas tiket ' . $t->ticket_no }}"
    >
      @csrf
      <select
        name="priority"
        class="form-select form-select-sm"
        onchange="this.form.submit()"
        {{ $priorityLocked ? 'disabled' : '' }}
      >
        @foreach (['rendah', 'normal', 'mendesak'] as $p)
          <option
            value="{{ $p }}"
            {{ ($t->priority ?? 'normal') === $p ? 'selected' : '' }}
          >
            {{ $p }}
          </option>
        @endforeach
      </select>
    </form>
  </td>
  <td class="text-end">Rp {{ number_format($t->total_cost,0,',','.') }}</td>
  <td class="text-end text-nowrap">
    <form
      method="POST"
      action="{{ route('services.bulkPickupRemind') }}"
      class="d-inline"
      onsubmit="return confirm('Kirim pengingat WA ambil ke tiket {{ $t->ticket_no }}?')"
    >
      @csrf
      <input
        type="hidden"
        name="ticket_ids[]"
        value="{{ $t->id }}"
      >
      <button
        type="submit"
        class="btn btn-sm btn-outline-success"
        title="{{ $t->status === 'siap_diambil' ? 'Ingatkan ambil tiket ' . $t->ticket_no . ' via WA (reuse endpoint massal)' : 'Hanya siap_diambil yang bisa diingatkan ambil' }}"
        {{ $t->status === 'siap_diambil' ? '' : 'disabled' }}
      >
        <i class="ti ti-bell me-1"></i> ambil
      </button>
    </form>
    <form
      method="POST"
      action="{{ route('services.bulkTechnicianRemind') }}"
      class="d-inline"
      onsubmit="return confirm('Kirim pengingat WA ke teknisi {{ $t->technician->name ?? '-' }} untuk tiket {{ $t->ticket_no }}?')"
    >
      @csrf
      <input
        type="hidden"
        name="ticket_ids[]"
        value="{{ $t->id }}"
      >
      @php
        $techRemindable = !in_array($t->status, ['diserahkan', 'batal'])
          && $t->technician
          && trim((string) ($t->technician->phone ?? '')) !== '';
      @endphp
      <button
        type="submit"
        class="btn btn-sm btn-outline-warning"
        title="{{ $techRemindable ? 'Ingatkan teknisi ' . ($t->technician->name ?? '') . ' untuk tiket ' . $t->ticket_no . ' via WA (reuse endpoint massal)' : 'Butuh tiket aktif + teknisi penanggung jawab + nomor HP teknisi' }}"
        {{ $techRemindable ? '' : 'disabled' }}
      >
        <i class="ti ti-user me-1"></i>‍<i class="ti ti-tool me-1"></i> teknisi
      </button>
    </form>
    <a
      href="{{ route('services.show', $t) }}"
      class="btn btn-sm btn-outline-primary"
    >
      Detail
    </a>
    <a
      href="{{ route('services.create', ['copy_from' => $t->id]) }}"
      class="btn btn-sm btn-outline-secondary"
      title="Salin tiket {{ $t->ticket_no }} — buka form Terima HP terisi pelanggan & device (tanpa foto/status lama)"
    >
      Salin
    </a>
    <a
      href="{{ route('services.print', $t) }}"
      target="_blank"
      class="btn btn-sm btn-ghost-secondary"
    >
      Print
    </a>
  </td>
</tr>
@empty<tr><td colspan="10"><div class="empty py-3"><div class="empty-icon"><i class="ti ti-tool fs-1 text-secondary"></i></div><p class="empty-title">Belum ada tiket service</p><p class="empty-subtitle text-secondary">Tiket baru muncul setelah penerimaan HP di counter atau dari booking online.</p><div class="empty-action"><a href="{{ route('services.create') }}" class="btn btn-primary"><i class="ti ti-plus me-1"></i>Terima HP Baru</a></div></div></td></tr>@endforelse
</tbody></table></div>
@if(method_exists($tickets,'links'))<div class="card-footer">{{ $tickets->links() }}</div>@endif</div>
<script>
function collectCheckedTicketIds() {
    return Array.from(document.querySelectorAll('.ticket-check:checked')).map(
        function (cb) {
            return cb.value;
        }
    );
}
function confirmBulkPickup(form, message) {
    var ids = collectCheckedTicketIds();
    if (ids.length === 0) {
        alert('Pilih minimal 1 tiket dulu (centang baris tabel).');
        return false;
    }
    if (ids.length > 50) {
        alert('Maksimal 50 tiket per pengingat massal.');
        return false;
    }
    if (!confirm(message)) {
        return false;
    }
    ids.forEach(function (id) {
        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'ticket_ids[]';
        hidden.value = id;
        form.appendChild(hidden);
    });
    return true;
}
function confirmBulkTechnician(form, message) {
    var ids = collectCheckedTicketIds();
    if (ids.length === 0) {
        alert('Pilih minimal 1 tiket dulu (centang baris tabel).');
        return false;
    }
    if (ids.length > 50) {
        alert('Maksimal 50 tiket per pengingat teknisi.');
        return false;
    }
    if (!confirm(message)) {
        return false;
    }
    ids.forEach(function (id) {
        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'ticket_ids[]';
        hidden.value = id;
        form.appendChild(hidden);
    });
    return true;
}
function confirmBulkPriority(form, message) {
    var ids = collectCheckedTicketIds();
    if (ids.length === 0) {
        alert('Pilih minimal 1 tiket dulu (centang baris tabel).');
        return false;
    }
    if (ids.length > 50) {
        alert('Maksimal 50 tiket per set prioritas massal.');
        return false;
    }
    if (!confirm(message)) {
        return false;
    }
    ids.forEach(function (id) {
        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'ticket_ids[]';
        hidden.value = id;
        form.appendChild(hidden);
    });
    return true;
}
function confirmBulkSetTechnician(form, message) {
    var ids = collectCheckedTicketIds();
    if (ids.length === 0) {
        alert('Pilih minimal 1 tiket dulu (centang baris tabel).');
        return false;
    }
    if (ids.length > 50) {
        alert('Maksimal 50 tiket per set teknisi massal.');
        return false;
    }
    var dest = form.querySelector('select[name="technician_id"]');
    if (!dest || dest.value === '') {
        alert('Pilih teknisi tujuan dulu.');
        return false;
    }
    if (!confirm(message)) {
        return false;
    }
    ids.forEach(function (id) {
        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'ticket_ids[]';
        hidden.value = id;
        form.appendChild(hidden);
    });
    return true;
}
function confirmBulkEstimasi(form, message) {
    var ids = collectCheckedTicketIds();
    if (ids.length === 0) {
        alert('Pilih minimal 1 tiket dulu (centang baris tabel).');
        return false;
    }
    if (ids.length > 50) {
        alert('Maksimal 50 tiket per geser estimasi massal.');
        return false;
    }
    var modeEl = form.querySelector('select[name="mode"]');
    var mode = modeEl ? modeEl.value : 'shift';
    if (mode === 'shift') {
        var shiftEl = form.querySelector('input[name="shift_days"]');
        var shift = shiftEl ? parseInt(shiftEl.value, 10) : NaN;
        if (isNaN(shift) || shift === 0 || shift < -30 || shift > 30) {
            alert('Isi jumlah hari geser ±1..30 (0 tidak boleh, tanpa pecahan).');
            return false;
        }
    } else {
        var dateEl = form.querySelector('input[name="set_date"]');
        if (!dateEl || dateEl.value === '') {
            alert('Isi tanggal estimasi tujuan dulu.');
            return false;
        }
    }
    if (!confirm(message)) {
        return false;
    }
    ids.forEach(function (id) {
        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'ticket_ids[]';
        hidden.value = id;
        form.appendChild(hidden);
    });
    return true;
}
function confirmBulkTransfer(form, message) {
    var ids = collectCheckedTicketIds();
    if (ids.length === 0) {
        alert('Pilih minimal 1 tiket dulu (centang baris tabel).');
        return false;
    }
    if (ids.length > 50) {
        alert('Maksimal 50 tiket per pindah massal.');
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
        hidden.name = 'ticket_ids[]';
        hidden.value = id;
        form.appendChild(hidden);
    });
    return true;
}
document.getElementById('checkAllTickets')?.addEventListener('change', function () {
    document.querySelectorAll('.ticket-check').forEach(function (cb) {
        cb.checked = document.getElementById('checkAllTickets').checked;
    });
});
document.getElementById('bulkEstimasiMode')?.addEventListener('change', function () {
    var isShift = this.value === 'shift';
    var shiftEl = document.getElementById('bulkEstimasiShift');
    var dateEl = document.getElementById('bulkEstimasiDate');
    if (shiftEl) {
        shiftEl.style.display = isShift ? '' : 'none';
    }
    if (dateEl) {
        dateEl.style.display = isShift ? 'none' : '';
    }
});
document.getElementById('printTicketsBtn')?.addEventListener('click', function () {
    const ids = Array.from(document.querySelectorAll('.ticket-check:checked')).map(function (cb) {
        return cb.value;
    });
    if (!ids.length) {
        alert('Pilih minimal 1 tiket dulu (centang baris tabel).');
        return;
    }
    if (ids.length > 50) {
        alert('Maksimal 50 tiket per cetak massal.');
        return;
    }
    const q = ids.map(function (id) {
        return 'ids[]=' + encodeURIComponent(id);
    }).join('&');
    window.open("{{ route('services.bulkTickets') }}?" + q, '_blank');
});
</script>
@endsection
