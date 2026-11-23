@extends('layouts.tabler')
@section('title', 'Tutup Kasir')
@section('content')
<style>@media print{ .no-print{ display:none !important; } .card{ break-inside: avoid; } }</style>
<div class="page-header no-print"><div class="row align-items-center">
  <div class="col"><h2 class="page-title">Rekap Tutup Kasir</h2><div class="text-secondary">Rekap per tanggal + kasir: omzet, per metode, diskon, retur, kas masuk/keluar.</div></div>
  <div class="col-auto"><button class="btn btn-primary" onclick="window.print()"><i class="ti ti-printer me-1"></i> Cetak Browser</button>
  <a href="{{ route('sales.index') }}" class="btn btn-outline-secondary">Riwayat</a>
  <form method="POST" action="{{ route('sales.closing.wa', ['date' => $date, 'kasir_id' => $kasirId]) }}" class="d-inline" onsubmit="return confirm('Kirim rekap {{ $date }} ke WA owner?')">
    @csrf
    <button class="btn btn-success"><i class="ti ti-brand-whatsapp me-1"></i> Kirim Rekap ke WA Owner</button>
  </form>
  <form method="POST" action="{{ route('sales.closing.digest', ['date' => $date, 'kasir_id' => $kasirId]) }}" class="d-inline" onsubmit="return confirm('Kirim DIGEST {{ $date }} (omzet + service) ke WA owner?')">
    @csrf
    <button class="btn btn-info"><i class="ti ti-chart-bar me-1"></i> Kirim Digest Sekarang</button>
  </form></div>
</div></div>

@if(session('success'))<div class="alert alert-success no-print mt-2">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger no-print mt-2">{{ session('error') }}</div>@endif

<div class="card my-3 no-print"><div class="card-body"><form method="GET" class="row g-2">
  <div class="col-md-3"><label class="form-label">Tanggal</label><input type="date" name="date" value="{{ $date }}" class="form-control"></div>
  <div class="col-md-4"><label class="form-label">Kasir</label><select name="kasir_id" class="form-select">
    <option value="">Semua kasir</option>
    @foreach($cashiers as $c)<option value="{{ $c->id }}" {{ (string) $kasirId === (string) $c->id ? 'selected' : '' }}>{{ $c->name }}</option>@endforeach
  </select></div>
  <div class="col-md-2 d-flex align-items-end"><button class="btn btn-secondary w-100">Tampilkan</button></div>
</form></div></div>

<div class="card"><div class="card-header"><h3 class="card-title">Tutup Kasir — {{ $date }} @if($kasirId) • Kasir: {{ $cashiers->firstWhere('id', (int) $kasirId)->name ?? $kasirId }} @else • Semua kasir @endif @if($showAll)<span class="badge bg-purple-lt ms-1">Semua toko</span>@endif</h3></div>
<div class="card-body">
  <div class="row g-3">
    <div class="col-md-4"><div class="border rounded p-3"><div class="text-secondary">Omzet ({{ $trxCount }} transaksi)</div><div class="h1 mb-0">Rp {{ number_format($omzet,0,',','.') }}</div></div></div>
    <div class="col-md-4"><div class="border rounded p-3"><div class="text-secondary">Total diskon</div><div class="h1 mb-0">Rp {{ number_format($diskon,0,',','.') }}</div></div></div>
    <div class="col-md-4"><div class="border rounded p-3"><div class="text-secondary">Retur</div><div class="h1 mb-0">Rp {{ number_format($retur,0,',','.') }}</div></div></div>
    <div class="col-md-6"><div class="border rounded p-3"><div class="text-secondary">Kas masuk (semua kategori)</div><div class="h1 mb-0 text-success">Rp {{ number_format($kasMasuk,0,',','.') }}</div></div></div>
    <div class="col-md-6"><div class="border rounded p-3"><div class="text-secondary">Kas keluar</div><div class="h1 mb-0 text-danger">Rp {{ number_format($kasKeluar,0,',','.') }}</div></div></div>
  </div>
  <h3 class="card-title mt-4">Per Metode Bayar (pembayaran diterima)</h3>
  <div class="table-responsive"><table class="table table-sm mb-0">
    <thead><tr><th>Metode</th><th class="text-end">Nominal</th></tr></thead>
    <tbody>@forelse($perMetode as $m => $t)<tr><td><span class="badge bg-blue-lt">{{ strtoupper($m) }}</span></td><td class="text-end">Rp {{ number_format($t,0,',','.') }}</td></tr>
    @empty<tr><td colspan="2" class="text-center text-secondary">Belum ada pembayaran hari ini.</td></tr>@endforelse</tbody>
    <tfoot><tr><th>Total diterima</th><th class="text-end">Rp {{ number_format(collect($perMetode)->sum(),0,',','.') }}</th></tr></tfoot>
  </table></div>
  <div class="text-secondary small mt-3">Dicetak: {{ now()->format('d/m/Y H:i') }} • Selisih laci = fisik − total diterima di atas.</div>
