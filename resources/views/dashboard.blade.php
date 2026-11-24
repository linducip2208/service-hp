@extends('layouts.tabler')
@section('title','Dashboard')
@section('content')
<div class="page-header d-print-none no-print">
  <div class="row align-items-center">
    <div class="col">
      <h2 class="page-title">Dashboard Operasional</h2>
      <div class="text-secondary">Ringkasan {{ $targetWidget['store_name'] ?? 'toko aktif' }} hari ini — pantau penjualan, service, dan stok sekilas.</div>
      @if(!empty($partial))
        <div class="mt-1"><span class="badge bg-yellow-lt" title="Sebagian widget gagal dimuat (lihat log)"><i class="ti ti-alert-triangle me-1"></i>Sebagian data tidak termuat</span></div>
      @endif
    </div>
    <div class="col-auto ms-auto d-flex flex-wrap gap-2 align-items-center">
      <span class="text-secondary small d-none d-md-inline">{{ now()->format('d M Y') }}</span>
      @if(in_array(auth()->user()->role ?? '', ['superadmin','owner','admin','kasir']))
      <a href="{{ route('pos.index') }}" class="btn btn-primary"><i class="ti ti-plus me-1"></i>Transaksi Baru</a>
      <a href="{{ route('sales.index') }}" class="btn btn-outline-secondary"><i class="ti ti-history me-1"></i>Riwayat</a>
      @endif
      <a href="{{ route('services.create') }}" class="btn btn-outline-primary"><i class="ti ti-tool me-1"></i>Terima Service</a>
      @if(in_array(auth()->user()->role ?? '', ['superadmin','owner','admin','gudang']))
      <a href="{{ route('stocks.opname') }}" class="btn btn-outline-secondary"><i class="ti ti-clipboard-check me-1"></i>Opname</a>
      @endif
      @if(in_array(auth()->user()->role ?? '', ['superadmin','owner']))
      <a href="{{ route('sales.closing') }}" class="btn btn-outline-warning"><i class="ti ti-lock me-1"></i>Tutup Kasir</a>
      @endif
    </div>
  </div>
</div>
<div class="row row-cards mt-2">
  <div class="col-sm-6 col-lg-3">
    <div class="card corp-kpi">
      <div class="card-body">
        <div class="corp-kpi-top"><span class="corp-kpi-icon blue"><i class="ti ti-cash"></i></span><span class="corp-kpi-label">Omzet hari ini <span class="text-lowercase fw-normal">(invoice)</span></span></div>
        <div class="corp-kpi-value">Rp {{ number_format($stats['penjualan_hari_ini'] ?? 0,0,',','.') }}</div>
        <div class="corp-kpi-sub">Kemarin: Rp {{ number_format($stats['penjualan_kemarin'] ?? 0,0,',','.') }}</div>
        <div class="mt-1">
          @php $diff = $stats['penjualan_diff'] ?? 0; $pct = $stats['penjualan_pct'] ?? null; @endphp
          @if($diff > 0)<span class="badge bg-green-lt">▲ +Rp {{ number_format($diff,0,',','.') }} @if(!is_null($pct))(+{{ $pct }}%)@endif vs kemarin</span>
          @elseif($diff < 0)<span class="badge bg-red-lt">▼ −Rp {{ number_format(abs($diff),0,',','.') }} @if(!is_null($pct))({{ $pct }}%)@endif vs kemarin</span>
          @else<span class="badge bg-secondary-lt">▬ sama seperti kemarin</span>@endif
        </div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="card corp-kpi">
      <div class="card-body">
        <div class="corp-kpi-top"><span class="corp-kpi-icon green"><i class="ti ti-receipt"></i></span><span class="corp-kpi-label">Transaksi · profit kotor</span></div>
        <div class="corp-kpi-value">{{ $stats['trx_hari_ini'] ?? 0 }} <small class="text-secondary fs-6">trx</small></div>
        <div class="corp-kpi-sub">Profit kotor (produk): <b class="text-success">Rp {{ number_format($stats['profit_kotor_hari_ini'] ?? 0,0,',','.') }}</b></div>
        <div class="corp-kpi-sub">Kas diterima: Rp {{ number_format($stats['kas_diterima_hari_ini'] ?? 0,0,',','.') }}</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="card corp-kpi">
      <div class="card-body">
        <div class="corp-kpi-top"><span class="corp-kpi-icon blue"><i class="ti ti-tool"></i></span><span class="corp-kpi-label">Service aktif</span></div>
        <div class="corp-kpi-value">{{ $stats['service_aktif'] ?? 0 }} <small class="text-secondary fs-6">tiket</small></div>
        <div class="corp-kpi-sub">Siap diambil: <b>{{ $stats['siap_diambil'] ?? 0 }}</b> · <a href="{{ route('services.index') }}" class="btn btn-sm btn-ghost-primary mt-1 px-0">Kelola tiket →</a></div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="card corp-kpi">
      <div class="card-body">
        <div class="corp-kpi-top"><span class="corp-kpi-icon amber"><i class="ti ti-box"></i></span><span class="corp-kpi-label">Stok menipis · piutang</span></div>
        <div class="corp-kpi-value">{{ $stats['stok_menipis'] ?? 0 }} <small class="text-secondary fs-6">produk</small></div>
        <div class="corp-kpi-sub">Piutang outstanding: <b class="text-danger">Rp {{ number_format($stats['piutang_outstanding'] ?? 0,0,',','.') }}</b></div>
        <div class="corp-kpi-sub">Pelanggan baru 30h: {{ $stats['pelanggan_baru_30h'] ?? 0 }}</div>
      </div>
    </div>
  </div>
