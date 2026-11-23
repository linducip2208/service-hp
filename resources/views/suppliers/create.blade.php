@extends('layouts.tabler')
@section('title','Tambah Supplier')
@section('content')
<div class="card col-lg-6 mx-auto"><div class="card-header"><h3 class="card-title">Supplier Baru</h3></div>
<div class="card-body"><form method="POST" action="{{ route('suppliers.store') }}">@csrf
<div class="mb-2"><label class="form-label">Toko</label><select name="store_id" class="form-select">@foreach($stores as $s)<option value="{{ $s->id }}" {{ (int)session('active_store_id')===$s->id?'selected':'' }}>{{ $s->name }}</option>@endforeach</select></div>
<div class="row"><div class="col-4 mb-2"><label class="form-label">Kode</label><input name="code" class="form-control" required></div>
<div class="col-8 mb-2"><label class="form-label">Nama</label><input name="name" class="form-control" required></div></div>
<div class="mb-2"><label class="form-label">Telepon</label><input name="phone" class="form-control"></div>
<div class="mb-2"><label class="form-label">Alamat</label><input name="address" class="form-control"></div>
<button class="btn btn-primary">Simpan</button></form></div></div>
@endsection
