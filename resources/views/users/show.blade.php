@extends('layouts.tabler')
@section('title','Detail Pengguna')
@section('content')
<a href="{{ route('users.index') }}" class="btn btn-outline-secondary btn-sm mb-3">← Kembali</a>
<div class="card col-lg-6"><div class="card-header"><h3 class="card-title">{{ $user->name }}</h3></div>
<div class="card-body"><p class="mb-1">{{ $user->email }} | {{ $user->phone ?? '-' }}</p>
<p class="mb-1">Role: <span class="badge bg-blue-lt">{{ $user->role }}</span> | Status: <span class="badge bg-{{ $user->is_active?'green':'red' }}-lt">{{ $user->is_active?'Aktif':'Nonaktif' }}</span></p>
<p>Toko: {{ $user->stores->pluck('name')->join(', ') ?: '-' }} | Default: {{ $user->activeStore->name ?? '-' }}</p>
<a href="{{ route('users.edit',$user) }}" class="btn btn-sm btn-secondary">Edit / Reset Password / Assign Toko</a></div></div>
@endsection