</div>
@if((auth()->user()->role ?? '') === 'teknisi' && !empty($myTickets) && $myTickets->count())
<div class="card mt-3 border-primary">
  <div class="card-header"><h3 class="card-title"><i class="ti ti-user-cog me-1"></i>Tiket saya ({{ $myTickets->count() }})</h3></div>
  <div class="table-responsive"><table class="table table-sm card-table mb-0"><thead><tr><th>Tiket</th><th>Device</th><th>Status</th><th>Prioritas</th><th></th></tr></thead>
  <tbody>@foreach($myTickets as $t)<tr class="{{ ($t->priority ?? '')==='mendesak' ? 'table-danger' : '' }}"><td class="font-monospace">{{ $t->ticket_no }}</td><td>{{ $t->product_brand }} {{ $t->product_type }}</td><td><span class="badge bg-blue-lt">{{ $t->status }}</span></td><td>@if(($t->priority ?? '')==='mendesak')<span class="badge bg-red-lt">mendesak</span>@else<span class="text-secondary">{{ $t->priority ?? '-' }}</span>@endif</td><td class="text-end"><a href="{{ route('services.show', $t->id) }}" class="btn btn-sm btn-ghost-primary">Kerjakan →</a></td></tr>@endforeach</tbody></table></div>
</div>
@endif
@if(in_array(auth()->user()->role ?? '', ['superadmin','owner','admin']) && !empty($alerts))
@php
  $alertGroups = ['mendesak' => ['label' => 'Mendesak — tangani sekarang', 'cls' => 'red'], 'hari_ini' => ['label' => 'Hari ini', 'cls' => 'orange'], 'rutin' => ['label' => 'Rutin dipantau', 'cls' => 'yellow']];
  $alertByLevel = collect($alerts)->groupBy(fn($a) => $a['level'] ?? 'rutin');
@endphp
<div class="card mt-3 no-print">
  <div class="card-header">
    <h3 class="card-title"><i class="ti ti-alert-triangle me-1"></i>Perlu perhatian ({{ count($alerts) }})</h3>
    <div class="card-actions">
      <a href="{{ route('reminders.index') }}" class="btn btn-sm btn-outline-primary"><i class="ti ti-bell me-1"></i>Pusat pengingat</a>
    </div>
  </div>
  @foreach($alertGroups as $lvl => $meta)
    @if(!empty($alertByLevel[$lvl]) && count($alertByLevel[$lvl]))
    <div class="card-body py-2 border-bottom">
      <span class="badge bg-{{ $meta['cls'] }}-lt">{{ $meta['label'] }} ({{ count($alertByLevel[$lvl]) }})</span>
    </div>
    <div class="list-group list-group-flush">
    @foreach($alertByLevel[$lvl] as $a)
      <a href="{{ $a['url'] }}" class="list-group-item list-group-item-action d-flex align-items-center gap-2">
        <i class="ti {{ $a['icon'] ?? 'ti-alert-circle' }} text-{{ $meta['cls'] === 'orange' ? 'warning' : $meta['cls'] }}"></i><span>{{ $a['text'] }}</span><span class="ms-auto text-primary">→</span>
      </a>
    @endforeach
    </div>
    @endif
  @endforeach
