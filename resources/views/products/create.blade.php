@extends('layouts.tabler')
@section('title', 'Tambah Produk')
@section('content')
<div class="page-header"><div class="row align-items-center"><div class="col"><h2 class="page-title">Tambah Produk</h2><div class="text-secondary">SKU dibuat otomatis saat disimpan.</div></div>
<div class="col-auto"><a href="{{ route('products.index') }}" class="btn btn-outline-secondary">Kembali</a></div></div></div>
<div class="card"><div class="card-body"><form method="POST" action="{{ route('products.store') }}" enctype="multipart/form-data">@csrf
<div class="row g-3">
<div class="col-md-6"><label class="form-label">Nama produk *</label><input name="name" value="{{ old('name') }}" class="form-control" required></div>
<div class="col-md-3"><label class="form-label">Merek</label><select name="brand_id" class="form-select"><option value="">—</option>@foreach($brands as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select></div>
<div class="col-md-3"><label class="form-label">Kategori</label><select name="category_id" class="form-select"><option value="">—</option>@foreach($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></div>
<div class="col-md-3"><label class="form-label">Tipe *</label><select name="type" class="form-select">@foreach(['hp_baru','hp_bekas','refurbished','aksesoris','sparepart','pulsa','paket_data','lain'] as $t)<option value="{{ $t }}">{{ $t }}</option>@endforeach</select></div>
<div class="col-md-3"><label class="form-label">RAM</label><input name="ram" class="form-control" placeholder="8GB"></div>
<div class="col-md-3"><label class="form-label">Storage</label><input name="storage" class="form-control" placeholder="128GB"></div>
<div class="col-md-3"><label class="form-label">Warna</label><input name="warna" class="form-control"></div>
<div class="col-md-3"><label class="form-label">Harga beli *</label><input name="purchase_price" type="number" min="0" value="0" class="form-control" required></div>
<div class="col-md-3"><label class="form-label">Harga jual *</label><input name="selling_price" type="number" min="0" value="0" class="form-control" required></div>
<div class="col-md-3"><label class="form-label">Min. stok</label><input name="min_stock" type="number" min="0" value="5" class="form-control"></div>
<div class="col-md-3"><label class="form-label">Stok awal (toko aktif)</label><input name="initial_stock" type="number" min="0" value="0" class="form-control"></div>
<div class="col-md-3"><label class="form-label">Barcode</label><input name="barcode" class="form-control" placeholder="otomatis = SKU"></div>
<div class="col-md-3"><label class="form-label">Foto</label><input name="photo" type="file" accept="image/*" class="form-control"></div>
<div class="col-md-3"><label class="form-check mt-4"><input type="checkbox" name="is_serialized" value="1" class="form-check-input"> Produk serialized (IMEI)</label>
<label class="form-check"><input type="checkbox" name="is_active" value="1" class="form-check-input" checked> Aktif dijual</label></div>
</div>
<button class="btn btn-primary mt-3">Simpan Produk</button></form></div></div>
@endsection
