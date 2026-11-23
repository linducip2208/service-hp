@extends('layouts.tabler')
@section('title','Terima HP Service')
@section('content')
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <h2 class="page-title">Terima HP Service Baru</h2>
            <div class="text-secondary">Tiket otomatis: SVC-YYYYMMDD-XXXX per toko.</div>
        </div>
        <div class="col-auto ms-auto">
            <a href="{{ route('services.index') }}" class="btn btn-ghost-secondary">Kembali</a>
        </div>
    </div>
</div>

<form method="POST" action="{{ route('services.store') }}" enctype="multipart/form-data" class="card mt-2">
    <div class="card-body">
        <div class="row g-3">
            @csrf

            @if(!empty($copySource))
                <div class="col-12">
                    <div class="alert alert-info mb-0">
                        <strong>Mode salin tiket:</strong>
                        data pelanggan & device diambil dari
                        <a href="{{ route('services.show', $copySource) }}">
                            {{ $copySource->ticket_no }}
                        </a>
                        ({{ $copySource->product_brand }} {{ $copySource->product_type }}).
                        Foto & status lama tidak ikut disalin — tiket baru selalu mulai dari
                        <code>diterima</code>.
                    </div>
                </div>
            @endif

            @if(!empty($imeiDuplicates) && $imeiDuplicates->count())
                <div class="col-12">
                    <div class="alert alert-warning mb-0">
                        <strong>Perhatian:</strong>
                        IMEI mirip <code>{{ $imeiQuery }}</code> sudah punya {{ $imeiDuplicates->count() }} tiket aktif (belum diserahkan/batal). Membuat tiket baru dengan IMEI yang sama akan ditolak.
                        <ul class="mb-0 mt-1">
                            @foreach($imeiDuplicates as $d)
                                <li>
                                    <a href="{{ route('services.show', $d) }}">{{ $d->ticket_no }}</a>
                                    — {{ $d->product_brand }} {{ $d->product_type }}
                                    — status: {{ $d->status }}
                                    — toko: {{ $d->store->name ?? ('#'.$d->store_id) }}
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif

            <div class="col-md-6">
                <label class="form-label d-flex justify-content-between align-items-center">
                    <span>Pelanggan Terdaftar (opsional)</span>
                    <button
                        type="button"
                        class="btn btn-sm btn-outline-primary py-0 px-2"
                        data-bs-toggle="modal"
                        data-bs-target="#modal-customer-service"
                        title="Tambah pelanggan baru tanpa keluar form Terima HP"
                    >
                        + Baru
                    </button>
                </label>
                <select name="customer_id" id="customer_id" class="form-select">
                    <option value="">-- Buat baru --</option>
                    @foreach($customers as $c)
                        @php
                            $tier = ($customerTiers[(int) $c->id] ?? 'Bronze');
                        @endphp
                        <option
                            value="{{ $c->id }}"
                            @selected((string) old('customer_id', $selectedCustomerId ?? '') === (string) $c->id)
                        >
                            {{ $c->name }} — {{ $c->phone }} [{{ $tier }}]
                        </option>
                    @endforeach
                </select>
                <div id="deviceHistory" class="mt-2 d-flex flex-wrap gap-1"></div>
                <small class="text-secondary" id="deviceHistoryHint">
                    Pilih pelanggan untuk melihat HP yang pernah diservis — klik chip untuk isi otomatis.
                </small>
            </div>

            <div class="col-md-3">
                <label class="form-label">Nama Pelanggan *</label>
                <input
                    name="customer_name"
                    class="form-control"
                    value="{{ old('customer_name') }}"
                    placeholder="Isi jika pelanggan baru"
                >
            </div>

            <div class="col-md-3">
                <label class="form-label">No. HP / WA *</label>
                <input
                    name="customer_phone"
                    class="form-control"
                    value="{{ old('customer_phone') }}"
                    placeholder="08xx"
                >
                <small class="text-secondary">Otomatis dinormalisasi 08… → 628… saat disimpan.</small>
            </div>

            <div class="col-md-3">
                <label class="form-label">Brand *</label>
                <input
                    name="product_brand"
                    id="product_brand"
                    class="form-control"
                    required
                    value="{{ old('product_brand', isset($copySource) && $copySource ? $copySource->product_brand : request('product_brand')) }}"
                    placeholder="Samsung / Xiaomi / iPhone"
                >
            </div>

            <div class="col-md-3">
                <label class="form-label">Tipe *</label>
                <input
                    name="product_type"
                    id="product_type"
                    class="form-control"
                    required
                    value="{{ old('product_type', isset($copySource) && $copySource ? $copySource->product_type : request('product_type')) }}"
                    placeholder="Galaxy A54 / Redmi 13..."
                >
            </div>

            <div class="col-md-3">
                <label class="form-label">IMEI</label>
                <input
                    name="imei"
                    id="imei"
                    class="form-control"
                    value="{{ old('imei', $imeiQuery ?? '') }}"
                >
            </div>

            <div class="col-md-3">
                <label
                    class="form-label"
                    for="priority"
                >
                    Prioritas
                </label>
                <select
                    name="priority"
                    id="priority"
                    class="form-select"
                >
                    <option
                        value="rendah"
                        @selected(old('priority', 'normal') === 'rendah')
                    >
                        rendah (+4 hari)
                    </option>
                    <option
                        value="normal"
                        @selected(old('priority', 'normal') === 'normal')
                    >
                        normal (+2 hari)
                    </option>
                    <option
                        value="mendesak"
                        @selected(old('priority', 'normal') === 'mendesak')
                    >
                        mendesak (+1 hari)
                    </option>
                </select>
                <small
                    class="text-secondary"
                    id="priorityEtaHint"
                >
                    Estimasi otomatis mengikuti prioritas.
                </small>
            </div>

            <div class="col-md-6">
                <label class="form-label">Keluhan *</label>
                <textarea name="complaint" class="form-control" rows="2" required>{{ old('complaint') }}</textarea>
            </div>

            <div class="col-md-6">
                <label class="form-label">Catatan Kondisi Fisik</label>
                <textarea name="condition_note" class="form-control" rows="2">{{ old('condition_note') }}</textarea>
            </div>

            <div class="col-md-4">
                <label class="form-label">Teknisi</label>
                <select name="technician_id" class="form-select">
                    <option value="">-- Belum ditentukan --</option>
                    @foreach($technicians as $t)
                        @php
                            $load = (int) (($workload[$t->id] ?? 0));
                        @endphp
                        <option
                            value="{{ $t->id }}"
                            @selected((string) old('technician_id', $suggestedTechnicianId ?? '') === (string) $t->id)
                        >
                            {{ $t->name }} — {{ $load }} aktif{{ isset($suggestedTechnicianId) && $suggestedTechnicianId == $t->id ? ' ★ disarankan' : '' }}
                        </option>
                    @endforeach
                </select>
                <small class="text-secondary">Angka = tiket aktif (belum diserahkan/batal). Preselect otomatis beban tersedikit.</small>
            </div>

            <div class="col-md-2">
                <label class="form-label">Garansi (hari)</label>
                <input
                    type="number"
                    name="warranty_days"
                    class="form-control"
                    value="7"
                    min="0"
                    max="365"
                >
            </div>

            <div class="col-md-3">
                <label
                    class="form-label"
                    for="estimated_done_at"
                >
                    Estimasi Selesai
                </label>
                <input
                    type="datetime-local"
                    name="estimated_done_at"
                    id="estimated_done_at"
                    class="form-control"
                    value="{{ old('estimated_done_at', now()->addDays(2)->format('Y-m-d\TH:i')) }}"
                >
                <small class="text-secondary">Otomatis: mendesak +1 hari, normal +2 hari, rendah +4 hari.</small>
            </div>

            <div class="col-md-3">
                <label class="form-label">Foto Kondisi (max 4, @2MB)</label>
                <input
                    type="file"
                    name="photos[]"
                    class="form-control"
                    multiple
                    accept="image/*"
                >
            </div>
        </div>
    </div>
    <div class="card-footer text-end">
        <button class="btn btn-primary">Simpan & Cetak Tanda Terima</button>
    </div>
