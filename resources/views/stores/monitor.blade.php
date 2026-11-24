@extends('layouts.tabler')
@section('title','Monitoring Pusat')
@section('content')
<div class="page-header d-print-none">
  <div class="row align-items-center">
    <div class="col"><h2 class="page-title"><i class="ti ti-building me-1"></i> Monitoring Pusat</h2><div class="text-secondary">Perbandingan kinerja semua toko — khusus owner.</div></div>
    <div class="col-auto ms-auto">
      <a href="{{ route('stores.index') }}" class="btn btn-outline-secondary">Toko / Cabang</a>
      <button class="btn btn-primary ms-1" onclick="window.print()"><i class="ti ti-printer me-1"></i> Cetak</button>
    </div>
  </div>
</div>

<div class="card mt-2">
  <div class="card-header"><h3 class="card-title">Perbandingan Omzet per Toko (30 hari terakhir)</h3></div>
  <div class="card-body">
    @forelse($rows as $r)
      <div class="mb-2">
        <div class="d-flex justify-content-between small mb-1">
          <span><strong>{{ $r['store']->name }}</strong> <span class="text-secondary">{{ $r['store']->code }}</span></span>
          <span>Rp {{ number_format($r['omzet_30'],0,',','.') }}</span>
        </div>
        <div style="background:#e9ecef;border-radius:4px;height:14px;overflow:hidden">
          <div style="height:100%;background:#206bc4;border-radius:4px;width:{{ $maxOmzet > 0 ? round($r['omzet_30'] / $maxOmzet * 100) : 0 }}%"></div>
        </div>
      </div>
    @empty
      <div class="text-secondary">Belum ada toko.</div>
    @endforelse
  </div>
</div>

<div class="card mt-3">
  <div class="card-header">
    <h3 class="card-title"><i class="ti ti-target me-1"></i> Target Omzet Bulan Ini ({{ now()->format('m/Y') }})</h3>
    <div class="ms-auto">
      <form
        method="POST"
        action="{{ route('stores.targets.copy-next-year') }}"
        onsubmit="return confirm('Salin SEMUA target ke tahun depan? Data yang sudah ada tidak ditimpa.')"
      >
        @csrf
        <button
          type="submit"
          class="btn btn-sm btn-outline-primary"
          title="Salin semua target {toko}:{YYYY-MM} ke {toko}:{YYYY+1-MM}; yang sudah ada dilewati"
        >
          <i class="ti ti-clipboard-list me-1"></i> Salin ke Tahun Depan
        </button>
      </form>
    </div>
  </div>
  <div class="card-body">
    @forelse($rows as $r)
      <div class="mb-3">
        <div class="d-flex justify-content-between align-items-center small mb-1 flex-wrap gap-2">
          <span><strong>{{ $r['store']->name }}</strong> <span class="text-secondary">{{ $r['store']->code }}</span></span>
          <span class="text-secondary">Realisasi Rp {{ number_format($r['omzet_bulan'],0,',','.') }} / Target Rp {{ number_format($r['target'],0,',','.') }}
            @if($r['target'] > 0)<span class="badge bg-{{ $r['target_pct'] >= 100 ? 'green' : ($r['target_pct'] >= 50 ? 'blue' : 'yellow') }}-lt ms-1">{{ $r['target_pct'] }}%</span>@else<span class="badge bg-secondary-lt ms-1">belum ada target</span>@endif
          </span>
        </div>
        <div style="background:#e9ecef;border-radius:4px;height:14px;overflow:hidden" class="mb-1">
          <div style="height:100%;border-radius:4px;background:{{ $r['target_pct'] >= 100 ? '#2fb344' : '#206bc4' }};width:{{ $r['target_pct'] }}%"></div>
        </div>
        <form method="POST" action="{{ route('stores.target', $r['store']->id) }}" class="d-flex gap-2 align-items-center">
          @csrf
          <div class="input-group input-group-sm" style="max-width:280px">
            <span class="input-group-text">Rp</span>
            <input type="number" name="target" class="form-control" min="0" step="1000" value="{{ $r['target'] ? (int) $r['target'] : '' }}" placeholder="cth 50000000" required>
            <button class="btn btn-primary" type="submit">Simpan</button>
          </div>
        </form>
      </div>
    @empty
      <div class="text-secondary">Belum ada toko.</div>
    @endforelse
  </div>
</div>

<div class="card mt-3">
  <div class="card-header">
    <h3 class="card-title"><i class="ti ti-trending-up me-1"></i> Target vs Realisasi {{ $yearNow ?? now()->format('Y') }} — Jan–Des</h3>
    <div class="ms-auto d-flex align-items-center gap-2">
      <label for="selTokoChart" class="form-label mb-0 small text-secondary">Toko</label>
      <select id="selTokoChart" class="form-select form-select-sm" style="min-width:200px">
        <option value="total">TOTAL — Semua Toko</option>
        @foreach($rows as $r)
          <option value="{{ $r['store']->id }}">{{ $r['store']->name }} ({{ $r['store']->code }})</option>
        @endforeach
      </select>
    </div>
  </div>
  <div class="card-body">
    <div class="text-secondary small mb-2">
      Garis putus-putus = target, garis tegas = realisasi omzet (sales). Klik legenda untuk tampil/sembunyikan garis.
    </div>
    <div>
      <canvas id="chartTargetTahun" height="130"></canvas>
    </div>
  </div>