</div>
@endif
@if(!empty($targetWidget))
<div class="card mt-3">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div>
        <div class="subheader">
          Target vs realisasi — {{ $targetWidget['store_name'] ?? 'Toko aktif' }} ({{ $targetWidget['month'] }})
        </div>
        <div class="h3 mb-0">
          Rp {{ number_format($targetWidget['realisasi'],0,',','.') }}
          <span class="text-secondary small">/ Rp {{ number_format($targetWidget['target'],0,',','.') }}</span>
        </div>
      </div>
      @if($targetWidget['target'] > 0)
        <span class="badge bg-{{ $targetWidget['pct'] >= 100 ? 'green' : ($targetWidget['pct'] >= 50 ? 'blue' : 'yellow') }}-lt">
          {{ $targetWidget['pct'] }}% tercapai
        </span>
      @else
        <span class="badge bg-secondary-lt">belum ada target</span>
      @endif
    </div>
    <div style="background:#e9ecef;border-radius:4px;height:12px;overflow:hidden" class="mt-2">
      <div style="height:100%;border-radius:4px;background:{{ ($targetWidget['pct'] ?? 0) >= 100 ? '#2fb344' : '#206bc4' }};width:{{ $targetWidget['pct'] }}%"></div>
    </div>
    @if(($targetWidget['target'] ?? 0) > 0)
      <div class="mt-3 p-2 rounded bg-light border">
        <div class="subheader mb-1">
          Target harian — sisa Rp {{ number_format($targetWidget['sisa_target'] ?? 0,0,',','.') }}
          / {{ $targetWidget['days_left'] ?? 1 }} hari (termasuk hari ini)
        </div>
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
          <div>
            <div class="small text-secondary">Harus terkumpul hari ini</div>
            <div class="h4 mb-0">
              Rp {{ number_format($targetWidget['daily_target'] ?? 0,0,',','.') }}
            </div>
          </div>
          <div class="text-end">
            <div class="small text-secondary">Realisasi hari ini</div>
            <div class="h4 mb-0">
              Rp {{ number_format($targetWidget['today_sales'] ?? 0,0,',','.') }}
            </div>
          </div>
          <div>
            @if(is_null($targetWidget['daily_reached'] ?? null))
              <span class="badge bg-secondary-lt">belum ada target</span>
            @elseif(!empty($targetWidget['daily_reached']))
              <span class="badge bg-green-lt">
                Tercapai
                @if(($targetWidget['daily_diff'] ?? 0) > 0)
                  (+Rp {{ number_format($targetWidget['daily_diff'],0,',','.') }})
                @endif
              </span>
            @else
              <span class="badge bg-yellow-lt">
                Kurang Rp {{ number_format(abs($targetWidget['daily_diff'] ?? 0),0,',','.') }}
              </span>
            @endif
          </div>
        </div>
      </div>
    @endif
    <div class="mt-2">
      <a href="{{ route('stores.monitor') }}" class="btn btn-sm btn-ghost-primary">Kelola target →</a>
    </div>
  </div>
