@extends('layouts.tabler')
@section('title','Tiket '.$service->ticket_no)
@section('content')
<div class="page-header"><div class="row align-items-center">
<div class="col"><div class="page-pretitle">Service • Tiket</div><h2 class="page-title">{{ $service->ticket_no }}</h2><div class="text-secondary">{{ $service->product_brand }} {{ $service->product_type }} · {{ $service->customer->name ?? '' }} ({{ $service->customer->phone ?? '' }})</div></div>
<div class="col-auto ms-auto no-print"><a href="{{ route('services.print',$service) }}" target="_blank" class="btn btn-outline-primary"><i class="ti ti-printer me-1"></i>Tanda Terima</a> <button class="btn btn-outline-dark" onclick="printArea('label-area')"><i class="ti ti-tag me-1"></i>Label/Stiker</button> <button class="btn btn-outline-success" onclick="printArea('dp-area')"><i class="ti ti-receipt me-1"></i>Kwitansi DP</button> <a href="{{ route('services.edit',$service) }}" class="btn btn-ghost-secondary">Edit</a></div>
</div></div>

<div class="row row-cards mt-2">
<div class="col-lg-8">
  <div class="card"><div class="card-header"><h3 class="card-title">Detail Service</h3>
    <div class="ms-auto"><span class="badge {{ match($service->status){'diterima'=>'bg-blue-lt','diagnosa'=>'bg-cyan-lt','menunggu_persetujuan'=>'bg-yellow-lt','disetujui'=>'bg-teal-lt','dikerjakan'=>'bg-purple-lt','qc'=>'bg-orange-lt','siap_diambil'=>'bg-green-lt','diserahkan'=>'bg-dark-lt','batal'=>'bg-red-lt',default=>'bg-secondary-lt'} }}">{{ $service->status }}</span>
    <span class="badge bg-secondary-lt">{{ $service->priority }}</span>
    <span class="badge {{ $service->approval_status=='disetujui'?'bg-green-lt':($service->approval_status=='ditolak'?'bg-red-lt':'bg-yellow-lt') }}">{{ $service->approval_status }}</span></div></div>
  <div class="card-body"><div class="row">
    <div class="col-md-6"><p class="mb-1"><b>Device:</b> {{ $service->product_brand }} {{ $service->product_type }}</p><p class="mb-1"><b>IMEI:</b> {{ $service->imei ?? '-' }}</p><p class="mb-1"><b>Teknisi:</b> {{ $service->technician->name ?? '-' }}</p><p class="mb-1"><b>Garansi:</b> {{ $service->warranty_days }} hari</p><p class="mb-1"><b>Estimasi selesai:</b> {{ $service->estimated_done_at?->format('d/m/Y H:i') ?? '-' }}
    @if($service->estimated_done_at && now()->gt($service->estimated_done_at) && !in_array($service->status,['diserahkan','siap_diambil','batal'])) <span class="badge bg-red ms-1">Terlambat</span>@endif</p></div>
    <div class="col-md-6"><p class="mb-1"><b>Estimasi/Jasa:</b> Rp {{ number_format($service->estimate_cost,0,',','.') }}</p><p class="mb-1"><b>Sparepart:</b> Rp {{ number_format($service->spareparts->sum('subtotal'),0,',','.') }}</p><p class="mb-1"><b>Total:</b> Rp {{ number_format($service->total_cost,0,',','.') }}</p><p class="mb-1"><b>DP:</b> Rp {{ number_format($service->dp,0,',','.') }} · <b>Sisa:</b> <span class="text-danger fw-bold">Rp {{ number_format($sisa,0,',','.') }}</span></p></div>
    <div class="col-12 mt-2"><b>Keluhan:</b><p>{{ $service->complaint }}</p>@if($service->condition_note)<b>Kondisi:</b><p>{{ $service->condition_note }}</p>@endif @if($service->diagnosis)<b>Diagnosa:</b><p>{{ $service->diagnosis }}</p>@endif</div>
    @if($service->photos)
    @php
        $beforePhotos = collect((array) $service->photos)->reject(fn($p) => str_contains((string) $p, 'after'))->values();
        $afterPhotos = collect((array) $service->photos)->filter(fn($p) => str_contains((string) $p, 'after'))->values();
    @endphp
    <div class="col-12"><b>Foto Kondisi Awal:</b>
    @if($beforePhotos->isEmpty())
        <span class="text-secondary"> -</span>
    @else
        <div class="row g-2 mt-1">
        @foreach($beforePhotos as $p)
            <div class="col-3"><a href="{{ asset('storage/'.$p) }}" target="_blank"><img src="{{ asset('storage/'.$p) }}" class="rounded w-100" style="height:120px;object-fit:cover"></a></div>
        @endforeach
        </div>
    @endif
    </div>
    <div class="col-12 mt-2"><b>Foto Sesudah:</b>
    @if($afterPhotos->isEmpty())
        <span class="text-secondary"> belum ada</span>
    @else
        <div class="row g-2 mt-1">
        @foreach($afterPhotos as $p)
            <div class="col-3"><a href="{{ asset('storage/'.$p) }}" target="_blank"><img src="{{ asset('storage/'.$p) }}" class="rounded w-100" style="height:120px;object-fit:cover"></a></div>
        @endforeach
        </div>
    @endif
    </div>
    @endif
  </div></div></div>

  <div class="card mt-2">
    <div class="card-header">
      <h3 class="card-title">Sparepart Terpakai</h3>
      <span class="ms-auto text-secondary small">Harga riwayat tidak auto-ubah bila master berubah</span>
    </div>
    <div class="table-responsive">
      <table class="table card-table">
        <thead>
          <tr>
            <th>Produk</th>
            <th>Qty</th>
            <th class="text-end">Harga Pakai</th>
            <th class="text-end">Master</th>
            <th class="text-end">Selisih vs Master</th>
            <th class="text-end">Subtotal</th>
            @if(!in_array($service->status,['diserahkan','batal']))
              <th class="text-end">Aksi</th>
            @endif
          </tr>
        </thead>
        <tbody>
          @forelse($service->spareparts as $sp)
            @php
              $master = $sp->product ? (float) $sp->product->selling_price : null;
              $diff = $master !== null ? ((float) $sp->price - $master) : null;
            @endphp
            <tr>
              <td>{{ $sp->product->name ?? '#' }}</td>
              <td>{{ $sp->qty }}</td>
              <td class="text-end">{{ number_format($sp->price,0,',','.') }}</td>
              <td class="text-end text-secondary">
                {{ $master !== null ? number_format($master,0,',','.') : '-' }}
              </td>
              <td class="text-end">
                @if($diff === null)
                  <span class="text-secondary">-</span>
                @elseif(abs($diff) < 0.5)
                  <span class="badge bg-green-lt">sesuai master</span>
                @else
                  <span
                    class="badge bg-yellow-lt"
                    title="Harga master berubah / override manual — riwayat tidak auto-ubah"
                  >
                    {{ $diff > 0 ? '+' : '−' }}Rp {{ number_format(abs($diff),0,',','.') }}
                  </span>
                @endif
              </td>
              <td class="text-end">{{ number_format($sp->subtotal,0,',','.') }}</td>
              @if(!in_array($service->status,['diserahkan','batal']))
                <td class="text-end">
                  <form
                    method="POST"
                    action="{{ route('services.sparepart.destroy',$sp) }}"
                    onsubmit="return confirm('Lepas {{ $sp->product->name ?? 'sparepart' }} x{{ $sp->qty }}? Stok akan dikembalikan.')"
                    class="d-inline"
                  >
                    @csrf
                    @method('DELETE')
                    <button class="btn btn-sm btn-outline-danger">Lepas</button>
                  </form>
                </td>
              @endif
            </tr>
          @empty
            <tr>
              <td colspan="7" class="text-center text-secondary">Belum ada sparepart.</td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
    @if($service->spareparts->isNotEmpty())
      @php
        $driftCount = $service->spareparts->filter(function ($sp) {
            if (!$sp->product) return false;
            return abs((float) $sp->price - (float) $sp->product->selling_price) >= 0.5;
        })->count();
      @endphp
      @if($driftCount > 0)
        <div class="card-body py-2">
          <div class="alert alert-warning py-2 px-2 mb-0">
            {{ $driftCount }} baris harganya beda dari master saat ini (override manual / master berubah).
            Riwayat dibiarkan apa adanya — tidak auto-ubah.
          </div>
        </div>
      @endif
    @endif
    @if(!in_array($service->status,['diserahkan','batal']))
      <div class="card-body">
        <form method="POST" action="{{ route('services.sparepart',$service) }}" class="row g-2">
          @csrf
          <div class="col-md-5">
            <label class="form-label">Sparepart</label>
            <select name="product_id" id="sparepartSelect" class="form-select" required>
              <option value="">-- Pilih sparepart toko --</option>
              @foreach($sparepartProducts as $p)
                <option value="{{ $p->id }}" data-price="{{ (float) $p->selling_price }}">
                  {{ $p->name }} — Rp {{ number_format($p->selling_price,0,',','.') }}
                </option>
              @endforeach
            </select>
            <small class="text-secondary" id="masterHint">Pilih sparepart untuk melihat harga master.</small>
          </div>
          <div class="col-md-3">
            <label class="form-label">Harga pakai (Rp)</label>
            <input
              type="number"
              name="price"
              id="sparepartPrice"
              class="form-control"
              min="0"
              step="1"
              placeholder="default = master"
            >
            <small class="text-secondary">Default = harga jual master. Boleh override manual.</small>
          </div>
          <div class="col-md-2">
            <label class="form-label">Qty</label>
            <input type="number" name="qty" class="form-control" value="1" min="1" max="100">
          </div>
          <div class="col-md-2 d-grid">
            <label class="form-label">&nbsp;</label>
            <button class="btn btn-primary">+ Tambah</button>
          </div>
        </form>
      </div>
    @endif
  </div>

  <script>
  (function () {
    var sel = document.getElementById('sparepartSelect');
    var price = document.getElementById('sparepartPrice');
    var hint = document.getElementById('masterHint');
    if (!sel || !price) return;
    function fmt(n) {
      try {
        return new Intl.NumberFormat('id-ID').format(n);
      } catch (e) {
        return n;
      }
    }
    sel.addEventListener('change', function () {
      var opt = sel.options[sel.selectedIndex];
      var master = opt && opt.dataset ? parseFloat(opt.dataset.price || '') : NaN;
      if (!isNaN(master)) {
        price.placeholder = 'default = ' + fmt(master);
        price.value = String(Math.round(master));
        if (hint) hint.textContent = 'Harga master: Rp ' + fmt(Math.round(master)) + ' — terisi otomatis, boleh diubah manual.';
      } else {
        price.placeholder = 'default = master';
        if (hint) hint.textContent = 'Pilih sparepart untuk melihat harga master.';
      }
    });
  })();
  </script>

  <div class="card mt-2"><div class="card-header"><h3 class="card-title">Timeline</h3></div><div class="card-body"><ul class="steps steps-vertical">
  @forelse($service->logs->sortBy('created_at') as $l)<li class="step-item"><div class="h4 m-0">{{ $l->from_status ?? 'baru' }} → {{ $l->to_status }}</div><div class="text-secondary">{{ $l->created_at?->format('d/m/Y H:i') }} · {{ $l->user->name ?? '' }} · {{ trim(preg_replace('/\s*\[SIG:[^\]]+\]/', '', (string) ($l->note ?? ''))) }}</div>@if(preg_match('/\[SIG:([^\]]+)\]/', (string) ($l->note ?? ''), $sigm))<div class="mt-1"><a href="{{ asset('storage/'.$sigm[1]) }}" target="_blank"><img src="{{ asset('storage/'.$sigm[1]) }}" alt="TTD" style="max-height:64px;background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:2px"></a> <span class="badge bg-dark-lt">bertanda tangan</span></div>@endif</li>
  @empty<li class="text-secondary">Belum ada log.</li>@endforelse
  </ul></div></div>

  @if(!empty($handoverSignature))
  <div class="card mt-2"><div class="card-header"><h3 class="card-title">Tanda Tangan Serah Terima</h3><span class="ms-auto text-secondary small">{{ $handoverLog?->created_at?->format('d/m/Y H:i') }}</span></div>
  <div class="card-body"><a href="{{ asset('storage/'.$handoverSignature) }}" target="_blank"><img src="{{ asset('storage/'.$handoverSignature) }}" alt="Tanda tangan pelanggan" style="max-height:140px;background:#fff;border:1px solid #e5e7eb;border-radius:8px"></a>
  <div class="text-secondary small mt-1">Pelanggan: {{ $service->customer->name ?? '-' }} · Tiket {{ $service->ticket_no }}</div></div></div>
  @endif

  @if($service->status==='qc' || $service->status==='dikerjakan')
  <div class="card mt-2"><div class="card-header"><h3 class="card-title">QC Checklist — wajib lengkap sebelum Siap Diambil</h3></div>
  <div class="card-body"><form method="POST" action="{{ route('services.status',$service) }}" enctype="multipart/form-data">@csrf
    <input type="hidden" name="to_status" value="siap_diambil">
    <div class="row g-2">
    @foreach(($qcItems ?? ['Fungsi nyala','Sinyal','Kamera','Charging','Tombol','Speaker']) as $item)
      <div class="col-md-4"><label class="form-check"><input type="checkbox" name="qc[]" value="{{ $item }}" class="form-check-input" {{ in_array($item, $qcChecked ?? []) ? 'checked' : '' }}><span class="form-check-label">{{ $item }}</span></label></div>
    @endforeach
    </div>
    <label class="form-label mt-2">Catatan QC</label><input name="note" class="form-control" placeholder="cth: semua fungsi normal">
    <label class="form-label mt-2">Foto sesudah <span class="text-danger">*</span> <span class="text-secondary">(wajib min 1, maks 2, tiap max 2 MB — dilewati bila tiket sudah punya foto sesudah)</span></label><input type="file" name="after_photos[]" class="form-control" accept="image/*" multiple>
    <button class="btn btn-success w-100 mt-2" {{ $service->status!=='qc' ? 'disabled title=Selesaikan tahap dikerjakan dulu (→ qc)' : '' }}>QC Lolos → Siap Diambil</button>
    @if($service->status!=='qc')<small class="text-secondary">Tiket masih tahap {{ $service->status }} — ubah dulu ke qc via panel Update Status.</small>@endif
  </form></div></div>
  @endif

  <div class="card mt-2"><div class="card-header"><h3 class="card-title">Riwayat HP Ini @if($service->imei)<span class="text-secondary">({{ $service->imei }})</span>@endif</h3></div>
  <div class="card-body">
  @if(!$service->imei)<span class="text-secondary">IMEI kosong — isi IMEI untuk melihat riwayat device.</span>
  @elseif(($history ?? collect())->isEmpty())<span class="text-secondary">Belum ada tiket lain dengan IMEI ini.</span>
  @else<ul class="steps steps-vertical">
    @foreach($history as $h)<li class="step-item"><div class="h4 m-0"><a href="{{ route('services.show',$h) }}">{{ $h->ticket_no }}</a> <span class="badge bg-secondary-lt">{{ $h->status }}</span></div><div class="text-secondary">{{ $h->created_at->format('d/m/Y H:i') }} · {{ $h->technician->name ?? '-' }} · {{ \Str::limit($h->complaint,60) }}</div></li>@endforeach
  </ul>@endif
  </div></div>
