@extends('layouts.tabler')
@section('title', 'Kanban Service')
@section('content')
<div class="page-header d-print-none"><div class="row align-items-center">
  <div class="col"><div class="page-pretitle">Service Center</div><h2 class="page-title">Kanban Tiket Aktif</h2>
  <div class="text-secondary">Geser tiket antar kolom sesuai alur. Aturan transisi, QC &amp; TTD tetap ditegakkan server.</div></div>
  <div class="col-auto d-flex gap-2 flex-wrap">
    <form method="GET" class="d-flex gap-2">
      <select name="technician_id" class="form-select" onchange="this.form.submit()" aria-label="Filter teknisi">
        <option value="">Semua teknisi</option>
        @foreach(($technicians ?? []) as $t)<option value="{{ $t->id }}" {{ (string)request('technician_id')===(string)$t->id?'selected':'' }}>{{ $t->name }}</option>@endforeach
      </select>
    </form>
    <a href="{{ route('services.index') }}" class="btn btn-outline-secondary"><i class="ti ti-list me-1"></i>Daftar</a>
    <a href="{{ route('services.create') }}" class="btn btn-primary"><i class="ti ti-plus me-1"></i>Terima HP Baru</a>
  </div>
</div></div>
@php $flowCls = ['siap_diambil' => 'green', 'qc' => 'yellow', 'dikerjakan' => 'blue']; @endphp
<div class="d-flex gap-3 overflow-auto pb-3 align-items-start">
@foreach($columns as $col)
  @php $cards = ($tickets[$col] ?? collect()); @endphp
  <div class="card flex-shrink-0" style="min-width:280px;max-width:280px">
    <div class="card-header bg-{{ $flowCls[$col] ?? 'secondary' }}-lt py-2">
      <h3 class="card-title">{{ $labels[$col] ?? $col }} <span class="badge bg-secondary ms-1">{{ $cards->count() }}</span></h3>
    </div>
    <div class="card-body p-2 d-flex flex-column gap-2" style="max-height:70vh;overflow-y:auto">
      @forelse($cards as $t)
        <div class="card {{ ($t->priority ?? '')==='mendesak' ? 'border-danger' : '' }}">
          <div class="card-body p-2">
            <div class="d-flex justify-content-between align-items-center">
              <span class="font-monospace small">{{ $t->ticket_no }}</span>
              @if(($t->priority ?? '')==='mendesak')<span class="badge bg-red-lt">mendesak</span>@endif
            </div>
            <div class="fw-bold">{{ trim(($t->product_brand ?? '').' '.($t->product_type ?? '')) ?: '—' }}</div>
            <div class="small text-secondary">{{ $t->customer->name ?? 'Umum' }} · {{ $t->technician->name ?? 'belum assign' }}</div>
            <div class="small text-secondary">{{ $t->created_at ? $t->created_at->diffForHumans() : '' }}</div>
            <div class="d-flex flex-wrap gap-1 mt-2">
              <a href="{{ route('services.show', $t) }}" class="btn btn-sm btn-outline-primary">Buka</a>
              @foreach(($nextMap[$t->status] ?? []) as $nx)
                @if($nx !== 'batal')
                <form method="POST" action="{{ route('services.status', $t) }}" class="d-inline">@csrf
                  <input type="hidden" name="to_status" value="{{ $nx }}">
                  <button class="btn btn-sm btn-ghost-success" title="Pindah ke {{ $nx }}">{{ $nx === 'siap_diambil' ? 'Siap!' : '→ ' . $nx }}</button>
                </form>
                @endif
              @endforeach
            </div>
          </div>
        </div>
      @empty
        <div class="text-center text-secondary small py-3">Kosong</div>
      @endforelse
    </div>
  </div>
@endforeach
</div>
@endsection
