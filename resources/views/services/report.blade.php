@extends('layouts.tabler')
@section('title','Laporan Service')
@section('content')
<div class="page-header d-print-none"><div class="row align-items-center">
  <div class="col"><h2 class="page-title">Laporan Service per Periode</h2><div class="text-secondary">Periode {{ $from }} s/d {{ $to }} · {{ $jumlah }} tiket</div></div>
  <div class="col-auto ms-auto d-flex gap-2">
    <button class="btn btn-outline-secondary" onclick="window.print()"><i class="ti ti-printer me-1"></i> Cetak</button>
    <a href="{{ route('services.report', array_merge(request()->query(), ['export' => 'csv'])) }}" class="btn btn-outline-success"><i class="ti ti-download me-1"></i> Export CSV</a>
    <a href="{{ route('services.index') }}" class="btn btn-outline-primary">← Tiket</a>
  </div>
</div></div>

<div class="card mb-3 d-print-none">
    <div class="card-body">
        <form
            method="GET"
            id="serviceReportForm"
            class="row g-2"
        >
  <div class="col-md-2"><label class="form-label">Dari</label><input type="date" name="from" value="{{ $from }}" class="form-control"></div>
  <div class="col-md-2"><label class="form-label">Sampai</label><input type="date" name="to" value="{{ $to }}" class="form-control"></div>
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
  <div class="col-md-3"><label class="form-label">Teknisi</label><select name="technician_id" class="form-select"><option value="">-- Semua --</option>@foreach($technicians as $t)<option value="{{ $t->id }}" {{ (string)request('technician_id')===(string)$t->id?'selected':'' }}>{{ $t->name }}</option>@endforeach</select></div>
  <div class="col-md-3"><label class="form-label">Status</label><select name="status" class="form-select"><option value="">-- Semua --</option>@foreach($statuses as $s)<option value="{{ $s }}" {{ request('status')===$s?'selected':'' }}>{{ $s }}</option>@endforeach</select></div>
  <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100">Tampilkan</button></div>
</form></div></div>

<div class="print-area">
<div class="row row-cards mb-3">
  <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="text-secondary">Jumlah Tiket</div><div class="h2">{{ $jumlah }}</div></div></div></div>
  <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="text-secondary">Omzet Jasa (estimasi)</div><div class="h2">Rp {{ number_format($omzetJasa,0,',','.') }}</div></div></div></div>
  <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="text-secondary">Omzet Sparepart</div><div class="h2">Rp {{ number_format($omzetSparepart,0,',','.') }}</div></div></div></div>
  <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="text-secondary">Rata-rata Pengerjaan</div><div class="h2">{{ $avgHari !== null ? $avgHari.' hari' : '-' }}</div><div class="text-secondary small">Total omzet: Rp {{ number_format($omzetTotal,0,',','.') }}</div></div></div></div>
  <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="text-secondary">Rata-rata Waktu Tunggu</div><div class="h2">{{ $avgTungguJam !== null ? number_format($avgTungguJam,1,',','.').' jam' : '-' }}</div><div class="text-secondary small">Diterima → dikerjakan · {{ $tungguCount }} tiket</div></div></div></div>
  <div class="col-sm-6 col-lg-3">
    <div class="card">
      <div class="card-body">
        <div class="text-secondary">Rata-rata Selisih (Aktual − Estimasi)</div>
        <div class="h2 {{ $avgSelisih !== null && $avgSelisih > 0 ? 'text-danger' : 'text-success' }}">
          {{ $avgSelisih !== null ? 'Rp ' . number_format($avgSelisih, 0, ',', '.') : '-' }}
        </div>
        <div class="text-secondary small">Positif = over budget · Negatif = hemat</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="card">
      <div class="card-body">
        <div class="text-secondary">Rata-rata per Tiket</div>
        <div class="h2">
          {{ $avgTiket !== null ? 'Rp ' . number_format($avgTiket, 0, ',', '.') : '-' }}
        </div>
        <div class="text-secondary small">
          Total Rp {{ number_format($omzetTotal, 0, ',', '.') }} · {{ $jumlah }} tiket
        </div>
      </div>
    </div>
  </div>
</div>