</form>

{{-- Modal pelanggan baru inline (tanpa keluar form Terima HP): POST ke customers.store existing + redirect=service --}}
<div class="modal fade" id="modal-customer-service" tabindex="-1" aria-labelledby="modal-customer-service-label" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('customers.store') }}" class="modal-content">
            @csrf
            <input type="hidden" name="redirect" value="service">
            <div class="modal-header">
                <h5 class="modal-title" id="modal-customer-service-label">Pelanggan Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">
                        Nama <span class="text-danger">*</span>
                    </label>
                    <input
                        name="name"
                        class="form-control"
                        required
                        maxlength="255"
                        placeholder="cth: Budi Santoso"
                        autocomplete="off"
                    >
                </div>
                <div class="mb-1">
                    <label class="form-label">No. HP / WA</label>
                    <input
                        name="phone"
                        class="form-control"
                        maxlength="50"
                        placeholder="cth: 0812xxxxxxx"
                        autocomplete="off"
                    >
                </div>
                <div class="form-text">
                    Nomor otomatis dinormalisasi (08… → 628…) seperti di master pelanggan. Setelah simpan, pelanggan langsung terpilih di form Terima HP.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button class="btn btn-primary" type="submit">Simpan & Pilih</button>
            </div>
        </form>
    </div>