</div>

<div class="col-lg-4 no-print">
  <div class="card"><div class="card-header"><h3 class="card-title">Update Status</h3></div><div class="card-body">
  @if(!empty($allowedNext))
  <form id="statusForm" method="POST" action="{{ route('services.status',$service) }}" onsubmit="return confirmStatus(event)">@csrf
    <label class="form-label">Ke status</label><select id="toStatus" name="to_status" class="form-select">@foreach($allowedNext as $n)<option value="{{ $n }}">{{ $n }}</option>@endforeach</select>
    <div id="sigHint" class="alert alert-warning py-2 px-2 mt-2 mb-0 d-none"><b>Serah terima wajib tanda tangan</b> — klik <i>Ambil Tanda Tangan</i> dulu, baru Simpan.</div>
    <input type="hidden" name="signature" id="sig-data" value="">
    <div id="sigPreviewWrap" class="mt-2 d-none"><label class="form-label mb-1">Pratinjau TTD</label><div><img id="sigPreview" src="" alt="TTD" style="max-height:90px;background:#fff;border:1px dashed #98a2b3;border-radius:6px"></div><button type="button" class="btn btn-sm btn-ghost-secondary mt-1" onclick="openSigModal()">Ulangi / Ubah</button></div>
    <div class="d-grid gap-2 mt-2"><button type="button" id="sigBtn" class="btn btn-outline-dark d-none" onclick="openSigModal()"><i class="ti ti-pen me-1"></i>Ambil Tanda Tangan</button></div>
    <div
      id="handoverWrap"
      class="mt-2 border rounded p-2 d-none"
    >
      <label class="form-label mb-1">
        Checklist serah terima <span class="text-danger">*</span>
      </label>
      <div class="text-secondary small mb-2">
        Wajib centang semua + tanda tangan sebelum tombol Diserahkan aktif.
      </div>
      @foreach(($handoverItems ?? ['HP sudah dites nyala', 'Nota/struk diserahkan', 'Garansi dijelaskan']) as $item)
        <label class="form-check">
          <input
            type="checkbox"
            name="handover[]"
            value="{{ $item }}"
            class="form-check-input handover-check"
            {{ in_array($item, $handoverChecked ?? []) ? 'checked' : '' }}
          >
          <span class="form-check-label">{{ $item }}</span>
        </label>
      @endforeach
      <div id="handoverHint" class="text-danger small mt-1 d-none">
        Centang ketiga checklist di atas dulu.
      </div>
    </div>
    <label class="form-label mt-2">Teknisi</label><select name="technician_id" class="form-select"><option value="">-- tetap --</option>@foreach($technicians as $t)<option value="{{ $t->id }}" {{ $service->technician_id==$t->id?'selected':'' }}>{{ $t->name }}</option>@endforeach</select>
    <label class="form-label mt-2">Diagnosa (saat diagnosa)</label><textarea name="diagnosis" class="form-control" rows="2">{{ $service->diagnosis }}</textarea>
    <label class="form-label mt-2">Estimasi/Jasa Rp</label><input type="number" name="estimate_cost" class="form-control" value="{{ $service->estimate_cost }}" min="0">
    <label class="form-label mt-2">Persetujuan</label><select name="approval_status" class="form-select"><option value="">-- otomatis --</option><option value="menunggu">menunggu</option><option value="disetujui">disetujui</option><option value="ditolak">ditolak</option></select>
    <label class="form-label mt-2">DP / Bayar (Rp)</label><input type="number" name="payment" class="form-control" value="0" min="0">
    <label class="form-label mt-2">Estimasi Selesai</label><input type="datetime-local" name="estimated_done_at" class="form-control" value="{{ $service->estimated_done_at?->format('Y-m-d\TH:i') }}">
    <label class="form-label mt-2">Catatan</label><input name="note" class="form-control">
    <label class="form-check mt-2"><input type="checkbox" name="notify_tech" value="1" class="form-check-input"><span class="form-check-label">Kirim juga ringkasan WA ke teknisi <span class="text-secondary">({{ $service->technician->phone ?? 'no. HP teknisi belum ada' }})</span></span></label>
    <button id="statusSubmit" class="btn btn-primary w-100 mt-3">Simpan Status</button>
  </form>
  @else<span class="text-secondary">Tiket sudah final ({{ $service->status }}).</span>@endif
  </div></div>

  @if(!in_array($service->status, ['diserahkan', 'batal']))
    <div class="card mt-2">
      <div class="card-header">
        <h3 class="card-title">Pindah Toko</h3>
      </div>
      <div class="card-body">
        <form
          method="POST"
          action="{{ route('services.transfer', $service) }}"
          onsubmit="return confirm('Pindah tiket {{ $service->ticket_no }} ke toko lain? Stok sparepart terpakai & kas/DP tetap tercatat di toko asal.')"
        >
          @csrf
          <label class="form-label">
            Toko tujuan
          </label>
          <select
            name="to_store_id"
            class="form-select"
            required
          >
            <option value="">
              -- Pilih toko --
            </option>
            @foreach(($stores ?? collect()) as $st)
              @if((int) $st->id !== (int) $service->store_id)
                <option value="{{ $st->id }}">
                  {{ $st->name }}
                </option>
              @endif
            @endforeach
          </select>
          <label class="form-label mt-2">
            Catatan pindah (opsional)
          </label>
          <input
            name="note"
            class="form-control"
            maxlength="500"
            placeholder="cth: lanjut pengerjaan di cabang X"
          >
          <button class="btn btn-outline-primary w-100 mt-3">
            Pindah Tiket
          </button>
        </form>
        <small class="text-secondary d-block mt-2">
          Status tidak berubah, hanya kepemilikan toko + log.
          Sparepart terpakai & kas/DP tetap di toko asal.
        </small>
      </div>
    </div>
  @endif

  <div class="card mt-2">
    <div class="card-header">
      <h3 class="card-title">Persetujuan WA</h3>
    </div><div class="card-body d-grid gap-2">
    <a class="btn btn-success" target="_blank" href="https://wa.me/{{ $waPhone }}?text={{ $waApproveText }}"><i class="ti ti-brand-whatsapp me-1"></i>Kirim Estimasi via WA</a>
    <form method="POST" action="{{ route('services.status',$service) }}" class="d-grid gap-2">@csrf
      <input type="hidden" name="approval_status" value="disetujui">
      @if($service->status==='menunggu_persetujuan')<button name="to_status" value="disetujui" class="btn btn-outline-success">Tandai Disetujui Pelanggan</button>@endif
    </form>
    <small class="text-secondary">Link wa.me otomatis memakai nomor {{ $waPhone }}.</small>
  </div></div>

  <div class="card mt-2"><div class="card-header"><h3 class="card-title">Komisi</h3></div><div class="card-body">
  @forelse($service->commissions as $c)<div class="d-flex justify-content-between"><span>{{ $c->technician->name ?? '' }}</span><span class="badge {{ $c->status=='dibayar'?'bg-green-lt':'bg-yellow-lt' }}">{{ $c->status }} · Rp {{ number_format($c->amount,0,',','.') }}</span></div>
  @empty<span class="text-secondary">Komisi dibuat otomatis saat serah terima.</span>@endforelse
  </div></div>

  <form method="POST" action="{{ route('services.destroy',$service) }}" onsubmit="return confirm('Hapus tiket?')" class="mt-2">@csrf @method('DELETE')<button class="btn btn-outline-danger w-100">Hapus Tiket</button></form>