</div>

<div class="card mt-3">
  <div class="table-responsive">
    <table class="table table-vcenter card-table table-striped">
      <thead><tr>
        <th>Toko</th><th class="text-end">Omzet Hari Ini</th><th class="text-end">Omzet Bulan Ini</th>
        <th class="text-end">Trx Hari Ini</th><th class="text-end">Service Aktif</th><th class="text-end">Terlambat</th>
        <th class="text-end">Stok Menipis</th><th class="text-end">Kas Total</th>
        <th class="text-end">Hutang Out.</th><th class="text-end">Piutang Out.</th><th class="text-end">Booking </th>
      </tr></thead>
      <tbody>
      @forelse($rows as $r)
        <tr>
          <td><strong>{{ $r['store']->name }}</strong>
            <div class="small text-secondary">{{ $r['store']->code }}</div>
            @if($r['store']->is_center)<span class="badge bg-yellow-lt">Pusat</span>@endif
          </td>
          <td class="text-end">Rp {{ number_format($r['omzet_hari'],0,',','.') }}</td>
          <td class="text-end">Rp {{ number_format($r['omzet_bulan'],0,',','.') }}</td>
          <td class="text-end">{{ $r['trx_hari'] }}</td>
          <td class="text-end"><span class="badge bg-blue-lt">{{ $r['service_aktif'] }}</span></td>
          <td class="text-end">@if($r['service_terlambat']>0)<span class="badge bg-red-lt">{{ $r['service_terlambat'] }}</span>@else<span class="text-secondary">0</span>@endif</td>
          <td class="text-end">@if($r['stok_menipis']>0)<span class="badge bg-yellow-lt">{{ $r['stok_menipis'] }}</span>@else<span class="text-secondary">0</span>@endif</td>
          <td class="text-end">Rp {{ number_format($r['kas_total'],0,',','.') }}</td>
          <td class="text-end">Rp {{ number_format($r['hutang'],0,',','.') }}</td>
          <td class="text-end">Rp {{ number_format($r['piutang'],0,',','.') }}</td>
          <td class="text-end">@if($r['booking_menunggu']>0)<span class="badge bg-orange-lt">{{ $r['booking_menunggu'] }}</span>@else<span class="text-secondary">0</span>@endif</td>
        </tr>
      @empty
        <tr><td colspan="11" class="text-center text-secondary">Belum ada toko.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</div>
<p class="text-secondary small mt-2">Dicetak: {{ now()->format('d/m/Y H:i') }} — ServiceHP Monitoring Pusat.</p>

@push('js')
<script src="/vendor/chart/chart.umd.min.js"></script>
<script>
(function() {
  var el = document.getElementById('chartTargetTahun');
  var sel = document.getElementById('selTokoChart');
  if (!el || typeof Chart === 'undefined') return;
  var rp = function(v) {
    return 'Rp ' + Number(v || 0).toLocaleString('id-ID');
  };
  var labels = @json($chartBulan ?? []);
  var targetTotal = @json($targetTotalTahun ?? []);
  var realTotal = @json($realTotalTahun ?? []);
  var targetPerToko = @json($targetPerTokoChart ?? []);
  var realPerToko = @json($realPerTokoChart ?? []);
  function datasetsFor(mode) {
    if (mode !== 'total' && realPerToko[mode]) {
      var nama = (realPerToko[mode] && realPerToko[mode].name) || ('Toko #' + mode);
      return [
        {
          label: 'Target — ' + nama,
          data: (targetPerToko[mode] && targetPerToko[mode].data) || [],
          borderColor: '#f59f00',
          backgroundColor: 'rgba(245,159,0,.10)',
          borderDash: [6, 4],
          tension: 0.3
        },
        {
          label: 'Realisasi — ' + nama,
          data: realPerToko[mode].data || [],
          borderColor: '#206bc4',
          backgroundColor: 'rgba(32,107,196,.12)',
          fill: true,
          tension: 0.3
        }
      ];
    }
    return [
      {
        label: 'Target TOTAL',
        data: targetTotal,
        borderColor: '#f59f00',
        backgroundColor: 'rgba(245,159,0,.10)',
        borderDash: [6, 4],
        tension: 0.3
      },
      {
        label: 'Realisasi TOTAL',
        data: realTotal,
        borderColor: '#206bc4',
        backgroundColor: 'rgba(32,107,196,.12)',
        fill: true,
        tension: 0.3
      }
    ];
  }
  var chart = new Chart(el, {
    type: 'line',
    data: {
      labels: labels,
      datasets: datasetsFor('total')
    },
    options: {
      plugins: {
        legend: { position: 'bottom' },
        tooltip: {
          callbacks: {
            label: function(c) {
              return ' ' + c.dataset.label + ': ' + rp(c.parsed.y);
            }
          }
        }
      },
      scales: {
        y: {
          ticks: {
            callback: function(v) { return rp(v); }
          }
        }
      }
    }
  });
  if (sel) {
    sel.addEventListener('change', function() {
      chart.data.datasets = datasetsFor(sel.value);
      chart.update();
    });
  }
})();
</script>
@endpush
@endsection
