@extends('layouts.tabler')
@section('title','Log Aktivitas (File)')
@section('content')
<div class="page-header d-print-none">
  <div class="row align-items-center">
    <div class="col">
      <h2 class="page-title"><i class="ti ti-receipt me-1"></i> Log Aktivitas</h2>
      <div class="text-secondary">Aksi tulis terautentikasi (POST/PUT/PATCH/DELETE) dari <code>storage/logs/activity-YYYY-MM-DD.log</code>. Maksimal 500 baris terakhir, terbaru di atas. Tanpa database.</div>
    </div>
    <div class="col-auto ms-auto">
      <a href="{{ route('dashboard') }}" class="btn btn-outline-secondary">← Dashboard</a>
    </div>
  </div>
</div>

<div class="card mt-2">
  <div class="card-header">
    <form method="GET" action="{{ route('activity.log') }}" class="row row-cols-auto g-2 align-items-center">
      <div class="col">
        <label for="date" class="col-form-label">Tanggal</label>
      </div>
      <div class="col">
        <input type="date" id="date" name="date" class="form-control" value="{{ $date }}" max="{{ now()->format('Y-m-d') }}">
      </div>
      <div class="col">
        <button type="submit" class="btn btn-primary">Tampilkan</button>
      </div>
      <div class="col text-secondary small">
        @if($exists)
          Menampilkan {{ $total }} baris terakhir untuk {{ $date }}.
        @else
          Belum ada file log untuk {{ $date }}.
        @endif
      </div>
    </form>
  </div>
  <div class="card-body p-0">
    @if($exists && count($lines) > 0)
    <div class="table-responsive">
      <table class="table table-sm table-striped table-hover mb-0 font-monospace" style="font-size:.78rem">
        <thead>
          <tr><th style="width:3rem">#</th><th>Baris log</th></tr>
        </thead>
        <tbody>
          @foreach($lines as $i => $line)
          <tr><td class="text-secondary">{{ $i + 1 }}</td><td class="text-break">{{ $line }}</td></tr>
          @endforeach
        </tbody>
      </table>
    </div>
    @else
    <div class="text-center text-secondary py-4">
      @if($exists)
        File log ada tetapi kosong untuk tanggal {{ $date }}.
      @else
        Belum ada aktivitas tercatat pada {{ $date }} (file <code>activity-{{ $date }}.log</code> belum terbentuk).
      @endif
    </div>
    @endif
  </div>
  <div class="card-footer text-secondary small">
    Format baris: <code>[YYYY-MM-DD HH:MM:SS] user:{nama}#{id} | role:{role} | toko:{id_toko} | {METHOD} {path} | ip:{ip}</code>
  </div>
</div>

<div class="card mt-3">
  <div class="card-header">
    <h3 class="card-title"><i class="ti ti-lock me-1"></i> Gagal Login (50 terakhir)</h3>
    <div class="card-subtitle text-secondary">Dari <code>storage/logs/auth-fail-{{ $date }}.log</code>. Email disamarkan parsial, tanpa password. Khusus owner/superadmin.</div>
  </div>
  <div class="card-body p-0">
    @if($failExists && count($failLines) > 0)
    <div class="table-responsive">
      <table class="table table-sm table-striped table-hover mb-0 font-monospace" style="font-size:.78rem">
        <thead>
          <tr><th style="width:3rem">#</th><th>Baris log</th></tr>
        </thead>
        <tbody>
          @foreach($failLines as $i => $line)
          <tr><td class="text-secondary">{{ $i + 1 }}</td><td class="text-break">{{ $line }}</td></tr>
          @endforeach
        </tbody>
      </table>
    </div>
    @else
    <div class="text-center text-secondary py-4">
      @if($failExists)
        File log gagal login ada tetapi kosong untuk tanggal {{ $date }}.
      @else
        Tidak ada kegagalan login tercatat pada {{ $date }} (file <code>auth-fail-{{ $date }}.log</code> belum terbentuk).
      @endif
    </div>
    @endif
  </div>
  <div class="card-footer text-secondary small">
    Format baris: <code>[YYYY-MM-DD HH:MM:SS] login-gagal | email:{samaran} | sebab:{kredensial|nonaktif} | ip:{ip}</code>
  </div>
</div>
@endsection