<div class="row row-cards mb-3">
  <div class="col-lg-6"><div class="card"><div class="card-header"><h3 class="card-title">Per Status</h3></div>
    <div class="table-responsive"><table class="table card-table"><thead><tr><th>Status</th><th class="text-end">Tiket</th></tr></thead><tbody>
    @forelse($perStatus as $st => $jml)<tr><td>{{ $st }}</td><td class="text-end">{{ $jml }}</td></tr>@empty<tr><td colspan="2" class="text-center text-secondary">Belum ada data.</td></tr>@endforelse
    </tbody></table></div></div></div>
  <div class="col-lg-6"><div class="card"><div class="card-header"><h3 class="card-title">Per Teknisi</h3></div>
    <div class="table-responsive"><table class="table card-table"><thead><tr>
      <th>Teknisi</th>
      <th class="text-end">Tiket</th>
      <th class="text-end">Selesai</th>
      <th class="text-end">Avg (hari)</th>
      <th class="text-end">Tunggu (jam)</th>
      <th class="text-end">Jasa</th>
      <th class="text-end">Total</th>
      <th class="text-end">Avg/Tiket</th>
      <th class="text-end">Avg Selisih</th>
    </tr></thead><tbody>
    @forelse($perTeknisi as $nama => $r)
      <tr>
        <td>{{ $nama }}</td>
        <td class="text-end">{{ $r['tiket'] }}</td>
        <td class="text-end">{{ $r['selesai'] ?? 0 }}</td>
        <td class="text-end">{{ ($r['avg_hari'] ?? null) !== null ? $r['avg_hari'].' hari' : '-' }}</td>
        <td class="text-end" title="{{ $r['tunggu_n'] ?? 0 }} tiket">{{ ($r['avg_tunggu_jam'] ?? null) !== null ? number_format($r['avg_tunggu_jam'],1,',','.') . ' jam' : '-' }}</td>
        <td class="text-end">{{ number_format($r['jasa'],0,',','.') }}</td>
        <td class="text-end">{{ number_format($r['total'],0,',','.') }}</td>
        <td class="text-end">
          {{ ($r['avg_total'] ?? null) !== null ? number_format($r['avg_total'], 0, ',', '.') : '-' }}
        </td>
        <td class="text-end">
          @if(($r['avg_selisih'] ?? null) !== null)
            <span class="{{ $r['avg_selisih'] > 0 ? 'text-danger' : 'text-success' }}">{{ ($r['avg_selisih'] > 0 ? '+' : '') . number_format($r['avg_selisih'],0,',','.') }}</span>
            @if($r['avg_selisih'] > 0)
              <span class="badge bg-red-lt ms-1">Over</span>
            @elseif($r['avg_selisih'] < 0)
              <span class="badge bg-green-lt ms-1">Hemat</span>
            @else
              <span class="badge bg-secondary-lt ms-1">Pas</span>
            @endif
          @else
            -
          @endif
        </td>
      </tr>
    @empty
      <tr><td colspan="9" class="text-center text-secondary">Belum ada data.</td></tr>
    @endforelse
    </tbody></table></div></div></div>
</div>

<div class="row row-cards mb-3">
  <div class="col-lg-6"><div class="card"><div class="card-header"><h3 class="card-title">Distribusi Waktu Pengerjaan <span class="text-secondary fw-normal">({{ $done->count() }} tiket selesai)</span></h3></div>
    <div class="table-responsive"><table class="table card-table"><thead><tr><th>Bucket</th><th class="text-end">Tiket</th><th class="text-end">%</th></tr></thead><tbody>
    @php $totDone = max(1, $done->count()); @endphp
    <tr><td><span class="badge bg-green-lt">&lt; 1 hari</span></td><td class="text-end">{{ $buckets['lt1'] }}</td><td class="text-end">{{ round($buckets['lt1'] * 100 / $totDone, 1) }}%</td></tr>
    <tr><td><span class="badge bg-yellow-lt">1–3 hari</span></td><td class="text-end">{{ $buckets['d1_3'] }}</td><td class="text-end">{{ round($buckets['d1_3'] * 100 / $totDone, 1) }}%</td></tr>
    <tr><td><span class="badge bg-red-lt">&gt; 3 hari</span></td><td class="text-end">{{ $buckets['gt3'] }}</td><td class="text-end">{{ round($buckets['gt3'] * 100 / $totDone, 1) }}%</td></tr>
    </tbody></table></div>
    @if($done->count())<div class="card-footer"><div class="progress"><div class="progress-bar bg-green" style="width: {{ round($buckets['lt1'] * 100 / $totDone, 1) }}%"></div><div class="progress-bar bg-yellow" style="width: {{ round($buckets['d1_3'] * 100 / $totDone, 1) }}%"></div><div class="progress-bar bg-red" style="width: {{ round($buckets['gt3'] * 100 / $totDone, 1) }}%"></div></div></div>@endif
  </div></div>
  <div class="col-lg-6"><div class="card"><div class="card-header"><h3 class="card-title">Bucket per Teknisi <span class="text-secondary fw-normal">(tiket selesai)</span></h3></div>
    <div class="table-responsive"><table class="table card-table"><thead><tr><th>Teknisi</th><th class="text-end">&lt;1 hari</th><th class="text-end">1–3 hari</th><th class="text-end">&gt;3 hari</th></tr></thead><tbody>
    @php $adaBucket = false; @endphp
    @foreach($perTeknisi as $nama => $r)
      @if(($r['selesai'] ?? 0) > 0)
        @php $adaBucket = true; @endphp
        <tr><td>{{ $nama }}</td><td class="text-end">{{ $r['lt1'] ?? 0 }}</td><td class="text-end">{{ $r['d1_3'] ?? 0 }}</td><td class="text-end">{{ $r['gt3'] ?? 0 }}</td></tr>
      @endif
    @endforeach
    @if(!$adaBucket)<tr><td colspan="4" class="text-center text-secondary">Belum ada data.</td></tr>@endif
    </tbody></table></div></div></div>
