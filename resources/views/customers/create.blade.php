@extends('layouts.tabler')
@section('title','Tambah Pelanggan')
@section('content')
<div class="card col-lg-6 mx-auto"><div class="card-header"><h3 class="card-title">Pelanggan Baru</h3></div>
<div class="card-body"><form method="POST" action="{{ route('customers.store') }}" data-loading>@csrf
<div class="mb-2"><label class="form-label">Toko</label><select name="store_id" class="form-select">@foreach($stores as $s)<option value="{{ $s->id }}" {{ (string)old('store_id', session('active_store_id'))===(string)$s->id?'selected':'' }}>{{ $s->name }}</option>@endforeach</select></div>
<div class="mb-2"><label class="form-label">Nama</label><input name="name" value="{{ old('name') }}" class="form-control" required></div>
<div class="row"><div class="col-6 mb-2"><label class="form-label">HP</label><input name="phone" value="{{ old('phone') }}" class="form-control"></div>
<div class="col-6 mb-2"><label class="form-label">Kode Member</label><input name="member_code" value="{{ old('member_code') }}" class="form-control"></div></div>
<div class="mb-2"><label class="form-label">Alamat</label><input name="address" value="{{ old('address') }}" class="form-control"></div>
<button class="btn btn-primary">Simpan</button></form></div></div>
@endsection