</div>
@endif
@if(!empty($annualTarget))
<div class="card mt-3">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div>
        <div class="subheader">
          Progres tahunan — {{ $annualTarget['year'] }}
          ({{ $targetWidget['store_name'] ?? 'Toko aktif' }})
        </div>
        <div class="h3 mb-0">
          Rp {{ number_format($annualTarget['ytd'] ?? 0, 0, ',', '.') }}
          <span class="text-secondary small">
            / Rp {{ number_format($annualTarget['total'] ?? 0, 0, ',', '.') }}
          </span>
        </div>
        <div class="small text-secondary mt-1">
          Sum target Jan–Des yang sudah diset
          ({{ $annualTarget['months_set'] ?? 0 }}/12 bulan)
          vs realisasi YTD (1 Jan s/d hari ini).
        </div>
      </div>
      @if(($annualTarget['total'] ?? 0) > 0)
        <span class="badge bg-{{ ($annualTarget['pct'] ?? 0) >= 100 ? 'green' : (($annualTarget['pct'] ?? 0) >= 50 ? 'blue' : 'yellow') }}-lt">
          {{ $annualTarget['pct'] }}% tercapai
        </span>
      @else
        <span class="badge bg-secondary-lt">
          belum ada target tahun ini
        </span>
      @endif
    </div>
    <div class="mt-2 progress" style="height: 12px;">
      <div
        class="progress-bar {{ ($annualTarget['pct'] ?? 0) >= 100 ? 'bg-green' : 'bg-primary' }}"
        role="progressbar"
        style="width: {{ $annualTarget['pct'] ?? 0 }}%;"
      >
      </div>
    </div>
    <div class="row mt-3">
      <div class="col-sm-4">
        <div class="small text-secondary">
          Total target {{ $annualTarget['year'] }}
        </div>
        <div class="h4 mb-0">
          Rp {{ number_format($annualTarget['total'] ?? 0, 0, ',', '.') }}
        </div>
      </div>
      <div class="col-sm-4">
        <div class="small text-secondary">
          Realisasi YTD
        </div>
        <div class="h4 mb-0">
          Rp {{ number_format($annualTarget['ytd'] ?? 0, 0, ',', '.') }}
        </div>
      </div>
      <div class="col-sm-4">
        <div class="small text-secondary">
          Sisa target
        </div>
        <div class="h4 mb-0">
          Rp {{ number_format($annualTarget['sisa'] ?? 0, 0, ',', '.') }}
        </div>
      </div>
    </div>
    @php
      $namaBulan = [
        1 => 'Jan',
        2 => 'Feb',
        3 => 'Mar',
        4 => 'Apr',
        5 => 'Mei',
        6 => 'Jun',
        7 => 'Jul',
        8 => 'Agu',
        9 => 'Sep',
        10 => 'Okt',
        11 => 'Nov',
        12 => 'Des',
      ];
    @endphp
    <div class="table-responsive mt-3">
      <table class="table table-sm card-table mb-0">
        <thead>
          <tr>
            <th>Bulan</th>
            <th class="text-end">Target</th>
            <th class="text-end">Status</th>
          </tr>
        </thead>
        <tbody>
          @foreach(($annualTarget['months'] ?? []) as $am)
            <tr>
              <td>
                {{ $namaBulan[$am['month']] ?? $am['month'] }}
                @if(!empty($am['is_current']))
                  <span class="badge bg-blue-lt ms-1">
                    berjalan
                  </span>
                @endif
              </td>
              <td class="text-end">
                @if(!empty($am['is_set']))
                  Rp {{ number_format($am['target'], 0, ',', '.') }}
                @else
                  <span class="text-secondary">—</span>
                @endif
              </td>
              <td class="text-end">
                @if(!empty($am['is_set']))
                  <span class="badge bg-green-lt">
                    diset
                  </span>
                @else
                  <span class="badge bg-secondary-lt">
                    belum
                  </span>
                @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    <div class="mt-2">
      <a href="{{ route('stores.monitor') }}" class="btn btn-sm btn-ghost-primary">
        Kelola target tahunan →
      </a>
    </div>
  </div>
</div>
@endif
@php
  $todayCount = ($todayBookings ?? collect())->count() + ($todayTickets ?? collect())->count();
  $tomorrowCount = ($tomorrowBookings ?? collect())->count() + ($tomorrowTickets ?? collect())->count();