</div>

<div class="card mb-3"><div class="card-header"><h3 class="card-title">Tiket Melewati Estimasi <span class="badge bg-red-lt ms-1">{{ $overdue->count() }}</span></h3></div>
<div class="table-responsive"><table class="table card-table table-vcenter"><thead><tr><th>Tiket</th><th>Teknisi</th><th>Status</th><th>Estimasi</th><th class="text-end">Terlambat</th></tr></thead><tbody>
@forelse($overdue as $t)<tr>
  <td><a href="{{ route('services.show',$t) }}">{{ $t->ticket_no }}</a></td><td>{{ $t->technician->name ?? '-' }}</td><td>{{ $t->status }}</td>
  <td class="small">{{ $t->estimated_done_at?->format('d/m/Y H:i') ?? '-' }}</td>
  <td class="text-end text-danger">{{ $t->sla_lambat_hari }} hari</td>
</tr>@empty<tr><td colspan="5" class="text-center text-secondary">Tidak ada tiket melewati estimasi. </td></tr>@endforelse
</tbody></table></div></div>

<div class="card">
  <div class="card-header">
    <h3 class="card-title">Rincian Tiket ({{ $tickets->count() }})</h3>
  </div>
  <div class="table-responsive">
    <table class="table card-table table-vcenter">
      <thead>
        <tr>
          <th>ID</th>
          <th>Tiket</th>
          <th>Tanggal</th>
          <th>Teknisi</th>
          <th>Status</th>
          <th class="text-end">Jasa</th>
          <th class="text-end">Sparepart</th>
          <th class="text-end">Total</th>
          <th class="text-end">Selisih</th>
        </tr>
      </thead>
      <tbody>
        @forelse($tickets as $t)
          @php
            $selisih = (float) ($t->total_cost ?? 0) - (float) ($t->estimate_cost ?? 0);
          @endphp
          <tr>
            <td>{{ $t->id }}</td>
            <td>
              <a href="{{ route('services.show', $t) }}">{{ $t->ticket_no }}</a>
            </td>
            <td class="small">{{ $t->created_at?->format('d/m/Y H:i') }}</td>
            <td>{{ $t->technician->name ?? '-' }}</td>
            <td>{{ $t->status }}</td>
            <td class="text-end">{{ number_format($t->estimate_cost, 0, ',', '.') }}</td>
            <td class="text-end">{{ number_format($t->spareparts->sum('subtotal'), 0, ',', '.') }}</td>
            <td class="text-end">{{ number_format($t->total_cost, 0, ',', '.') }}</td>
            <td class="text-end {{ $selisih > 0 ? 'text-danger' : 'text-success' }}">
              {{ ($selisih > 0 ? '+' : '') . number_format($selisih, 0, ',', '.') }}
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="9" class="text-center text-secondary">Belum ada tiket pada periode ini.</td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>
</div>
<script>
(function () {
    var form = document.getElementById('serviceReportForm');
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
@endsection
