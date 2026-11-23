@extends('layouts.tabler')
@section('title', 'Edit '.$product->name)
@section('content')
<div class="page-header"><div class="row align-items-center"><div class="col"><h2 class="page-title">Edit — {{ $product->name }}</h2></div>
<div class="col-auto"><a href="{{ route('products.show',$product) }}" class="btn btn-outline-secondary">Kembali</a></div></div></div>
<div class="card"><div class="card-body"><form method="POST" action="{{ route('products.update',$product) }}" enctype="multipart/form-data">@csrf @method('PUT')
<div class="row g-3">
<div class="col-md-6"><label class="form-label">Nama produk *</label><input name="name" value="{{ old('name',$product->name) }}" class="form-control" required></div>
<div class="col-md-3"><label class="form-label">Merek</label><select name="brand_id" class="form-select"><option value="">—</option>@foreach($brands as $b)<option value="{{ $b->id }}" {{ $product->brand_id==$b->id?'selected':'' }}>{{ $b->name }}</option>@endforeach</select></div>
<div class="col-md-3"><label class="form-label">Kategori</label><select name="category_id" class="form-select"><option value="">—</option>@foreach($categories as $c)<option value="{{ $c->id }}" {{ $product->category_id==$c->id?'selected':'' }}>{{ $c->name }}</option>@endforeach</select></div>
<div class="col-md-3"><label class="form-label">Tipe *</label><select name="type" class="form-select">@foreach(['hp_baru','hp_bekas','refurbished','aksesoris','sparepart','pulsa','paket_data','lain'] as $t)<option value="{{ $t }}" {{ $product->type===$t?'selected':'' }}>{{ $t }}</option>@endforeach</select></div>
<div class="col-md-3"><label class="form-label">RAM</label><input name="ram" value="{{ $product->ram }}" class="form-control"></div>
<div class="col-md-3"><label class="form-label">Storage</label><input name="storage" value="{{ $product->storage }}" class="form-control"></div>
<div class="col-md-3"><label class="form-label">Warna</label><input name="warna" value="{{ $product->warna }}" class="form-control"></div>
<div class="col-md-3"><label class="form-label">Harga beli *</label><input name="purchase_price" type="number" min="0" value="{{ $product->purchase_price }}" class="form-control" required></div>
<div class="col-md-3"><label class="form-label">Harga jual *</label><input name="selling_price" type="number" min="0" value="{{ $product->selling_price }}" class="form-control" required></div>
<div class="col-md-3"><label class="form-label">Min. stok</label><input name="min_stock" type="number" min="0" value="{{ $product->min_stock }}" class="form-control"></div>
<div class="col-md-3"><label class="form-label">Barcode</label><input name="barcode" value="{{ $product->barcode }}" class="form-control"></div>
<div class="col-md-3"><label class="form-label">Foto baru</label><input name="photo" type="file" accept="image/*" class="form-control"></div>
<div class="col-md-3"><label class="form-check mt-4"><input type="checkbox" name="is_serialized" value="1" class="form-check-input" {{ $product->is_serialized?'checked':'' }}> Serialized (IMEI)</label>
<label class="form-check"><input type="checkbox" name="is_active" value="1" class="form-check-input" {{ $product->is_active?'checked':'' }}> Aktif</label></div>
</div>
<button class="btn btn-primary mt-3">Simpan Perubahan</button></form></div></div>
@endsection