@endphp
@if($todayCount || $tomorrowCount)
<div class="card mt-3">
  <div class="card-header">
    <h3 class="card-title">
      <i class="ti ti-calendar me-1"></i> Jadwal Hari Ini ({{ $todayCount }}) & Besok ({{ $tomorrowCount }})
    </h3>
  </div>
  <div class="card-body py-2">
    <div class="subheader mb-1">
      Hari ini — {{ now()->format('d/m/Y') }} ({{ $todayCount }} jadwal)
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-sm card-table mb-0">
      <thead>
        <tr>
          <th>Jenis</th>
          <th>Kode</th>
          <th>Pelanggan / Device</th>
          <th>Waktu</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        @forelse($todayBookings ?? [] as $b)
          <tr>
            <td>
              <span class="badge bg-azure-lt">Booking</span>
            </td>
            <td class="font-monospace">{{ $b->code }}</td>
            <td>
              {{ $b->customer_name }}
              <div class="small text-secondary">{{ $b->device }}</div>
            </td>
            <td class="small">
              {{ $b->schedule_date ? substr($b->schedule_date, 11, 5) : '-' }}
            </td>
            <td>
              <span class="badge bg-yellow-lt">{{ $b->status }}</span>
            </td>
            <td class="text-end">
              <a href="{{ route('bookings.index') }}" class="btn btn-sm btn-ghost-primary">
                Konfirmasi →
              </a>
            </td>
          </tr>
        @empty
          @if(!($todayTickets ?? collect())->count())
            <tr>
              <td colspan="6" class="text-center text-secondary">
                Tidak ada jadwal hari ini.
              </td>
            </tr>
          @endif
        @endforelse
        @foreach($todayTickets ?? [] as $t)
          <tr>
            <td>
              <span class="badge bg-green-lt">Estimasi selesai</span>
            </td>
            <td class="font-monospace">{{ $t->ticket_no }}</td>
            <td>
              {{ $t->product_brand }} {{ $t->product_type }}
            </td>
            <td class="small">
              {{ $t->estimated_done_at ? substr($t->estimated_done_at, 11, 5) : '-' }}
            </td>
            <td>
              <span class="badge bg-blue-lt">{{ $t->status }}</span>
            </td>
            <td class="text-end">
              <a href="{{ route('services.show', $t->id) }}" class="btn btn-sm btn-ghost-primary">
                Tiket →
              </a>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
  <div class="card-body py-2 border-top">
    <div class="subheader mb-1">
      Besok — {{ isset($tomorrow) ? \Carbon\Carbon::parse($tomorrow)->format('d/m/Y') : now()->addDay()->format('d/m/Y') }} ({{ $tomorrowCount }} jadwal)
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-sm card-table mb-0">
      <thead>
        <tr>
          <th>Jenis</th>
          <th>Kode</th>
          <th>Pelanggan / Device</th>
          <th>Waktu</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        @forelse($tomorrowBookings ?? [] as $b)
          <tr>
            <td>
              <span class="badge bg-azure-lt">Booking</span>
            </td>
            <td class="font-monospace">{{ $b->code }}</td>
            <td>
              {{ $b->customer_name }}
              <div class="small text-secondary">{{ $b->device }}</div>
            </td>
            <td class="small">
              {{ $b->schedule_date ? substr($b->schedule_date, 11, 5) : '-' }}
            </td>
            <td>
              <span class="badge bg-yellow-lt">{{ $b->status }}</span>
            </td>
            <td class="text-end">
              <a href="{{ route('bookings.index') }}" class="btn btn-sm btn-ghost-primary">
                Konfirmasi →
              </a>
            </td>
          </tr>
        @empty
          @if(!($tomorrowTickets ?? collect())->count())
            <tr>
              <td colspan="6" class="text-center text-secondary">
                Tidak ada jadwal besok.
              </td>
            </tr>
          @endif
        @endforelse
        @foreach($tomorrowTickets ?? [] as $t)
          <tr>
            <td>
              <span class="badge bg-green-lt">Estimasi selesai</span>
            </td>
            <td class="font-monospace">{{ $t->ticket_no }}</td>
            <td>
              {{ $t->product_brand }} {{ $t->product_type }}
            </td>
            <td class="small">
              {{ $t->estimated_done_at ? substr($t->estimated_done_at, 11, 5) : '-' }}
            </td>
            <td>
              <span class="badge bg-blue-lt">{{ $t->status }}</span>
            </td>
            <td class="text-end">
              <a href="{{ route('services.show', $t->id) }}" class="btn btn-sm btn-ghost-primary">
                Tiket →
              </a>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
</div>
@endif
<div class="row row-cards mt-3">
  <div class="col-lg-6"><div class="card"><div class="card-header"><h3 class="card-title">Penjualan Terakhir</h3></div>
    <div class="table-responsive"><table class="table card-table table-vcenter"><thead><tr><th>Invoice</th><th>Total</th><th>Bayar</th><th>Waktu</th></tr></thead><tbody>
    @forelse($recentSales as $s)<tr><td>{{ $s->invoice_no }}</td><td>Rp {{ number_format($s->total,0,',','.') }}</td><td><span class="badge bg-green-lt">{{ $s->payment_method }} / {{ $s->payment_status }}</span></td><td class="text-secondary">{{ $s->created_at ? \Carbon\Carbon::parse($s->created_at)->format('d/m/Y H:i') : '' }}</td></tr>
    @empty<tr><td colspan="4" class="text-center text-secondary">Belum ada transaksi.</td></tr>@endforelse
    </tbody></table></div></div></div>
  <div class="col-lg-6"><div class="card"><div class="card-header"><h3 class="card-title">Service Terbaru</h3></div>
    <div class="table-responsive"><table class="table card-table table-vcenter"><thead><tr><th>Tiket</th><th>Device</th><th>Status</th></tr></thead><tbody>
    @forelse($recentService as $t)<tr><td>{{ $t->ticket_no }}</td><td>{{ $t->product_brand }} {{ $t->product_type }}</td><td><span class="badge bg-blue-lt">{{ $t->status }}</span></td></tr>
    @empty<tr><td colspan="3" class="text-center text-secondary">Belum ada tiket.</td></tr>@endforelse
    </tbody></table></div></div></div>
