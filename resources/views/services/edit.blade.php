@extends('layouts.tabler')
@section('title','Edit Tiket '.$service->ticket_no)
@section('content')
<div class="page-header"><div class="row align-items-center"><div class="col"><h2 class="page-title">Edit {{ $service->ticket_no }}</h2></div><div class="col-auto ms-auto"><a href="{{ route('services.show',$service) }}" class="btn btn-ghost-secondary">Kembali</a></div></div></div>
<form method="POST" action="{{ route('services.update',$service) }}" enctype="multipart/form-data" class="card mt-2"><div class="card-body"><div class="row g-3">
@csrf @method('PUT')
<div class="col-md-6"><label class="form-label">Pelanggan</label><select name="customer_id" class="form-select">@foreach($customers as $c)<option value="{{ $c->id }}" {{ $service->customer_id==$c->id?'selected':'' }}>{{ $c->name }} — {{ $c->phone }}</option>@endforeach</select></div>
<div class="col-md-6"><label class="form-label">Teknisi</label><select name="technician_id" class="form-select"><option value="">--</option>@foreach($technicians as $t)<option value="{{ $t->id }}" {{ $service->technician_id==$t->id?'selected':'' }}>{{ $t->name }}</option>@endforeach</select></div>
<div class="col-md-3"><label class="form-label">Brand</label><input name="product_brand" class="form-control" value="{{ $service->product_brand }}"></div>
<div class="col-md-3"><label class="form-label">Tipe</label><input name="product_type" class="form-control" value="{{ $service->product_type }}"></div>
<div class="col-md-3"><label class="form-label">IMEI</label><input name="imei" class="form-control" value="{{ $service->imei }}"></div>
<div class="col-md-3"><label class="form-label">Prioritas</label><select name="priority" class="form-select">@foreach(['rendah','normal','mendesak'] as $p)<option {{ $service->priority==$p?'selected':'' }}>{{ $p }}</option>@endforeach</select></div>
<div class="col-md-6"><label class="form-label">Keluhan</label><textarea name="complaint" class="form-control" rows="2">{{ $service->complaint }}</textarea></div>
<div class="col-md-6"><label class="form-label">Kondisi</label><textarea name="condition_note" class="form-control" rows="2">{{ $service->condition_note }}</textarea></div>
<div class="col-md-6"><label class="form-label">Diagnosa</label><textarea name="diagnosis" class="form-control" rows="2">{{ $service->diagnosis }}</textarea></div>
<div class="col-md-3"><label class="form-label">Estimasi / Jasa (Rp)</label><input type="number" name="estimate_cost" class="form-control" value="{{ $service->estimate_cost }}" min="0"></div>
<div class="col-md-3"><label class="form-label">Garansi (hari)</label><input type="number" name="warranty_days" class="form-control" value="{{ $service->warranty_days }}" min="0"></div>
<div class="col-md-3"><label class="form-label">Estimasi Selesai</label><input type="datetime-local" name="estimated_done_at" class="form-control" value="{{ old('estimated_done_at', $service->estimated_done_at?->format('Y-m-d\TH:i')) }}"></div>
<div class="col-md-6"><label class="form-label">Tambah Foto (max total 4)</label><input type="file" name="photos[]" class="form-control" multiple accept="image/*"></div>
</div></div><div class="card-footer text-end"><button class="btn btn-primary">Simpan</button></div></form>
@endsection
