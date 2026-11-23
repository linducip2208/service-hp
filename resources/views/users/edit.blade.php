@extends('layouts.tabler')
@section('title','Edit Pengguna')
@section('content')
<div class="card col-lg-7 mx-auto"><div class="card-header"><h3 class="card-title">Edit {{ $user->name }}</h3></div>
<div class="card-body"><form method="POST" action="{{ route('users.update',$user) }}">@csrf @method('PUT')
<div class="row"><div class="col-6 mb-2"><label class="form-label">Nama</label><input name="name" value="{{ $user->name }}" class="form-control" required></div>
<div class="col-6 mb-2"><label class="form-label">Email</label><input type="email" name="email" value="{{ $user->email }}" class="form-control" required></div></div>
<div class="row"><div class="col-6 mb-2"><label class="form-label">Password baru (kosongkan = tidak reset)</label><input type="password" name="password" class="form-control" minlength="6"></div>
<div class="col-6 mb-2"><label class="form-label">Role</label><select name="role" class="form-select">@foreach(['superadmin','owner','admin','kasir','teknisi','gudang'] as $r)<option value="{{ $r }}" {{ $user->role==$r?'selected':'' }}>{{ $r }}</option>@endforeach</select></div></div>
<div class="row"><div class="col-4 mb-2"><label class="form-label">HP</label><input name="phone" value="{{ $user->phone }}" class="form-control"></div>
<div class="col-4 mb-2"><label class="form-label">Komisi (%)</label><input type="number" step="0.01" name="commission_rate" value="{{ $user->commission_rate }}" class="form-control"></div>
<div class="col-4 mb-2"><label class="form-label">Status</label><select name="is_active" class="form-select"><option value="1" {{ $user->is_active?'selected':'' }}>Aktif</option><option value="0" {{ !$user->is_active?'selected':'' }}>Nonaktif</option></select></div></div>
<div class="mb-2"><label class="form-label">Assign Toko</label>
<div class="row">@foreach($stores as $s)<div class="col-md-6"><label class="form-check"><input type="checkbox" name="store_ids[]" value="{{ $s->id }}" class="form-check-input" {{ $user->stores->contains($s->id)?'checked':'' }}><span class="form-check-label">{{ $s->name }}</span></label></div>@endforeach</div></div>
<div class="mb-2"><label class="form-label">Toko default</label><select name="default_store_id" class="form-select"><option value="">—</option>@foreach($stores as $s)<option value="{{ $s->id }}" {{ (int)$user->active_store_id===$s->id?'selected':'' }}>{{ $s->name }}</option>@endforeach</select></div>
<button class="btn btn-primary">Simpan</button></form>
<hr><form method="POST" action="{{ route('users.destroy',$user) }}" onsubmit="return confirm('Nonaktifkan user ini?')">@csrf @method('DELETE')<button class="btn btn-outline-danger btn-sm">Nonaktifkan</button></form></div></div>
@endsection