</div>

<script>
try {
  const DEVICE_HISTORY = @json($deviceHistory ?? []);
  const sel = document.getElementById('customer_id');
  const box = document.getElementById('deviceHistory');
  const hint = document.getElementById('deviceHistoryHint');
  function esc(s){ return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
  function renderHistory(){
    if (!box || !sel) return;
    const list = DEVICE_HISTORY[sel.value] || DEVICE_HISTORY[String(sel.value)] || [];
    box.innerHTML = '';
    if (!sel.value) { if (hint) hint.textContent = 'Pilih pelanggan untuk melihat HP yang pernah diservis — klik chip untuk isi otomatis.'; return; }
    if (!list.length) { box.innerHTML = '<span class="text-secondary small">Belum ada riwayat device pelanggan ini.</span>'; return; }
    // Dedup brand+type+imei, pertahankan status terbaru (list sudah terbaru dulu)
    const seen = new Set(); const uniq = [];
    for (const d of list) { const k = (d.brand+'|'+d.type+'|'+d.imei).toLowerCase(); if (seen.has(k)) continue; seen.add(k); uniq.push(d); if (uniq.length >= 10) break; }
    uniq.forEach((d, i) => {
      const b = document.createElement('button');
      b.type = 'button'; b.className = 'btn btn-sm btn-ghost-secondary';
      b.title = 'Tiket ' + (d.tiket||'-') + ' (' + (d.tgl||'-') + ') — status: ' + (d.status||'-') + ' — klik untuk isi form';
      b.innerHTML = esc(d.brand + ' ' + d.type) + (d.imei ? ' <span class="text-secondary">' + esc(d.imei) + '</span>' : '') + ' <span class="badge bg-secondary-lt ms-1">' + esc(d.status||'-') + '</span>';
      b.addEventListener('click', () => {
        const brand = document.getElementById('product_brand'), type = document.getElementById('product_type'), imei = document.getElementById('imei');
        if (brand) brand.value = d.brand || brand.value;
        if (type) type.value = d.type || type.value;
        if (imei && d.imei) imei.value = d.imei;
      });
      box.appendChild(b);
    });
    if (hint) hint.textContent = 'Klik chip device untuk isi otomatis Brand/Tipe/IMEI.';
  }
  if (sel) sel.addEventListener('change', renderHistory);
  renderHistory();
} catch (e) { console.warn('device history skip:', e); }
</script>

<script>
try {
  const prioritySel = document.getElementById('priority');
  const etaInput = document.getElementById('estimated_done_at');
  const etaHint = document.getElementById('priorityEtaHint');
  const ETA_DAYS = {
    mendesak: 1,
    normal: 2,
    rendah: 4,
  };
  function pad(n) {
    return String(n).padStart(2, '0');
  }
  function toLocalInput(d) {
    return d.getFullYear()
      + '-' + pad(d.getMonth() + 1)
      + '-' + pad(d.getDate())
      + 'T' + pad(d.getHours())
      + ':' + pad(d.getMinutes());
  }
  function refreshEta() {
    if (!prioritySel || !etaInput) return;
    const days = ETA_DAYS[prioritySel.value] ?? 2;
    const d = new Date();
    d.setDate(d.getDate() + days);
    etaInput.value = toLocalInput(d);
    if (etaHint) {
      etaHint.textContent = 'Estimasi otomatis: +' + days + ' hari (' + prioritySel.value + ').';
    }
  }
  if (prioritySel) {
    prioritySel.addEventListener('change', refreshEta);
    if (etaHint) {
      const days = ETA_DAYS[prioritySel.value] ?? 2;
      etaHint.textContent = 'Estimasi otomatis: +' + days + ' hari (' + prioritySel.value + ').';
    }
  }
} catch (e) { console.warn('priority eta skip:', e); }
</script>
@endsection