</div>
</div>

{{-- MODAL TTD SERAH TERIMA (canvas JS murni, tanpa lib baru) --}}
<div class="modal modal-blur fade" id="sigModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-sm modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Tanda tangan pelanggan</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div>
    <div class="modal-body">
      <div class="alert alert-warning py-2 px-2 mb-2">
        <b>Sebelum serah terima:</b>
        pastikan HP sudah dites nyala, nota/struk diserahkan, dan garansi dijelaskan
        (centang ketiganya di panel Update Status).
      </div>
      <canvas id="sigPad" width="440" height="220" style="width:100%;height:220px;background:#fff;border:1px solid #d1d5db;border-radius:8px;touch-action:none;cursor:crosshair"></canvas>
      <div class="text-secondary small mt-1">Gambar dengan mouse / jari. TTD wajib sebelum serah terima.</div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-ghost-secondary" onclick="clearSig()">Hapus</button>
      <button type="button" class="btn btn-primary" onclick="saveSig()">Pakai Tanda Tangan</button>
    </div>
  </div></div>
</div>

<script>
(function(){
  var canvas = document.getElementById('sigPad');
  var sel = document.getElementById('toStatus');
  var sigBtn = document.getElementById('sigBtn');
  var sigHint = document.getElementById('sigHint');
  var sigInput = document.getElementById('sig-data');
  var prevWrap = document.getElementById('sigPreviewWrap');
  var prevImg = document.getElementById('sigPreview');
  var drawing = false, hasDraw = false, ctx = null, last = null;
  function pos(e){
    var r = canvas.getBoundingClientRect();
    var cx = (e.touches && e.touches[0]) ? e.touches[0].clientX : e.clientX;
    var cy = (e.touches && e.touches[0]) ? e.touches[0].clientY : e.clientY;
    return { x: (cx - r.left) * (canvas.width / r.width), y: (cy - r.top) * (canvas.height / r.height) };
  }
  function setup(){
    if(!canvas || ctx) return;
    ctx = canvas.getContext('2d');
    ctx.lineWidth = 2.5; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#111';
  }
  function start(e){ setup(); drawing = true; hasDraw = true; last = pos(e); if(e.cancelable) e.preventDefault(); }
  function move(e){ if(!drawing) return; var p = pos(e); ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke(); last = p; if(e.cancelable) e.preventDefault(); }
  function end(){ drawing = false; }
  if(canvas){
    canvas.addEventListener('mousedown', start);
    canvas.addEventListener('mousemove', move);
    window.addEventListener('mouseup', end);
    canvas.addEventListener('touchstart', start, {passive:false});
    canvas.addEventListener('touchmove', move, {passive:false});
    canvas.addEventListener('touchend', end);
  }
  window.clearSig = function(){
    setup(); ctx.clearRect(0,0,canvas.width,canvas.height); hasDraw = false;
    if(sigInput) sigInput.value = '';
    if(prevWrap) prevWrap.classList.add('d-none');
    if(typeof syncSigUI === 'function') syncSigUI();
  };
  window.saveSig = function(){
    if(!hasDraw){ alert('Coret dulu tanda tangannya.'); return; }
    var url = canvas.toDataURL('image/png');
    if(sigInput) sigInput.value = url;
    if(prevImg) prevImg.src = url;
    if(prevWrap) prevWrap.classList.remove('d-none');
    closeSigModal();
    if(typeof syncSigUI === 'function') syncSigUI();
  };
  window.openSigModal = function(){
    setup();
    var m = document.getElementById('sigModal');
    try { var inst = bootstrap.Modal.getOrCreateInstance(m); inst.show(); }
    catch(e){ m.classList.add('show'); m.style.display = 'block'; }
  };
  window.closeSigModal = function(){
    var m = document.getElementById('sigModal');
    try { var inst = bootstrap.Modal.getInstance(m); if(inst) inst.hide(); else { m.classList.remove('show'); m.style.display = 'none'; } }
    catch(e){ m.classList.remove('show'); m.style.display = 'none'; }
  };
  function syncSigUI(){
    var need = sel && sel.value === 'diserahkan';
    var wrap = document.getElementById('handoverWrap');
    var hint = document.getElementById('handoverHint');
    var submit = document.getElementById('statusSubmit');
    var boxes = document.querySelectorAll('.handover-check');
    if(sigBtn) sigBtn.classList.toggle('d-none', !need);
    if(wrap) wrap.classList.toggle('d-none', !need);
    if(!need){
      if(hint) hint.classList.add('d-none');
      if(submit) submit.disabled = false;
      if(sigHint) sigHint.classList.add('d-none');
      return;
    }
    var checked = 0;
    boxes.forEach(function(b){ if(b.checked) checked++; });
    var allChecked = boxes.length > 0 && checked === boxes.length;
    var hasSig = !!(sigInput && sigInput.value);
    if(hint) hint.classList.toggle('d-none', allChecked);
    if(sigHint) sigHint.classList.toggle('d-none', hasSig);
    if(submit) submit.disabled = !(allChecked && hasSig);
  }
  if(sel) sel.addEventListener('change', syncSigUI);
  document.querySelectorAll('.handover-check').forEach(function(b){
    b.addEventListener('change', syncSigUI);
  });
  syncSigUI();
  window.confirmStatus = function(e){
    if(sel && sel.value === 'diserahkan'){
      var boxes = document.querySelectorAll('.handover-check');
      var checked = 0;
      boxes.forEach(function(b){ if(b.checked) checked++; });
      if(boxes.length > 0 && checked !== boxes.length){
        e.preventDefault();
        syncSigUI();
        alert('Lengkapi checklist serah terima dulu (3 item wajib).');
        return false;
      }
      if(!sigInput || !sigInput.value){
        e.preventDefault();
        syncSigUI();
        openSigModal();
        return false;
      }
    }
    return confirm('Ubah status tiket ini?');
  };
})();
</script>

