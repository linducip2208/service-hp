@extends('layouts.tabler')
@section('title','Tambah Pengguna')
@section('content')
<div class="card col-lg-7 mx-auto"><div class="card-header"><h3 class="card-title">Pengguna Baru</h3></div>
<div class="card-body"><form method="POST" action="{{ route('users.store') }}">@csrf
<div class="row"><div class="col-6 mb-2"><label class="form-label">Nama</label><input name="name" class="form-control" required></div>
<div class="col-6 mb-2"><label class="form-label">Email</label><input type="email" name="email" class="form-control" required></div></div>
<div class="row"><div class="col-6 mb-2"><label class="form-label">Password</label><input type="password" name="password" class="form-control" minlength="6" required></div>
<div class="col-6 mb-2"><label class="form-label">Role</label><select name="role" class="form-select">@foreach(['superadmin','owner','admin','kasir','teknisi','gudang'] as $r)<option value="{{ $r }}">{{ $r }}</option>@endforeach</select></div></div>
<div class="row"><div class="col-6 mb-2"><label class="form-label">HP</label><input name="phone" class="form-control"></div>
<div class="col-6 mb-2"><label class="form-label">Komisi teknisi (%)</label><input type="number" step="0.01" name="commission_rate" value="0" class="form-control"></div></div>
<div class="mb-2"><label class="form-label">Assign Toko (centang)</label>
<div class="row">@foreach($stores as $s)<div class="col-md-6"><label class="form-check"><input type="checkbox" name="store_ids[]" value="{{ $s->id }}" class="form-check-input"><span class="form-check-label">{{ $s->name }}</span></label></div>@endforeach</div></div>
<div class="mb-2"><label class="form-label">Toko default</label><select name="default_store_id" class="form-select"><option value="">—</option>@foreach($stores as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select></div>
<button class="btn btn-primary">Simpan</button></form></div></div>
@endsection