</div>
<div class="row row-cards mt-3">
  <div class="col-lg-6"><div class="card"><div class="card-header"><h3 class="card-title">Tren Omzet — 30 Hari</h3><div class="card-subtitle">Total invoice per hari</div></div>
    <div class="card-body"><canvas id="ch-rev" height="120"></canvas></div></div></div>
  <div class="col-lg-6"><div class="card"><div class="card-header"><h3 class="card-title">Tren Profit Kotor — 30 Hari</h3><div class="card-subtitle">Margin produk per hari (jasa belum termasuk)</div></div>
    <div class="card-body"><canvas id="ch-profit" height="120"></canvas></div></div></div>
</div>
<div class="row row-cards mt-3">
  <div class="col-lg-4"><div class="card"><div class="card-header"><h3 class="card-title">Metode Bayar Bulan Ini</h3><div class="card-subtitle">Kas benar-benar diterima</div></div>
    <div class="card-body"><canvas id="ch-pay" height="200"></canvas>
    @if(empty($payLabels))<div class="text-secondary text-center small mt-2">Belum ada pembayaran bulan ini.</div>@endif</div></div></div>
  <div class="col-lg-4"><div class="card"><div class="card-header"><h3 class="card-title">Top 5 Produk Terlaris (30 hari)</h3></div>
    <div class="table-responsive"><table class="table table-sm card-table mb-0"><thead><tr><th>Produk</th><th class="text-end">Qty</th><th class="text-end">Omzet</th></tr></thead>
    <tbody>@forelse($topProducts as $t)<tr><td>{{ $t->name }}</td><td class="text-end">{{ $t->qty }}</td><td class="text-end">Rp {{ number_format($t->omzet,0,',','.') }}</td></tr>
    @empty<tr><td colspan="3" class="text-center text-secondary">Belum ada penjualan.</td></tr>@endforelse</tbody></table></div></div></div>
  <div class="col-lg-4"><div class="card"><div class="card-header"><h3 class="card-title">Alur Service Operasional</h3><div class="card-subtitle">{{ $serviceOps['active'] ?? 0 }} tiket aktif</div></div><div class="card-body">
    @php $flowLabels = ['diterima'=>'Diterima','diagnosa'=>'Diagnosa','menunggu_persetujuan'=>'Menunggu Persetujuan','disetujui'=>'Disetujui','dikerjakan'=>'Dikerjakan','qc'=>'QC','siap_diambil'=>'Siap Diambil','diserahkan'=>'Diserahkan','batal'=>'Batal']; @endphp
    @forelse(($serviceOps['counts'] ?? []) as $st => $c)
      <a href="{{ route('services.index') }}" class="d-flex align-items-center gap-2 py-1 text-decoration-none text-reset">
        <span class="badge bg-{{ in_array($st,['siap_diambil'])?'green':(in_array($st,['diserahkan'])?'secondary':(in_array($st,['batal'])?'dark':'blue')) }}-lt" style="min-width:34px">{{ $c }}</span>
        <span>{{ $flowLabels[$st] ?? $st }}</span>
        @if($st==='siap_diambil' && $c>0)<span class="ms-auto badge bg-green-lt">ambil!</span>@endif
      </a>
    @empty<span class="text-secondary">Belum ada tiket.</span>@endforelse
    <div class="mt-2"><a href="{{ route('services.index') }}" class="btn btn-sm btn-ghost-primary">Kelola service →</a></div></div></div></div>
  <div class="col-lg-4"><div class="card"><div class="card-header"><h3 class="card-title">Stok Menipis</h3></div>
    <div class="table-responsive"><table class="table table-sm card-table mb-0"><thead><tr><th>Produk</th><th class="text-end">Sisa</th></tr></thead>
    <tbody>@forelse($lowStocks as $l)<tr><td>{{ $l->name }}<div class="small text-secondary">min {{ $l->min_stock }}</div></td><td class="text-end"><span class="badge bg-{{ $l->qty<=0?'red':'yellow' }}-lt">{{ $l->qty }}</span></td></tr>
    @empty<tr><td colspan="2" class="text-center text-secondary">Stok aman.</td></tr>@endforelse</tbody></table></div></div></div>
