@extends('layouts.tabler')
@section('title','Detail Toko')
@section('content')
<a href="{{ route('stores.index') }}" class="btn btn-outline-secondary btn-sm mb-3">← Kembali</a>
<div class="row row-cards"><div class="col-lg-6"><div class="card"><div class="card-header"><h3 class="card-title">{{ $store->name }}</h3></div>
<div class="card-body"><p class="mb-1">Kode: <b>{{ $store->code }}</b> | Telp: {{ $store->phone ?? '-' }}</p><p class="text-secondary">{{ $store->address }}</p>
@php
    $mapsUrl = $store->address ? 'https://www.google.com/maps/search/?api=1&query=' . urlencode($store->name . ' ' . $store->address) : null;
    $waUrl = $mapsUrl ? 'https://wa.me/?text=' . urlencode('Lokasi ' . $store->name . ': ' . $store->address . ' ' . $mapsUrl) : null;
@endphp
@if($mapsUrl)
<p class="mt-2">
    <span class="text-secondary small d-block mb-1">Bagikan Lokasi:</span>
    <a href="{{ $mapsUrl }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary">Buka Maps</a>
    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="navigator.clipboard && navigator.clipboard.writeText('{{ $mapsUrl }}')">Salin Link</button>
    <a href="{{ $waUrl }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-success">Bagikan via WA</a>
</p>
@endif
<p>Akun kas: @foreach($store->cashAccounts as $a)<span class="badge bg-blue-lt">{{ $a->name }}: Rp {{ number_format($a->balance,0,',','.') }}</span> @endforeach</p>
<a href="{{ route('stores.edit',$store) }}" class="btn btn-sm btn-secondary">Edit + Assign User</a></div></div></div>
<div class="col-lg-6"><div class="card"><div class="card-header"><h3 class="card-title">User di Toko Ini</h3></div>
<div class="table-responsive"><table class="table card-table"><thead><tr><th>Nama</th><th>Role</th></tr></thead><tbody>
@foreach($store->users as $u)<tr><td>{{ $u->name }}</td><td>{{ $u->role }}</td></tr>@endforeach</tbody></table></div></div></div></div>
@endsection
