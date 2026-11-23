@extends('layouts.tabler')
@section('title','Laporan Komisi Teknisi')
@section('content')
@php
  $mode = $mode ?? 'bulan';
  $tahun = $tahun ?? substr((string) ($bulan ?? date('Y-m')), 0, 4);
  $perMonth = $perMonth ?? [];
@endphp
<div class="page-header d-print-none">
  <div class="row align-items-center">
    <div class="col">
      <h2 class="page-title">Komisi Teknisi per Periode</h2>
      <div class="text-secondary">
        @if($mode === 'tahun')
          Tahun {{ $tahun }} ({{ $start }} s/d {{ $end }})
        @else
          Bulan {{ $bulan }} ({{ $start }} s/d {{ $end }})
        @endif
      </div>
    </div>
    <div class="col-auto ms-auto d-flex gap-2">
      <button class="btn btn-outline-secondary" onclick="window.print()"><i class="ti ti-printer me-1"></i> Cetak</button>
      @if($mode === 'tahun')
        <a
          href="{{ route('technicians.report', ['tahun' => $tahun, 'export' => 'csv']) }}"
          class="btn btn-outline-success"
        >
          <i class="ti ti-download me-1"></i> Export CSV
        </a>
      @else
        <a
          href="{{ route('technicians.report', ['bulan' => $bulan, 'export' => 'csv']) }}"
          class="btn btn-outline-success"
        >
          <i class="ti ti-download me-1"></i> Export CSV
        </a>
      @endif
      <a href="{{ route('technicians.index') }}" class="btn btn-outline-primary">← Teknisi</a>
    </div>
  </div>
</div>

<div class="card mb-3 d-print-none">
  <div class="card-body">
    <form method="GET" class="row g-2">
      <div class="col-md-4">
        <label class="form-label">Bulan</label>
        <input
          type="month"
          name="bulan"
          value="{{ $mode === 'bulan' ? $bulan : '' }}"
          class="form-control"
        >
      </div>
      <div class="col-md-3">
        <label class="form-label">Tahun</label>
        <input
          type="number"
          name="tahun"
          value="{{ $mode === 'tahun' ? $tahun : '' }}"
          min="2000"
          max="2100"
          placeholder="YYYY"
          class="form-control"
        >
      </div>
      <div class="col-md-2 d-flex align-items-end">
        <button class="btn btn-primary w-100">Tampilkan</button>
      </div>
      <div class="col-md-3 d-flex align-items-end">
        <a
          href="{{ route('technicians.report', ['tahun' => date('Y')]) }}"
          class="btn btn-outline-secondary w-100"
        >
          Mode Tahun {{ date('Y') }}
        </a>
      </div>
    </form>
    <div class="small text-secondary mt-1">
      Isi bulan untuk mode bulanan (?bulan=Y-m), atau isi tahun saja untuk agregat Jan–Des (?tahun=YYYY).
    </div>
  </div>
</div>