{{-- LABEL / STIKER SERVICE (browser print) --}}
<div id="label-area" class="d-none">
  <div style="width:80mm;font-family:monospace;font-size:13px;color:#000">
    <div style="text-align:center;font-weight:bold;font-size:15px">{{ $service->ticket_no }}</div>
    <div style="text-align:center">{{ $service->customer->name ?? '' }}</div>
    <div>Device: {{ $service->product_brand }} {{ $service->product_type }}</div>
    <div>IMEI: {{ $service->imei ?? '-' }}</div>
    <div>Masuk: {{ $service->created_at->format('d/m/Y') }} · Est: {{ $service->estimated_done_at?->format('d/m/Y') ?? '-' }}</div>
    <div style="text-align:center;font-size:28px;letter-spacing:4px">*{{ $service->ticket_no }}*</div>
  </div>
</div>

{{-- KWITANSI DP (browser print struk kecil) --}}
<div id="dp-area" class="d-none">
  <div style="width:80mm;font-family:monospace;font-size:13px;color:#000">
    <div style="text-align:center;font-weight:bold">KWITANSI DP SERVICE</div>
    <div style="text-align:center">--------------------------------</div>
    <div>Tiket&nbsp;&nbsp;&nbsp;: {{ $service->ticket_no }}</div>
    <div>Tanggal : {{ now()->format('d/m/Y H:i') }}</div>
    <div>Pelanggan: {{ $service->customer->name ?? '' }} ({{ $service->customer->phone ?? '' }})</div>
    <div>Device&nbsp;&nbsp;: {{ $service->product_brand }} {{ $service->product_type }}</div>
    <div style="text-align:center">--------------------------------</div>
    <div>Total&nbsp;&nbsp;&nbsp;: Rp {{ number_format($service->total_cost,0,',','.') }}</div>
    <div>DP masuk: Rp {{ number_format($service->dp,0,',','.') }}</div>
    <div>Sisa&nbsp;&nbsp;&nbsp;&nbsp;: Rp {{ number_format($sisa,0,',','.') }}</div>
    <div style="text-align:center">--------------------------------</div>
    <div style="display:flex;justify-content:space-between;margin-top:24px"><span>Penerima,</span><span>Penyetor,</span></div>
    <div style="display:flex;justify-content:space-between;margin-top:48px"><span>(..............)</span><span>(..............)</span></div>
  </div>
</div>

<script src="/vendor/jsbarcode/JsBarcode.all.min.js"></script>
<script>
function printArea(id){
  var el = document.getElementById(id);
  if(!el){return;}
  var w = window.open('', '_blank', 'width=320,height=600');
  w.document.write('<html><head><title>Cetak</title><style>@page{margin:3mm}body{margin:0}</style></head><body>' + el.innerHTML + '</body></html>');
  w.document.close();
  w.focus();
  setTimeout(function(){ w.print(); }, 300);
}
</script>
@endsection
