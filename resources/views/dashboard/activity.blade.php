@extends('layouts.tabler')
@section('title','Linimasa Aktivitas Toko')
@section('content')
<div class="page-header d-print-none">
  <div class="row align-items-center">
    <div class="col"><h2 class="page-title"><i class="ti ti-history me-1"></i> Linimasa Aktivitas</h2><div class="text-secondary">30 kejadian terbaru toko aktif: penjualan, perubahan status service, mutasi besar/opname, kas keluar besar (≥ Rp 500rb).</div></div>
    <div class="col-auto ms-auto">
      <a href="{{ route('dashboard') }}" class="btn btn-outline-secondary">← Dashboard</a>
    </div>
  </div>
</div>
<div class="card mt-2">
  <div class="card-header">
    <div class="btn-group flex-wrap" role="group" aria-label="Filter kejadian">
      <a href="{{ route('activity') }}" class="btn btn-sm {{ empty($type) ? 'btn-primary' : 'btn-outline-primary' }}">Semua</a>
      <a href="{{ route('activity', ['type' => 'sale']) }}" class="btn btn-sm {{ ($type ?? '')==='sale' ? 'btn-primary' : 'btn-outline-primary' }}"><i class="ti ti-shopping-cart me-1"></i> Penjualan</a>
      <a href="{{ route('activity', ['type' => 'service']) }}" class="btn btn-sm {{ ($type ?? '')==='service' ? 'btn-primary' : 'btn-outline-primary' }}"><i class="ti ti-tool me-1"></i> Service</a>
      <a href="{{ route('activity', ['type' => 'stock']) }}" class="btn btn-sm {{ ($type ?? '')==='stock' ? 'btn-primary' : 'btn-outline-primary' }}"><i class="ti ti-package me-1"></i> Stok</a>
      <a href="{{ route('activity', ['type' => 'cash']) }}" class="btn btn-sm {{ ($type ?? '')==='cash' ? 'btn-primary' : 'btn-outline-primary' }}"><i class="ti ti-cash me-1"></i> Kas Keluar</a>
    </div>
  </div>
  <div class="list-group list-group-flush">
    @forelse($events as $e)
    <a href="{{ $e['url'] }}" class="list-group-item list-group-item-action">
      <div class="row align-items-center">
        <div class="col-auto"><span class="avatar bg-{{ $e['color'] }}-lt">{{ $e['icon'] }}</span></div>
        <div class="col">
          <div class="fw-bold">{{ $e['title'] }}</div>
          <div class="text-secondary small">{{ $e['desc'] }}</div>
          <div class="small text-secondary mt-1"><i class="ti ti-user me-1"></i> {{ $e['user'] }} •
            @if($e['kind']==='sale')<span class="badge bg-green-lt">penjualan</span>
            @elseif($e['kind']==='service')<span class="badge bg-blue-lt">service</span>
            @elseif($e['kind']==='stock')<span class="badge bg-yellow-lt">stok</span>
            @else<span class="badge bg-red-lt">kas</span>@endif
          </div>
        </div>
        <div class="col-auto text-secondary small text-end">
          {{ $e['time']?->format('d/m/Y H:i') }}<div class="text-primary">Lihat →</div>
        </div>
      </div>
    </a>
    @empty
    <div class="list-group-item text-center text-secondary py-4">Belum ada aktivitas{{ !empty($type) ? ' untuk filter ini' : '' }} di toko aktif.</div>
    @endforelse
  </div>
</div>
@endsection
