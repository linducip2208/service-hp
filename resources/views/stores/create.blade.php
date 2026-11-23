@extends('layouts.tabler')
@section('title','Tambah Toko')
@section('content')
<div class="card col-lg-8 mx-auto"><div class="card-header"><h3 class="card-title">Toko Baru</h3></div>
<div class="card-body"><form method="POST" action="{{ route('stores.store') }}">@csrf
<div class="row"><div class="col-md-4 mb-2"><label class="form-label">Kode</label><input name="code" class="form-control" required></div>
<div class="col-md-8 mb-2"><label class="form-label">Nama</label><input name="name" class="form-control" required></div></div>
<div class="mb-2"><label class="form-label">Alamat</label><input name="address" class="form-control"></div>
<div class="row"><div class="col-md-6 mb-2"><label class="form-label">Telepon</label><input name="phone" class="form-control"></div>
<div class="col-md-6 mb-2"><label class="form-label">Pusat?</label><select name="is_center" class="form-select"><option value="0">Bukan</option><option value="1">Ya, toko pusat</option></select></div></div>
<div class="mb-2"><label class="form-label">Assign User ke toko ini</label>
<div class="row">@foreach($users as $u)<div class="col-md-4"><label class="form-check"><input type="checkbox" name="user_ids[]" value="{{ $u->id }}" class="form-check-input"><span class="form-check-label">{{ $u->name }} <small class="text-secondary">({{ $u->role }})</small></span></label></div>@endforeach</div></div>
<button class="btn btn-primary">Simpan</button> <a href="{{ route('stores.index') }}" class="btn btn-link">Batal</a>
</form></div></div>
@endsection