</div></div>

<div class="card mt-3">
  <div class="card-header">
    <h3 class="card-title">Rekap Semua Kasir — {{ $date }}</h3>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-striped mb-0">
      <thead>
        <tr>
          <th>Kasir</th>
          <th class="text-end">Trx</th>
          <th
            class="text-end"
          >Omzet</th>
          <th
            class="text-end"
          >Profit</th>
          <th
            class="text-end"
          >Diskon</th>
          <th class="text-end">Rasio Diskon</th>
          <th class="text-end">Retur</th>
        </tr>
      </thead>
      <tbody>
        @forelse($perKasir ?? [] as $row)
          @php
            $rasio = $row['rasio'] ?? ($row['omzet'] > 0 ? round($row['diskon'] / $row['omzet'] * 100, 1) : 0);
          @endphp
          <tr
            @if((string) $kasirId === (string) $row['user_id'])
              class="table-active"
            @endif
          >
            <td>
              {{ $row['name'] }}
            </td>
            <td class="text-end">
              {{ $row['trx'] }}
            </td>
            <td class="text-end">
              Rp {{ number_format($row['omzet'], 0, ',', '.') }}
            </td>
            <td
              class="text-end text-success fw-bold"
            >
              Rp {{ number_format($row['profit'] ?? 0, 0, ',', '.') }}
            </td>
            <td
              class="text-end"
            >
              Rp {{ number_format($row['diskon'], 0, ',', '.') }}
            </td>
            <td class="text-end">
              {{ number_format($rasio, 1, ',', '.') }}%
              @if($rasio > 25)
                <span
                  class="badge bg-danger-lt ms-1"
                  title="Rasio diskon > 25% — periksa transaksi kasir ini"
                >AWAS</span>
              @elseif($rasio > 15)
                <span
                  class="badge bg-warning-lt ms-1"
                  title="Rasio diskon > 15% — pantau transaksi kasir ini"
                >PANTAU</span>
              @endif
            </td>
            <td class="text-end">
              Rp {{ number_format($row['retur'], 0, ',', '.') }}
            </td>
          </tr>
        @empty
          <tr>
            <td
              colspan="7"
              class="text-center text-secondary"
            >
              Belum ada transaksi kasir pada tanggal ini.
            </td>
          </tr>
        @endforelse
      </tbody>
      @if(!empty($perKasir) && count($perKasir))
        <tfoot>
          <tr>
            <th>
              Total ({{ count($perKasir) }} kasir)
            </th>
            <th class="text-end">
              {{ collect($perKasir)->sum('trx') }}
            </th>
            <th
              class="text-end"
            >
              Rp {{ number_format(collect($perKasir)->sum('omzet'), 0, ',', '.') }}
            </th>
            <th
              class="text-end text-success"
            >
              Rp {{ number_format(collect($perKasir)->sum('profit'), 0, ',', '.') }}
            </th>
            <th class="text-end">
              Rp {{ number_format(collect($perKasir)->sum('diskon'), 0, ',', '.') }}
            </th>
            <th class="text-end">
              —
            </th>
            <th class="text-end">
              Rp {{ number_format(collect($perKasir)->sum('retur'), 0, ',', '.') }}
            </th>
          </tr>
        </tfoot>
      @endif
    </table>
  </div>
  <div class="card-footer text-secondary small">
    Ambang audit diskon: rasio = diskon ÷ omzet × 100%.
    <span class="badge bg-warning-lt">PANTAU</span> bila &gt; 15%,
    <span class="badge bg-danger-lt">AWAS</span> bila &gt; 25%.
    Omzet Rp 0 → rasio 0%.
  </div>
</div>
@endsection