</div>
@if(in_array(auth()->user()->role ?? '', ['superadmin','owner']) && !empty($branchSummary))
<div class="card mt-3">
  <div class="card-header"><h3 class="card-title"><i class="ti ti-building me-1"></i>Semua Cabang Hari Ini</h3><div class="card-actions"><a href="{{ route('stores.monitor') }}" class="btn btn-sm btn-ghost-primary">Monitoring pusat →</a></div></div>
  <div class="table-responsive"><table class="table table-sm card-table mb-0"><thead><tr><th>Cabang</th><th class="text-end">Omzet</th><th class="text-end">Trx</th><th class="text-end">Service aktif</th><th class="text-end">Stok kritis</th></tr></thead>
  <tbody>@foreach($branchSummary as $b)<tr><td>{{ $b['store']->name }}</td><td class="text-end">Rp {{ number_format($b['omzet'],0,',','.') }}</td><td class="text-end">{{ $b['trx'] }}</td><td class="text-end">{{ $b['service_aktif'] }}</td><td class="text-end">@if($b['stok_kritis']>0)<span class="badge bg-red-lt">{{ $b['stok_kritis'] }}</span>@else<span class="text-secondary">0</span>@endif</td></tr>@endforeach</tbody></table></div>
</div>
@endif
@if($pendingBookings->count())
<div class="card mt-3"><div class="card-header"><h3 class="card-title"> Booking Menunggu Konfirmasi ({{ $pendingBookings->count() }})</h3></div>
  <div class="table-responsive"><table class="table table-sm card-table mb-0"><thead><tr><th>Kode</th><th>Pelanggan</th><th>Device</th><th>Jadwal</th><th>Status</th></tr></thead>
  <tbody>@foreach($pendingBookings as $b)<tr><td class="font-monospace">{{ $b->code }}</td><td>{{ $b->customer_name }}<div class="small text-secondary">{{ $b->customer_phone }}</div></td>
  <td>{{ $b->device }}</td><td class="small">{{ $b->schedule_date }}</td><td><span class="badge bg-yellow-lt">{{ $b->status }}</span></td></tr>@endforeach</tbody></table></div></div>
@endif
<div class="card mt-3 no-print"><div class="card-body">
  <h3 class="card-title">Alur Kerja Service HP</h3>
  <ol class="mb-0"><li>HP diterima (input pelanggan, tipe, IMEI, keluhan, foto)</li><li>Diagnosa teknisi + estimasi</li><li>Persetujuan pelanggan (WA)</li><li>Pengerjaan + sparepart</li><li>QC → Siap diambil (notif WA) → Serah terima + garansi</li></ol>
</div></div>
@push('js')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function(){
  if(typeof Chart === 'undefined') return;
  const rp = v => 'Rp '+Number(v).toLocaleString('id-ID');
  const rev = document.getElementById('ch-rev');
  if(rev) new Chart(rev, {type:'line', data:{labels:@json($revLabels ?? []), datasets:[{label:'Omzet (Rp)', data:@json($revData ?? []), borderColor:'#1769f5', backgroundColor:'rgba(23,105,245,.12)', fill:true, tension:.3}]}, options:{plugins:{legend:{display:false}}, scales:{y:{ticks:{callback:rp}}}}});
  const prf = document.getElementById('ch-profit');
  if(prf) new Chart(prf, {type:'bar', data:{labels:@json($trends['labels'] ?? $revLabels ?? []), datasets:[{label:'Profit kotor (Rp)', data:@json($trends['profit'] ?? []), backgroundColor:'#16a36a'}]}, options:{plugins:{legend:{display:false}}, scales:{y:{ticks:{callback:rp}}}}});
  const pay = document.getElementById('ch-pay');
  const pl = @json($payLabels ?? []);
  if(pay && pl.length) new Chart(pay, {type:'doughnut', data:{labels:pl, datasets:[{data:@json($payData ?? []), backgroundColor:['#1769f5','#16a36a','#e74c3c','#f59e0b','#6c757d']}]}, options:{plugins:{legend:{position:'bottom'}}}});
})();
</script>
@endpush
@endsection
