@extends('layouts.tabler')
@section('title','Edit Toko')
@section('content')
<div class="card col-lg-8 mx-auto"><div class="card-header"><h3 class="card-title">Edit {{ $store->name }}</h3></div>
<div class="card-body"><form method="POST" action="{{ route('stores.update',$store) }}">@csrf @method('PUT')
<div class="row"><div class="col-md-4 mb-2"><label class="form-label">Kode</label><input name="code" value="{{ $store->code }}" class="form-control" required></div>
<div class="col-md-8 mb-2"><label class="form-label">Nama</label><input name="name" value="{{ $store->name }}" class="form-control" required></div></div>
<div class="mb-2"><label class="form-label">Alamat</label><input name="address" value="{{ $store->address }}" class="form-control"></div>
<div class="row"><div class="col-md-4 mb-2"><label class="form-label">Telepon</label><input name="phone" value="{{ $store->phone }}" class="form-control"></div>
<div class="col-md-4 mb-2"><label class="form-label">Pusat?</label><select name="is_center" class="form-select"><option value="0">Bukan</option><option value="1" {{ $store->is_center?'selected':'' }}>Ya</option></select></div>
<div class="col-md-4 mb-2"><label class="form-label">Status</label><select name="is_active" class="form-select"><option value="1" {{ $store->is_active?'selected':'' }}>Aktif</option><option value="0" {{ !$store->is_active?'selected':'' }}>Nonaktif</option></select></div></div>
<div class="mb-2"><label class="form-label">Assign User (centang = punya akses)</label>
<div class="row">@foreach($users as $u)<div class="col-md-4"><label class="form-check"><input type="checkbox" name="user_ids[]" value="{{ $u->id }}" class="form-check-input" {{ $store->users->contains($u->id)?'checked':'' }}><span class="form-check-label">{{ $u->name }} <small class="text-secondary">({{ $u->role }})</small></span></label></div>@endforeach</div></div>
<button class="btn btn-primary">Simpan</button> <a href="{{ route('stores.index') }}" class="btn btn-link">Batal</a>
</form>
<hr><form method="POST" action="{{ route('stores.destroy',$store) }}" onsubmit="return confirm('Nonaktifkan toko ini?')">@csrf @method('DELETE')<button class="btn btn-outline-danger btn-sm">Nonaktifkan Toko</button></form>
</div></div>
@endsection