@if($mode === 'tahun')
  <div class="card mb-3">
    <div class="card-header">
      <h3 class="card-title">Rekap per Bulan — Tahun {{ $tahun }}</h3>
    </div>
    <div class="table-responsive">
      <table class="table card-table table-vcenter">
        <thead>
          <tr>
            <th>Bulan</th>
            <th class="text-center">Tiket Selesai</th>
            <th class="text-center">Jml Komisi</th>
            <th class="text-end">Menunggu</th>
            <th class="text-end">Dibayar</th>
            <th class="text-end">Total</th>
          </tr>
        </thead>
        <tbody>
          @php
            $namaBulan = [1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'];
          @endphp
          @for($m = 1; $m <= 12; $m++)
            @php
              $rm = $perMonth[$m] ?? ['selesai' => 0, 'tiket' => 0, 'menunggu' => 0, 'dibayar' => 0];
            @endphp
            <tr>
              <td>
                {{ $namaBulan[$m] }}
                <span class="text-secondary small">{{ sprintf('%04d-%02d', $tahun, $m) }}</span>
                <a
                  href="{{ route('technicians.report', ['bulan' => sprintf('%04d-%02d', $tahun, $m)]) }}"
                  class="btn btn-sm btn-outline-secondary ms-1 py-0 px-1"
                >
                  <i class="ti ti-search me-1"></i>
                </a>
              </td>
              <td class="text-center">
                <span class="badge bg-green-lt">{{ $rm['selesai'] }}</span>
              </td>
              <td class="text-center">{{ $rm['tiket'] }}</td>
              <td class="text-end">Rp {{ number_format($rm['menunggu'], 0, ',', '.') }}</td>
              <td class="text-end">Rp {{ number_format($rm['dibayar'], 0, ',', '.') }}</td>
              <td class="text-end">
                <b>Rp {{ number_format($rm['menunggu'] + $rm['dibayar'], 0, ',', '.') }}</b>
              </td>
            </tr>
          @endfor
        </tbody>
        <tfoot>
          <tr>
            <th>Total {{ $tahun }}</th>
            <th class="text-center">{{ collect($perMonth)->sum('selesai') }}</th>
            <th class="text-center">{{ collect($perMonth)->sum('tiket') }}</th>
            <th class="text-end">Rp {{ number_format(collect($perMonth)->sum('menunggu'), 0, ',', '.') }}</th>
            <th class="text-end">Rp {{ number_format(collect($perMonth)->sum('dibayar'), 0, ',', '.') }}</th>
            <th class="text-end">
              <b>
                Rp {{ number_format(collect($perMonth)->sum('menunggu') + collect($perMonth)->sum('dibayar'), 0, ',', '.') }}
              </b>
            </th>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
@endif

<div class="print-area">
<div class="card"><div class="card-header"><h3 class="card-title">Rekap per Teknisi</h3></div>
<div class="table-responsive"><table class="table card-table table-vcenter"><thead><tr><th>ID</th><th>Teknisi</th><th class="text-center">Tiket Selesai</th><th class="text-center">Target</th><th>Capaian</th><th class="text-center">Jml Komisi</th><th class="text-end">Menunggu</th><th class="text-end">Dibayar</th><th class="text-end">Total</th><th>Bukti</th></tr></thead><tbody>
@forelse($perTech as $id => $r)
<tr>
  <td>{{ $id }}</td><td>{{ $r['tech']->name }} <a href="{{ route('technicians.slip', ['id' => $id, 'bulan' => $bulan]) }}" class="btn btn-sm btn-outline-secondary ms-1 py-0 px-1" title="Cetak slip komisi {{ $r['tech']->name }}"><i class="ti ti-receipt me-1"></i></a></td>
  <td class="text-center"><span class="badge bg-green-lt">{{ $r['selesai'] }}</span></td>
  <td class="text-center">{{ $r['target'] > 0 ? $r['target'] : '-' }}</td>
  <td style="min-width:140px">
    @if($r['target'] > 0)
    <div class="progress progress-sm"><div class="progress-bar {{ $r['target_pct'] >= 100 ? 'bg-success' : 'bg-primary' }}" style="width: {{ $r['target_pct'] }}%"></div></div>
    <div class="small text-secondary">{{ $r['target_pct'] }}%</div>
    @else
    <span class="text-secondary small">-</span>
    @endif
  </td>
  <td class="text-center">{{ $r['tiket'] }}</td>
  <td class="text-end">Rp {{ number_format($r['menunggu'],0,',','.') }}</td>
  <td class="text-end">Rp {{ number_format($r['dibayar'],0,',','.') }}</td>
  <td class="text-end"><b>Rp {{ number_format($r['menunggu']+$r['dibayar'],0,',','.') }}</b></td>
  <td>
    @if(!empty($buktiKomisiByTech[$id] ?? null))
      <a
        href="{{ asset('storage/' . $buktiKomisiByTech[$id]) }}"
        target="_blank"
        class="small"
        title="Bukti pembayaran komisi {{ $r['tech']->name }} periode {{ $bulan }}"
      >
        <i class="ti ti-paperclip me-1"></i> Bukti
      </a>
    @else
      <span class="text-secondary small">-</span>
    @endif
  </td>
</tr>
@empty
<tr><td colspan="10" class="text-center text-secondary">Belum ada teknisi.</td></tr>
@endforelse
</tbody></table></div></div>

<div class="card mt-3 d-print-none">
  <div class="card-header">
    <h3 class="card-title">
      Grafik Komisi per Teknisi
    </h3>
    <span class="text-secondary ms-2">
      @if($mode === 'tahun')
        tahun {{ $tahun }} — total = menunggu + dibayar
      @else
        bulan {{ $bulan }} — total = menunggu + dibayar
      @endif
    </span>
  </div>
  <div class="card-body">
    @if(count($perTech))
      <canvas
        id="chartKomisi"
        height="120"
      ></canvas>
      <div class="text-secondary small mt-2">
        @if($mode === 'tahun')
          Batang per teknisi = total komisi setahun {{ $tahun }}.
        @else
          Batang per teknisi = total komisi bulan filter.
        @endif
        Arahkan kursor ke batang untuk rincian menunggu vs dibayar.
      </div>
    @else
      <div class="text-secondary">
        Belum ada data komisi untuk grafik bulan ini.
      </div>
    @endif
  </div>
</div>

<div class="card mt-3"><div class="card-header"><h3 class="card-title">Rincian Komisi ({{ $rows->count() }})</h3></div>
<div class="table-responsive"><table class="table card-table"><thead><tr><th>ID</th><th>Tanggal</th><th>Tiket</th><th>Teknisi</th><th class="text-end">Nominal</th><th>Status</th><th>Bukti</th></tr></thead><tbody>
@forelse($rows as $c)<tr>
  <td>{{ $c->id }}</td><td class="small">{{ $c->created_at?->format('d/m/Y') }}</td>
  <td><a href="{{ route('services.show',$c->service_ticket_id) }}">{{ $c->ticket->ticket_no ?? '#'.$c->service_ticket_id }}</a></td>
  <td>{{ $c->technician->name ?? '' }}</td>
  <td class="text-end">Rp {{ number_format($c->amount,0,',','.') }}</td>
  <td><span class="badge {{ $c->status=='dibayar'?'bg-green-lt':'bg-yellow-lt' }}">{{ $c->status }}</span></td>
  <td>
    @if($c->status == 'dibayar' && !empty($buktiKomisiByTech[$c->technician_id] ?? null))
      <a
        href="{{ asset('storage/' . $buktiKomisiByTech[$c->technician_id]) }}"
        target="_blank"
        class="small"
        title="Bukti pembayaran komisi {{ $c->technician->name ?? '' }} periode {{ $bulan }}"
      >
        <i class="ti ti-paperclip me-1"></i> Bukti
      </a>
    @else
      <span class="text-secondary small">-</span>
    @endif
  </td>
</tr>@empty<tr><td colspan="7" class="text-center text-secondary">Belum ada komisi pada bulan ini.</td></tr>@endforelse
</tbody></table></div></div>
</div>
@php
  $komisiLabels = collect($perTech)
    ->map(fn ($r) => $r['tech']->name)
    ->values();
  $komisiMenunggu = collect($perTech)
    ->map(fn ($r) => (float) $r['menunggu'])
    ->values();
  $komisiDibayar = collect($perTech)
    ->map(fn ($r) => (float) $r['dibayar'])
    ->values();
@endphp
@push('js')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
try {
  const elKomisi = document.getElementById('chartKomisi');
  if (elKomisi) {
    const rpKomisi = (v) => 'Rp ' + Number(v || 0).toLocaleString('id-ID');
    new Chart(elKomisi, {
      type: 'bar',
      data: {
        labels: @json($komisiLabels),
        datasets: [
          {
            label: 'Menunggu',
            data: @json($komisiMenunggu),
            backgroundColor: '#f59f00'
          },
          {
            label: 'Dibayar',
            data: @json($komisiDibayar),
            backgroundColor: '#2fb344'
          }
        ]
      },
      options: {
        plugins: {
          legend: { position: 'bottom' },
          tooltip: {
            callbacks: {
              label: (c) => ' ' + c.dataset.label + ': ' + rpKomisi(c.parsed.y)
            }
          }
        },
        scales: {
          x: { stacked: true },
          y: {
            stacked: true,
            beginAtZero: true,
            ticks: { callback: (v) => rpKomisi(v) }
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
@endsection
